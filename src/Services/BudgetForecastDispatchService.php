<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Closure;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastErrorCode;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastScope;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastAccountSetting;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Constants\HouseholdBudgetPermission;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Control\Email\Email;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Throwable;
use InvalidArgumentException;

/**
 * Selects opted-in variants, atomically claims them, and dispatches only on the local month end.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class BudgetForecastDispatchService
{
    private Closure $clock;

    /**
     * Wires forecast services and permits deterministic clock injection in tests.
     */
    public function __construct(
        private readonly BudgetForecastDeliveryRepository $deliveries,
        private readonly BudgetForecastService $forecasts,
        private readonly BudgetForecastEmailService $emails,
        private readonly BudgetReportService $reports,
        private readonly LoggerInterface $logger,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? fn (): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone(date_default_timezone_get())
        );
    }

    /**
     * Processes every active variant independently and reports aggregate outcomes.
     *
     * @return array{due: bool, processed: int, skipped: int, failed: int, target_month: ?string}
     */
    public function run(): array
    {
        $now = $this->now();
        if (!$this->isMonthEnd($now)) {
            return $this->summary(false, 0, 0, 0, null);
        }
        $targetMonth = $this->targetMonth($now);
        $processed = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($this->enabledBudgets() as $budget) {
            [$ownerProcessed, $ownerSkipped, $ownerFailed] = $this->dispatchBudget($budget, $targetMonth);
            $processed += $ownerProcessed;
            $skipped += $ownerSkipped;
            $failed += $ownerFailed;
        }
        return $this->summary(true, $processed, $skipped, $failed, $targetMonth);
    }

    /**
     * Reads the current local time from the injected clock.
     */
    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('The forecast clock must return a DateTimeImmutable.');
        }
        return $now;
    }

    /**
     * Selects only enabled personal household workspaces.
     *
     * @return list<HouseholdBudget>
     */
    private function enabledBudgets(): array
    {
        return HouseholdBudget::get()->filter(BudgetForecastField::FORECAST_EMAILS_ENABLED, 1)
            ->sort('ID ASC')->toArray();
    }

    /**
     * Builds account and overall variants without coupling them to the technical task member.
     *
     * @return array{int, int, int}
     */
    private function dispatchBudget(HouseholdBudget $budget, string $targetMonth): array
    {
        $owner = $budget->Owner();
        if (!$owner->exists()) {
            $this->logFailure($budget, 0, $targetMonth, BudgetForecastScope::OVERALL, BudgetForecastErrorCode::RECIPIENT);
            return [0, 0, 1];
        }
        $variants = $this->variants($budget, $owner);
        $processed = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($variants as $variant) {
            try {
                [$sent, $wasSkipped, $hadFailure] = $this->dispatchVariant($budget, $owner, $variant, $targetMonth);
                $processed += $sent;
                $skipped += $wasSkipped;
                $failed += $hadFailure;
            } catch (Throwable) {
                $this->logFailure($budget, 0, $targetMonth, $variant['scope'], BudgetForecastErrorCode::STATE_UPDATE);
                $failed++;
            }
        }
        return [$processed, $skipped, $failed];
    }

    /**
     * Creates independent account choices and the additional all-account choice.
     *
     * @return list<array{scope: string, account_id: int, account_ids: list<int>, label: string}>
     */
    private function variants(HouseholdBudget $budget, Member $owner): array
    {
        $ownAccounts = $this->ownAccounts($owner);
        $ids = array_map(fn (BankAccount $account): int => (int)$account->ID, $ownAccounts);
        $labels = [];
        foreach ($ownAccounts as $account) {
            $labels[(int)$account->ID] = (string)$account->Summary;
        }
        $variants = $this->accountVariants($budget, $labels);
        if ($budget->CombinedForecastEmailEnabled) {
            $variants[] = [
                'scope' => BudgetForecastScope::OVERALL,
                'account_id' => 0,
                'account_ids' => $ids,
                'label' => '',
            ];
        }
        return $variants;
    }

    /**
     * Creates individual variants only for explicitly enabled account settings.
     *
     * @param array<int, string> $labels
     * @return list<array{scope: string, account_id: int, account_ids: list<int>, label: string}>
     */
    private function accountVariants(HouseholdBudget $budget, array $labels): array
    {
        $settings = BudgetForecastAccountSetting::get()->filter([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ENABLED => 1,
        ])->toArray();
        $variants = [];
        foreach ($settings as $setting) {
            $accountID = (int)$setting->AccountID;
            $variants[] = [
                'scope' => BudgetForecastScope::ACCOUNT,
                'account_id' => $accountID,
                'account_ids' => [$accountID],
                'label' => $labels[$accountID] ?? '',
            ];
        }
        return $variants;
    }

    /**
     * Loads linked accounts from the owner's inverse Contact relationship.
     *
     * @return list<BankAccount>
     */
    private function ownAccounts(Member $owner): array
    {
        return $this->reports->accountsFor($owner)->toArray();
    }

    /**
     * Claims, prepares and sends one variant with conservative transport state handling.
     *
     * @param array{scope: string, account_id: int, account_ids: list<int>, label: string} $variant
     * @return array{int, int, int}
     */
    private function dispatchVariant(
        HouseholdBudget $budget,
        Member $owner,
        array $variant,
        string $targetMonth
    ): array {
        $now = $this->now();
        if (!$this->isMonthEnd($now) || $this->targetMonth($now) !== $targetMonth) {
            return [0, 1, 0];
        }
        $claim = $this->deliveries->claim(
            $budget,
            $targetMonth,
            $variant['scope'],
            $variant['account_id'],
            $now,
            $this->claimLeaseMinutes()
        );
        if ($claim === null) {
            $uncertainID = $this->deliveries->uncertainDeliveryID(
                $budget, $targetMonth, $variant['scope'], $variant['account_id']
            );
            if ($uncertainID !== null) {
                $this->logFailure($budget, $uncertainID, $targetMonth, $variant['scope'], BudgetForecastErrorCode::TRANSPORT);
                return [0, 0, 1];
            }
            return [0, 1, 0];
        }
        return $this->prepareAndSend($budget, $owner, $variant, $targetMonth, $claim);
    }

    /**
     * Performs final pre-transport validation and maps failures to safe persistent codes.
     *
     * @param array{scope: string, account_id: int, account_ids: list<int>, label: string} $variant
     * @param array{id: int, token: string} $claim
     * @return array{int, int, int}
     */
    private function prepareAndSend(
        HouseholdBudget $budget,
        Member $owner,
        array $variant,
        string $targetMonth,
        array $claim
    ): array {
        [$currentBudget, $currentOwner] = $this->refreshedRecords($budget, $owner);
        if (!$currentBudget || !$currentOwner) {
            return $this->skipClaim($budget, $claim, $targetMonth, $variant['scope'], BudgetForecastErrorCode::RECIPIENT);
        }
        if ($variant['scope'] === BudgetForecastScope::OVERALL) {
            $variant['account_ids'] = array_map(
                fn (BankAccount $account): int => (int)$account->ID,
                $this->ownAccounts($currentOwner)
            );
        }
        $eligibility = $this->eligibilityError($currentBudget, $currentOwner, $variant);
        if ($eligibility !== null) {
            return $this->skipClaim($budget, $claim, $targetMonth, $variant['scope'], $eligibility);
        }
        try {
            $forecast = $this->forecasts->create(
                $currentOwner,
                $variant['account_ids'],
                $targetMonth,
                $variant['scope'],
                $variant['label']
            );
            $email = $this->emails->prepare($forecast, $currentOwner);
        } catch (Throwable) {
            $this->deliveries->markFailed($claim, BudgetForecastErrorCode::PREPARATION);
            $this->logFailure($budget, $claim['id'], $targetMonth, $variant['scope'], BudgetForecastErrorCode::PREPARATION);
            return [0, 0, 1];
        }
        return $this->finalCheckAndSend($budget, $owner, $variant, $targetMonth, $claim, $email);
    }

    /**
     * Rechecks the local date, settings and account ownership immediately before handoff.
     *
     * @param array{scope: string, account_id: int, account_ids: list<int>, label: string} $variant
     * @param array{id: int, token: string} $claim
     * @return array{int, int, int}
     */
    private function finalCheckAndSend(
        HouseholdBudget $budget,
        Member $owner,
        array $variant,
        string $targetMonth,
        array $claim,
        Email $email
    ): array {
        $now = $this->now();
        $eligibility = !$this->isMonthEnd($now) || $this->targetMonth($now) !== $targetMonth
            ? BudgetForecastErrorCode::CLAIM_EXPIRED
            : null;
        if ($eligibility !== null) {
            return $this->skipClaim($budget, $claim, $targetMonth, $variant['scope'], $eligibility);
        }
        [$currentBudget, $currentOwner] = $this->refreshedRecords($budget, $owner);
        if (!$currentBudget || !$currentOwner) {
            return $this->skipClaim($budget, $claim, $targetMonth, $variant['scope'], BudgetForecastErrorCode::RECIPIENT);
        }
        $eligibility = $this->eligibilityError($currentBudget, $currentOwner, $variant);
        if ($eligibility !== null) {
            return $this->skipClaim($budget, $claim, $targetMonth, $variant['scope'], $eligibility);
        }
        $email->setTo((string)$currentOwner->Email);
        if (!$this->deliveries->beginSending($claim, $now, $this->claimLeaseMinutes())) {
            $this->logFailure($budget, $claim['id'], $targetMonth, $variant['scope'], BudgetForecastErrorCode::CLAIM_EXPIRED);
            return [0, 0, 1];
        }
        try {
            $this->emails->send($email);
        } catch (Throwable) {
            $this->deliveries->markUncertain($claim, BudgetForecastErrorCode::TRANSPORT);
            $this->logFailure($budget, $claim['id'], $targetMonth, $variant['scope'], BudgetForecastErrorCode::TRANSPORT);
            return [0, 0, 1];
        }
        if (!$this->deliveries->markSent($claim, $this->now())) {
            $this->deliveries->markUncertain($claim, BudgetForecastErrorCode::STATE_UPDATE);
            $this->logFailure($budget, $claim['id'], $targetMonth, $variant['scope'], BudgetForecastErrorCode::STATE_UPDATE);
            return [0, 0, 1];
        }
        return [1, 0, 0];
    }

    /**
     * Revalidates master/variant opt-in, current member access, email and account ownership.
     */
    private function eligibilityError(
        HouseholdBudget $budget,
        Member $owner,
        array $variant
    ): ?string {
        if ((int)$budget->OwnerID !== (int)$owner->ID || !$budget->canView($owner)) {
            return BudgetForecastErrorCode::ACCESS;
        }
        if (!$budget->ForecastEmailsEnabled) {
            return '';
        }
        if (!$owner->exists() || filter_var((string)$owner->Email, FILTER_VALIDATE_EMAIL) === false) {
            return BudgetForecastErrorCode::RECIPIENT;
        }
        // A reloaded Member does not invalidate Permission's member-ID cache.
        Permission::reset();
        if (!Permission::check(HouseholdBudgetPermission::ACCESS, 'any', $owner)) {
            return BudgetForecastErrorCode::ACCESS;
        }
        if ($variant['scope'] === BudgetForecastScope::OVERALL && !$budget->CombinedForecastEmailEnabled) {
            return '';
        }
        if ($variant['scope'] === BudgetForecastScope::ACCOUNT && !$this->accountSettingEnabled($budget, $variant['account_id'])) {
            return '';
        }
        $ownIDs = array_map(fn (BankAccount $account): int => (int)$account->ID, $this->ownAccounts($owner));
        if ($variant['account_ids'] === [] || array_diff($variant['account_ids'], $ownIDs) !== []) {
            return BudgetForecastErrorCode::ACCOUNT_SCOPE;
        }
        if ($variant['scope'] === BudgetForecastScope::OVERALL && array_diff($ownIDs, $variant['account_ids']) !== []) {
            return BudgetForecastErrorCode::ACCOUNT_SCOPE;
        }
        return null;
    }

    /**
     * Reloads the owner and workspace immediately before using their current settings.
     *
     * @return array{?HouseholdBudget, ?Member}
     */
    private function refreshedRecords(HouseholdBudget $budget, Member $owner): array
    {
        $currentBudget = HouseholdBudget::get()->byID($budget->ID);
        $currentOwner = Member::get()->byID($owner->ID);
        return [
            $currentBudget instanceof HouseholdBudget ? $currentBudget : null,
            $currentOwner instanceof Member ? $currentOwner : null,
        ];
    }

    /**
     * Confirms a selected individual account is still enabled for this workspace.
     */
    private function accountSettingEnabled(HouseholdBudget $budget, int $accountID): bool
    {
        return BudgetForecastAccountSetting::get()->filter([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $accountID,
            BudgetForecastField::ENABLED => 1,
        ])->exists();
    }

    /**
     * Records an ineligible claim as skipped and reports real access/data errors.
     *
     * @param array{id: int, token: string} $claim
     * @return array{int, int, int}
     */
    private function skipClaim(
        HouseholdBudget $budget,
        array $claim,
        string $month,
        string $scope,
        string $errorCode
    ): array {
        $code = $errorCode === '' ? '' : $errorCode;
        $this->deliveries->markSkipped($claim, $code);
        if ($code !== '') {
            $this->logFailure($budget, $claim['id'], $month, $scope, $code);
        }
        return [0, 1, $code === '' ? 0 : 1];
    }

    /**
     * Validates an integer claim lease from module configuration.
     */
    private function claimLeaseMinutes(): int
    {
        $lease = Config::inst()->get(self::class, 'claim_lease_minutes');
        if (!is_int($lease) || $lease < 1 || $lease > 1440) {
            throw new InvalidArgumentException('Forecast claim_lease_minutes must be from 1 to 1440.');
        }
        return $lease;
    }

    /**
     * Determines whether the local application calendar date is its final day.
     */
    private function isMonthEnd(DateTimeImmutable $date): bool
    {
        return $date->format('Y-m-d') === $date->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * Computes next month from this month's first day, including December rollover.
     */
    private function targetMonth(DateTimeImmutable $date): string
    {
        return $date->modify('first day of this month')->modify('+1 month')->format('Y-m');
    }

    /**
     * Emits a safe structured event without addresses, labels, financial values or exception text.
     */
    private function logFailure(
        HouseholdBudget $budget,
        int $deliveryID,
        string $month,
        string $scope,
        string $code
    ): void {
        $this->logger->error('Monthly household forecast delivery failed or was skipped.', [
            'household_budget_id' => (int)$budget->ID,
            'delivery_id' => $deliveryID,
            'target_month' => $month,
            'scope' => $scope,
            'error_code' => $code,
        ]);
    }

    /**
     * Creates a consistent aggregate response for task output.
     *
     * @return array{due: bool, processed: int, skipped: int, failed: int, target_month: ?string}
     */
    private function summary(
        bool $due,
        int $processed,
        int $skipped,
        int $failed,
        ?string $targetMonth
    ): array {
        return [
            'due' => $due,
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => $failed,
            'target_month' => $targetMonth,
        ];
    }
}
