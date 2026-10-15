<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forms;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastScope;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastStatus;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastAccountSetting;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastDelivery;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetReportService;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FormField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use InvalidArgumentException;

/**
 * Persists only the current owner's per-account forecast selections.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forms
 */
final class HouseholdBudgetForecastSettingsField extends FormField
{
    private HouseholdBudget $budget;

    /**
     * Renders own accounts and delivery states without creating setting rows.
     */
    public function __construct(string $name, HouseholdBudget $budget)
    {
        $this->budget = $budget;
        parent::__construct($name, _t(__CLASS__ . '.ACCOUNTS', 'Forecasts for my bank accounts'));
        $this->setValue($this->initialValue());
    }

    /**
     * Renders the account and read-only delivery table inside the existing CMS form.
     */
    public function FieldHolder($properties = [])
    {
        return $this->customise($this->templateData())->renderWith(
            'Clesson\Silverstripe\HouseholdBudget\Forms\HouseholdBudgetForecastSettingsField'
        );
    }

    /**
     * Validates every submitted account identifier against the owner's current account list.
     */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->validSelection()) {
            $result->addError(_t(__CLASS__ . '.INVALID_SELECTION', 'Forecast settings contain an account not linked to your profile.'));
        }
        return $result;
    }

    /**
     * Saves settings only for the household owner and current own account selection.
     */
    public function saveInto(DataObjectInterface $record): void
    {
        if (!$record instanceof HouseholdBudget || !$record->canEdit(Security::getCurrentUser())) {
            throw new InvalidArgumentException('Forecast settings can only be saved by the household owner.');
        }
        if (!$this->validSelection()) {
            throw new InvalidArgumentException('Forecast settings contain an invalid account selection.');
        }
        $this->saveAccountSettings($this->submittedEnabledIDs());
    }

    /**
     * Builds current account rows and owner-only delivery history for the template.
     */
    private function templateData(): ArrayData
    {
        $accounts = $this->accountRows();
        $deliveries = $this->deliveryRows();
        return ArrayData::create([
            'Title' => $this->Title(),
            'Accounts' => $accounts,
            'Deliveries' => $deliveries,
            'HasAccounts' => $accounts->count() > 0,
            'HasDeliveries' => $deliveries->count() > 0,
            'Description' => _t(
                __CLASS__ . '.DESCRIPTION',
                'Only your member email address is used. Forecasts are sent on the last calendar day in the application time zone ({timezone}) and include a private PDF.',
                ['timezone' => date_default_timezone_get()]
            ),
            'RecipientLabel' => _t(__CLASS__ . '.RECIPIENT', 'Recipient'),
            'RecipientEmail' => (string)$this->budget->Owner()->Email,
            'ScopeLabel' => _t(__CLASS__ . '.SCOPE', 'An overall forecast includes every own linked account, even when individual emails are disabled.'),
            'NoAccounts' => _t(__CLASS__ . '.NO_ACCOUNTS', 'No bank accounts are currently linked to your profile.'),
            'HistoryLabel' => _t(__CLASS__ . '.HISTORY', 'Recent delivery status'),
            'MonthLabel' => _t(__CLASS__ . '.MONTH', 'Forecast month'),
            'StatusLabel' => _t(__CLASS__ . '.STATUS', 'Status'),
            'ScopeColumnLabel' => _t(__CLASS__ . '.SCOPE_COLUMN', 'Scope'),
            'ErrorLabel' => _t(__CLASS__ . '.RESULT', 'Result'),
            'NoHistory' => _t(__CLASS__ . '.NO_HISTORY', 'No forecast emails have been processed.'),
            'OverallLabel' => BudgetForecastScope::label(BudgetForecastScope::OVERALL),
        ]);
    }

    /**
     * Produces the rendered account list from the owner's current Contact relation.
     */
    private function accountRows(): ArrayList
    {
        $owner = $this->budget->Owner();
        if (!$owner->exists()) {
            return new ArrayList();
        }
        $settings = BudgetForecastAccountSetting::get()
            ->filter(BudgetForecastField::HOUSEHOLD_BUDGET_ID, $this->budget->ID)
            ->toArray();
        $enabledByAccount = [];
        foreach ($settings as $setting) {
            $enabledByAccount[(int)$setting->AccountID] = (bool)$setting->Enabled;
        }
        $accounts = (new BudgetReportService())->accountsFor($owner)->toArray();
        return new ArrayList(array_map(fn ($account): ArrayData => ArrayData::create([
            'ID' => (int)$account->ID,
            'Title' => (string)$account->Summary,
            'Enabled' => $enabledByAccount[(int)$account->ID] ?? false,
        ]), $accounts));
    }

    /**
     * Returns a short, private history with translated safe status and scope labels.
     */
    private function deliveryRows(): ArrayList
    {
        $deliveries = BudgetForecastDelivery::get()
            ->filter(BudgetForecastField::HOUSEHOLD_BUDGET_ID, $this->budget->ID)
            ->sort('TargetMonth DESC, ID DESC')
            ->limit(12);
        return new ArrayList(array_map(fn (BudgetForecastDelivery $delivery): ArrayData => ArrayData::create([
            'Month' => (string)$delivery->TargetMonth,
            'Scope' => $this->deliveryScope($delivery),
            'Status' => BudgetForecastStatus::label((string)$delivery->Status),
            'Result' => $this->safeErrorLabel((string)$delivery->LastErrorCode),
        ]), $deliveries->toArray()));
    }

    /**
     * Returns the visible scope label without exposing account identifiers.
     */
    private function deliveryScope(BudgetForecastDelivery $delivery): string
    {
        if ($delivery->Scope === BudgetForecastScope::OVERALL) {
            return BudgetForecastScope::label(BudgetForecastScope::OVERALL);
        }
        return (string)$delivery->Account()->Summary;
    }

    /**
     * Translates only one of the system-defined safe delivery result codes.
     */
    private function safeErrorLabel(string $code): string
    {
        if ($code === '') {
            return '';
        }
        return _t(__CLASS__ . '.RESULT_' . strtoupper($code), 'Review the delivery status.');
    }

    /**
     * Returns account IDs enabled in the submitted form.
     *
     * @return list<int>
     */
    private function submittedEnabledIDs(): array
    {
        return $this->submittedIDs('EnabledIDs');
    }

    /**
     * Converts one nested form ID list into strictly validated positive integers.
     *
     * @return list<int>
     */
    private function submittedIDs(string $key): array
    {
        $value = $this->getValue();
        $values = is_array($value) ? ($value[$key] ?? []) : [];
        return array_map(fn (string $id): int => (int)$id, $values);
    }

    /**
     * Checks complete current account coverage and rejects malformed or foreign IDs.
     */
    private function validSelection(): bool
    {
        // Clear-missing form submissions omit this field when no account inputs are rendered.
        // The exact current-account comparison below still rejects omission for linked accounts.
        $value = $this->getValue() ?? [];
        if (!is_array($value)) {
            return false;
        }
        $all = $this->validatedIDs($value['AccountIDs'] ?? []);
        $enabled = $this->validatedIDs($value['EnabledIDs'] ?? []);
        if ($all === null || $enabled === null || count(array_unique($all)) !== count($all)
            || count(array_unique($enabled)) !== count($enabled) || array_diff($enabled, $all) !== []) {
            return false;
        }
        $owner = $this->budget->Owner();
        $expected = $owner->exists()
            ? array_map('intval', (new BudgetReportService())->accountsFor($owner)->column('ID'))
            : [];
        sort($all);
        sort($expected);
        return $all === $expected;
    }

    /**
     * Parses submitted IDs without accepting coercible or malformed input.
     *
     * @return list<int>|null
     */
    private function validatedIDs(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $id) {
            if (!is_string($id) || !ctype_digit($id) || (int)$id < 1 || (string)(int)$id !== $id) {
                return null;
            }
        }
        return array_map('intval', $value);
    }

    /**
     * Creates or updates exactly one setting for every currently linked own account.
     *
     * @param list<int> $enabledIDs
     */
    private function saveAccountSettings(array $enabledIDs): void
    {
        $accounts = (new BudgetReportService())->accountsFor($this->budget->Owner())->toArray();
        foreach ($accounts as $account) {
            $setting = BudgetForecastAccountSetting::get()->filter([
                BudgetForecastField::HOUSEHOLD_BUDGET_ID => $this->budget->ID,
                BudgetForecastField::ACCOUNT_ID => $account->ID,
            ])->first() ?: BudgetForecastAccountSetting::create([
                BudgetForecastField::HOUSEHOLD_BUDGET_ID => $this->budget->ID,
                BudgetForecastField::ACCOUNT_ID => $account->ID,
            ]);
            $setting->Enabled = in_array((int)$account->ID, $enabledIDs, true);
            $setting->write();
        }
    }

    /**
     * Supplies an initial complete ID selection so unchecked accounts save as disabled.
     *
     * @return array{AccountIDs: list<string>, EnabledIDs: list<string>}
     */
    private function initialValue(): array
    {
        $rows = $this->accountRows();
        $all = [];
        $enabled = [];
        foreach ($rows as $row) {
            $id = (string)$row->ID;
            $all[] = $id;
            if ($row->Enabled) {
                $enabled[] = $id;
            }
        }
        return ['AccountIDs' => $all, 'EnabledIDs' => $enabled];
    }
}
