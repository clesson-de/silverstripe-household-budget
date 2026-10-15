<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Models;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Stores an owner's opt-in for one of their linked bank accounts.
 *
 * @property bool $Enabled
 * @property int $HouseholdBudgetID
 * @property int $AccountID
 * @method HouseholdBudget HouseholdBudget()
 * @method BankAccount Account()
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Models
 */
class BudgetForecastAccountSetting extends DataObject
{
    private static string $table_name = 'HouseholdBudget_ForecastAccountSetting';
    private static string $default_sort = 'ID ASC';
    private static string $general_search_field = 'Enabled';
    private static array $db = [BudgetForecastField::ENABLED => 'Boolean'];
    private static array $has_one = [
        BudgetForecastField::HOUSEHOLD_BUDGET => HouseholdBudget::class,
        BudgetForecastField::ACCOUNT => BankAccount::class,
    ];
    private static array $indexes = [
        'BudgetAccountUnique' => [
            'type' => 'unique',
            'columns' => [BudgetForecastField::HOUSEHOLD_BUDGET_ID, BudgetForecastField::ACCOUNT_ID],
        ],
    ];

    /**
     * Defaults account opt-in to disabled.
     */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
        $this->Enabled = false;
    }

    /**
     * Validates required relations, ownership and the unique household/account pair.
     */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $budget = $this->HouseholdBudget();
        $account = $this->Account();
        if (!$budget->exists() || !$account->exists() || !$this->accountBelongsToOwner($budget, $account)) {
            $result->addError(_t(__CLASS__ . '.ACCOUNT_INVALID', 'Select an account linked to this household owner.'));
        }
        if ($this->duplicateExists()) {
            $result->addError(_t(__CLASS__ . '.PAIR_INVALID', 'This account already has a forecast setting.'));
        }
        return $result;
    }

    /**
     * Returns translated labels for settings fields and relations.
     */
    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            BudgetForecastField::ENABLED => BudgetForecastField::label(BudgetForecastField::ENABLED),
            BudgetForecastField::HOUSEHOLD_BUDGET => BudgetForecastField::label(BudgetForecastField::HOUSEHOLD_BUDGET),
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => BudgetForecastField::label(BudgetForecastField::HOUSEHOLD_BUDGET),
            BudgetForecastField::ACCOUNT => BudgetForecastField::label(BudgetForecastField::ACCOUNT),
            BudgetForecastField::ACCOUNT_ID => BudgetForecastField::label(BudgetForecastField::ACCOUNT),
            'ID' => _t('Clesson\Silverstripe\HouseholdBudget\Common.ID', 'ID'),
            'Created' => _t('Clesson\Silverstripe\HouseholdBudget\Common.CREATED', 'Created'),
            'LastEdited' => _t('Clesson\Silverstripe\HouseholdBudget\Common.LAST_EDITED', 'Last edited'),
        ]);
    }

    /**
     * Does not expose technical settings as a separate CMS editing surface.
     */
    public function getCMSFields(): FieldList
    {
        return new FieldList();
    }

    /**
     * Only the household owner can view a setting for a currently linked account.
     */
    public function canView($member = null): bool
    {
        return $this->isOwner($member) && $this->accountBelongsToOwner($this->HouseholdBudget(), $this->Account());
    }

    /**
     * Only the owner can create settings, and only for one of their own accounts.
     */
    public function canCreate($member = null, $context = []): bool
    {
        return $this->isOwner($member) && $this->accountBelongsToOwner($this->HouseholdBudget(), $this->Account());
    }

    /**
     * Only the owner can edit a setting while its account remains linked.
     */
    public function canEdit($member = null): bool
    {
        return $this->canView($member);
    }

    /**
     * Only the owner can remove a setting while its account remains linked.
     */
    public function canDelete($member = null): bool
    {
        return $this->canView($member);
    }

    /**
     * Checks whether another persisted setting uses the same household and account.
     */
    private function duplicateExists(): bool
    {
        $list = static::get()->filter([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $this->HouseholdBudgetID,
            BudgetForecastField::ACCOUNT_ID => $this->AccountID,
        ]);
        return $this->isInDB() ? $list->exclude('ID', $this->ID)->exists() : $list->exists();
    }

    /**
     * Confirms that the account contact is the owner's inverse Contact relation.
     */
    private function accountBelongsToOwner(HouseholdBudget $budget, BankAccount $account): bool
    {
        $owner = $budget->Owner();
        $contactID = $owner->exists() ? (int)$owner->Contact()->ID : 0;
        return $contactID > 0 && (int)$account->ContactID === $contactID;
    }

    /**
     * Resolves the explicitly supplied member or the current CMS member.
     */
    private function isOwner($member = null): bool
    {
        $member = $member instanceof Member ? $member : Security::getCurrentUser();
        return $member instanceof Member
            && $this->HouseholdBudget()->exists()
            && (int)$this->HouseholdBudget()->OwnerID === (int)$member->ID;
    }
}
