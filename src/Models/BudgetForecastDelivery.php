<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Models;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastScope;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastStatus;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use DateTimeImmutable;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Minimal technical claim and outcome record for one forecast email variant.
 *
 * @property string $TargetMonth
 * @property string $Scope
 * @property string $Status
 * @property int $AttemptCount
 * @property string $ClaimToken
 * @property string $ClaimedAt
 * @property string $SentAt
 * @property string $LastErrorCode
 * @property int $HouseholdBudgetID
 * @property int $AccountID
 * @method HouseholdBudget HouseholdBudget()
 * @method BankAccount Account()
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Models
 */
class BudgetForecastDelivery extends DataObject
{
    private static string $table_name = 'HouseholdBudget_ForecastDelivery';
    private static string $default_sort = 'Created DESC';
    private static string $general_search_field = BudgetForecastField::TARGET_MONTH;
    private static array $db = [
        BudgetForecastField::TARGET_MONTH => 'Varchar(7)',
        BudgetForecastField::SCOPE => 'Varchar(7)',
        BudgetForecastField::STATUS => 'Varchar(12)',
        BudgetForecastField::ATTEMPT_COUNT => 'Int',
        BudgetForecastField::CLAIM_TOKEN => 'Varchar(64)',
        BudgetForecastField::CLAIMED_AT => 'Datetime',
        BudgetForecastField::SENT_AT => 'Datetime',
        BudgetForecastField::LAST_ERROR_CODE => 'Varchar(64)',
    ];
    private static array $has_one = [
        BudgetForecastField::HOUSEHOLD_BUDGET => HouseholdBudget::class,
        BudgetForecastField::ACCOUNT => BankAccount::class,
    ];
    private static array $indexes = [
        'ForecastVariantUnique' => [
            'type' => 'unique',
            'columns' => [
                BudgetForecastField::HOUSEHOLD_BUDGET_ID,
                BudgetForecastField::TARGET_MONTH,
                BudgetForecastField::SCOPE,
                BudgetForecastField::ACCOUNT_ID,
            ],
        ],
    ];

    /**
     * Initializes a safe new preparation attempt.
     */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
        $this->Status = BudgetForecastStatus::PREPARING;
        $this->AttemptCount = 0;
        $this->ClaimToken = '';
        $this->LastErrorCode = '';
    }

    /**
     * Validates month syntax, recognized state and scope/account consistency.
     */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $this->validateMonth($result);
        $this->validateVariant($result);
        $this->validateUniqueVariant($result);
        return $result;
    }

    /**
     * Returns translated labels for every persisted field and relation.
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        foreach (BudgetForecastField::options() as $field => $label) {
            $labels[$field] = $label;
        }
        $labels[BudgetForecastField::HOUSEHOLD_BUDGET_ID] =
            BudgetForecastField::label(BudgetForecastField::HOUSEHOLD_BUDGET);
        $labels[BudgetForecastField::ACCOUNT_ID] = BudgetForecastField::label(BudgetForecastField::ACCOUNT);
        $labels['ID'] = _t('Clesson\Silverstripe\HouseholdBudget\Common.ID', 'ID');
        $labels['Created'] = _t('Clesson\Silverstripe\HouseholdBudget\Common.CREATED', 'Created');
        $labels['LastEdited'] = _t('Clesson\Silverstripe\HouseholdBudget\Common.LAST_EDITED', 'Last edited');
        return $labels;
    }

    /**
     * Keeps delivery claims out of general-purpose CMS editing interfaces.
     */
    public function getCMSFields(): FieldList
    {
        return new FieldList();
    }

    /**
     * Owners may read only the delivery status attached to their own workspace.
     */
    public function canView($member = null): bool
    {
        $member = $member instanceof Member ? $member : Security::getCurrentUser();
        return $member instanceof Member
            && $this->HouseholdBudget()->exists()
            && (int)$this->HouseholdBudget()->OwnerID === (int)$member->ID;
    }

    /**
     * Delivery rows are created only through the authorized technical repository.
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    /**
     * Delivery rows are updated only through token-conditional database transitions.
     */
    public function canEdit($member = null): bool
    {
        return false;
    }

    /**
     * Delivery rows are retained to prevent duplicate month/scope deliveries.
     */
    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * Validates the target month using a strict YYYY-MM round trip.
     */
    private function validateMonth(ValidationResult $result): void
    {
        $month = (string)$this->TargetMonth;
        $date = DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (!$date || $date->format('Y-m') !== $month || str_starts_with($month, '0000')) {
            $result->addError(_t(__CLASS__ . '.MONTH_INVALID', 'Enter a valid forecast month in YYYY-MM format.'));
        }
    }

    /**
     * Enforces valid overall and account-specific relation combinations.
     */
    private function validateVariant(ValidationResult $result): void
    {
        $accountValid = $this->Scope === BudgetForecastScope::ACCOUNT
            && $this->Account()->exists()
            && $this->accountBelongsToOwner();
        $overallValid = $this->Scope === BudgetForecastScope::OVERALL && (int)$this->AccountID === 0;
        if (!$this->HouseholdBudget()->exists() || (!$accountValid && !$overallValid)) {
            $result->addError(_t(__CLASS__ . '.SCOPE_INVALID', 'Select a valid forecast scope and account.'));
        }
        if (!isset(BudgetForecastStatus::options()[$this->Status])) {
            $result->addError(_t(__CLASS__ . '.STATUS_INVALID', 'Select a valid delivery status.'));
        }
    }

    /**
     * Reports duplicates early while the database unique key remains authoritative.
     */
    private function validateUniqueVariant(ValidationResult $result): void
    {
        $query = static::get()->filter([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $this->HouseholdBudgetID,
            BudgetForecastField::TARGET_MONTH => $this->TargetMonth,
            BudgetForecastField::SCOPE => $this->Scope,
            BudgetForecastField::ACCOUNT_ID => $this->AccountID,
        ]);
        $duplicate = $this->isInDB() ? $query->exclude('ID', $this->ID)->exists() : $query->exists();
        if ($duplicate) {
            $result->addError(_t(__CLASS__ . '.DUPLICATE_INVALID', 'A delivery record already exists for this forecast.'));
        }
    }

    /**
     * Confirms that an account variant still belongs to this household owner.
     */
    private function accountBelongsToOwner(): bool
    {
        $owner = $this->HouseholdBudget()->Owner();
        $contactID = $owner->exists() ? (int)$owner->Contact()->ID : 0;
        return $contactID > 0 && (int)$this->Account()->ContactID === $contactID;
    }
}
