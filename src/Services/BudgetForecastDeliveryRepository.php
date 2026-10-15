<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastErrorCode;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastStatus;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastDelivery;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use DateTimeImmutable;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Permission;
use RuntimeException;

/**
 * Performs token-conditional database claims and delivery state transitions.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class BudgetForecastDeliveryRepository
{
    /**
     * Claims a unique delivery variant atomically or returns null when another state owns it.
     *
     * @return array{id: int, token: string}|null
     */
    public function claim(
        HouseholdBudget $budget,
        string $targetMonth,
        string $scope,
        int $accountID,
        DateTimeImmutable $now,
        int $leaseMinutes
    ): ?array {
        $this->assertTechnicalAdmin();
        $delivery = $this->findVariant($budget, $targetMonth, $scope, $accountID);
        if (!$delivery) {
            return $this->createClaim($budget, $targetMonth, $scope, $accountID, $now);
        }
        $this->closeExpiredSending($delivery, $now, $leaseMinutes);
        return $this->reclaimIfSafe($delivery, $now, $leaseMinutes);
    }

    /**
     * Distinguishes a blocked uncertain transport from harmless duplicate or active claims.
     */
    public function uncertainDeliveryID(
        HouseholdBudget $budget,
        string $targetMonth,
        string $scope,
        int $accountID
    ): ?int {
        $this->assertTechnicalAdmin();
        $delivery = $this->findVariant($budget, $targetMonth, $scope, $accountID);
        return $delivery?->Status === BudgetForecastStatus::UNCERTAIN ? (int)$delivery->ID : null;
    }

    /**
     * Atomically transitions a fresh preparation claim to sending before transport handoff.
     *
     * @param array{id: int, token: string} $claim
     */
    public function beginSending(array $claim, DateTimeImmutable $now, int $leaseMinutes): bool
    {
        $this->assertTechnicalAdmin();
        $freshSince = $now->modify('-' . $leaseMinutes . ' minutes')->format('Y-m-d H:i:s');
        return $this->updateClaim($claim, [
            'Status' => BudgetForecastStatus::SENDING,
            'ClaimedAt' => $now->format('Y-m-d H:i:s'),
            'LastErrorCode' => '',
        ], BudgetForecastStatus::PREPARING, 'ClaimedAt >= ? OR ClaimedAt IS NULL', [$freshSince]);
    }

    /**
     * Stores successful transport acceptance only for the active sending claim.
     *
     * @param array{id: int, token: string} $claim
     */
    public function markSent(array $claim, DateTimeImmutable $now): bool
    {
        $this->assertTechnicalAdmin();
        return $this->updateClaim($claim, [
            'Status' => BudgetForecastStatus::SENT,
            'SentAt' => $now->format('Y-m-d H:i:s'),
            'LastErrorCode' => '',
        ], BudgetForecastStatus::SENDING);
    }

    /**
     * Marks a known pre-transport failure as eligible for a same-day retry.
     *
     * @param array{id: int, token: string} $claim
     */
    public function markFailed(array $claim, string $errorCode): bool
    {
        $this->assertTechnicalAdmin();
        return $this->updateClaim($claim, [
            'Status' => BudgetForecastStatus::FAILED,
            'LastErrorCode' => $errorCode,
        ], BudgetForecastStatus::PREPARING);
    }

    /**
     * Records a transport exception conservatively and permanently blocks automatic retry.
     *
     * @param array{id: int, token: string} $claim
     */
    public function markUncertain(array $claim, string $errorCode): bool
    {
        $this->assertTechnicalAdmin();
        return $this->updateClaim($claim, [
            'Status' => BudgetForecastStatus::UNCERTAIN,
            'LastErrorCode' => $errorCode,
        ], BudgetForecastStatus::SENDING);
    }

    /**
     * Closes a preparation claim when eligibility changed before transport handoff.
     *
     * @param array{id: int, token: string} $claim
     */
    public function markSkipped(array $claim, string $errorCode): bool
    {
        $this->assertTechnicalAdmin();
        return $this->updateClaim($claim, [
            'Status' => BudgetForecastStatus::SKIPPED,
            'LastErrorCode' => $errorCode,
        ], BudgetForecastStatus::PREPARING);
    }

    /**
     * Finds the unique owner/month/scope/account variant.
     */
    private function findVariant(
        HouseholdBudget $budget,
        string $targetMonth,
        string $scope,
        int $accountID
    ): ?BudgetForecastDelivery {
        return BudgetForecastDelivery::get()->filter([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::TARGET_MONTH => $targetMonth,
            BudgetForecastField::SCOPE => $scope,
            BudgetForecastField::ACCOUNT_ID => $accountID,
        ])->first();
    }

    /**
     * Creates the first claim, recognizing a competing insert only when its unique row exists.
     *
     * @return array{id: int, token: string}|null
     */
    private function createClaim(
        HouseholdBudget $budget,
        string $targetMonth,
        string $scope,
        int $accountID,
        DateTimeImmutable $now
    ): ?array {
        $token = bin2hex(random_bytes(32));
        $delivery = BudgetForecastDelivery::create([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $accountID,
            BudgetForecastField::TARGET_MONTH => $targetMonth,
            BudgetForecastField::SCOPE => $scope,
            BudgetForecastField::STATUS => BudgetForecastStatus::PREPARING,
            BudgetForecastField::ATTEMPT_COUNT => 1,
            BudgetForecastField::CLAIM_TOKEN => $token,
            BudgetForecastField::CLAIMED_AT => $now->format('Y-m-d H:i:s'),
        ]);
        try {
            $delivery->write();
            return ['id' => (int)$delivery->ID, 'token' => $token];
        } catch (DatabaseException|ValidationException $exception) {
            if ($this->findVariant($budget, $targetMonth, $scope, $accountID)) {
                return null;
            }
            throw $exception;
        }
    }

    /**
     * Reclaims known pre-transport failures/skips or expired preparation leases.
     *
     * @return array{id: int, token: string}|null
     */
    private function reclaimIfSafe(
        BudgetForecastDelivery $delivery,
        DateTimeImmutable $now,
        int $leaseMinutes
    ): ?array {
        $status = (string)$delivery->Status;
        if (!in_array($status, [
            BudgetForecastStatus::FAILED, BudgetForecastStatus::SKIPPED, BudgetForecastStatus::PREPARING,
        ], true)) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $freshSince = $now->modify('-' . $leaseMinutes . ' minutes')->format('Y-m-d H:i:s');
        if (!$this->atomicReclaim((int)$delivery->ID, $token, $now, $freshSince)) {
            return null;
        }
        return ['id' => (int)$delivery->ID, 'token' => $token];
    }

    /**
     * Uses a conditional update rather than a read-then-write claim decision.
     */
    private function atomicReclaim(
        int $deliveryID,
        string $token,
        DateTimeImmutable $now,
        string $freshSince
    ): bool {
        $table = $this->quotedTable();
        DB::prepared_query(
            'UPDATE ' . $table . ' SET "Status" = ?, "ClaimToken" = ?, "ClaimedAt" = ?, '
            . '"AttemptCount" = "AttemptCount" + 1, "LastErrorCode" = ? WHERE "ID" = ? '
            . 'AND ("Status" IN (?, ?) OR ("Status" = ? AND ("ClaimedAt" < ? OR "ClaimedAt" IS NULL)))',
            [
                BudgetForecastStatus::PREPARING, $token, $now->format('Y-m-d H:i:s'), '', $deliveryID,
                BudgetForecastStatus::FAILED, BudgetForecastStatus::SKIPPED,
                BudgetForecastStatus::PREPARING, $freshSince,
            ]
        );
        return DB::affected_rows() === 1;
    }

    /**
     * Converts an expired in-transport claim to uncertain without making it retryable.
     */
    private function closeExpiredSending(
        BudgetForecastDelivery $delivery,
        DateTimeImmutable $now,
        int $leaseMinutes
    ): void {
        if ($delivery->Status !== BudgetForecastStatus::SENDING) {
            return;
        }
        $expiredBefore = $now->modify('-' . $leaseMinutes . ' minutes')->format('Y-m-d H:i:s');
        $this->updateClaim(
            ['id' => (int)$delivery->ID, 'token' => (string)$delivery->ClaimToken],
            [
                'Status' => BudgetForecastStatus::UNCERTAIN,
                'LastErrorCode' => BudgetForecastErrorCode::TRANSPORT,
            ],
            BudgetForecastStatus::SENDING,
            'ClaimedAt < ?',
            [$expiredBefore]
        );
    }

    /**
     * Executes one status transition with ID, claim token, prior state, and optional condition.
     *
     * @param array{id: int, token: string} $claim
     * @param array<string, string> $values
     * @param list<mixed> $extraParameters
     */
    private function updateClaim(
        array $claim,
        array $values,
        string $expectedStatus,
        string $condition = '',
        array $extraParameters = []
    ): bool {
        $assignments = [];
        $parameters = [];
        foreach ($values as $field => $value) {
            $assignments[] = '"' . $field . '" = ?';
            $parameters[] = $value;
        }
        $parameters[] = $claim['id'];
        $parameters[] = $claim['token'];
        $parameters[] = $expectedStatus;
        $where = '"ID" = ? AND "ClaimToken" = ? AND "Status" = ?';
        if ($condition !== '') {
            $where .= ' AND (' . $condition . ')';
            array_push($parameters, ...$extraParameters);
        }
        DB::prepared_query(
            'UPDATE ' . $this->quotedTable() . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where,
            $parameters
        );
        return DB::affected_rows() === 1;
    }

    /**
     * Returns the schema-resolved delivery table identifier.
     */
    private function quotedTable(): string
    {
        return '"' . DataObject::getSchema()->tableName(BudgetForecastDelivery::class) . '"';
    }

    /**
     * Ensures technical claim transitions are never exposed to regular CMS users.
     */
    private function assertTechnicalAdmin(): void
    {
        if (!Permission::check('ADMIN')) {
            throw new RuntimeException('Only an administrator may change forecast delivery states.');
        }
    }
}
