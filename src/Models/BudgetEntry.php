<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Models;

use Clesson\Silverstripe\Contacts\Models\Contact;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetMonth;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetMonthMigrationService;
use Clesson\Silverstripe\HouseholdBudget\Forms\BudgetMonthsField;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * A monthly recurring household income or expense owned by one member.
 *
 * @property string $Description
 * @property string $Amount
 * @property string $Type
 * @property string $Months
 * @property string $StartMonth
 * @property string $EndMonth
 * @property int $AccountID
 * @property int $ContactID
 * @property int $OwnerID
 * @method BankAccount Account()
 * @method Contact Contact()
 * @method Member Owner()
 * @property-read string $TypeLabel
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Models
 */
class BudgetEntry extends DataObject
{
    private static string $table_name = 'HouseholdBudget_BudgetEntry';
    private static string $default_sort = 'Type DESC, Amount DESC';
    private static string $general_search_field = 'Description';

    private static array $db = [
        BudgetEntryField::DESCRIPTION => 'Varchar(150)',
        BudgetEntryField::AMOUNT => 'Decimal(12,2)',
        BudgetEntryField::TYPE => 'Varchar(11)',
        BudgetEntryField::MONTHS => 'Varchar(35)',
        BudgetEntryField::START_MONTH => 'Varchar(7)',
        BudgetEntryField::END_MONTH => 'Varchar(7)',
    ];

    private static array $has_one = [
        BudgetEntryField::ACCOUNT => BankAccount::class,
        BudgetEntryField::CONTACT => Contact::class,
        BudgetEntryField::OWNER => Member::class,
    ];

    /**
     * Returns translated labels for all fields and relations.
     *
     * @param mixed $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        foreach (BudgetEntryField::options() as $field => $label) {
            $labels[$field] = $label;
        }
        $labels[BudgetEntryField::ACCOUNT_ID] = BudgetEntryField::label(BudgetEntryField::ACCOUNT);
        $labels[BudgetEntryField::CONTACT_ID] = BudgetEntryField::label(BudgetEntryField::CONTACT);
        $labels[BudgetEntryField::OWNER_ID] = BudgetEntryField::label(BudgetEntryField::OWNER);
        $labels['TypeLabel'] = _t(__CLASS__ . '.TYPE', 'Transaction type');
        $labels['ID'] = _t('Clesson\Silverstripe\HouseholdBudget\Common.ID', 'ID');
        $labels['Created'] = _t('Clesson\Silverstripe\HouseholdBudget\Common.CREATED', 'Created');
        $labels['LastEdited'] = _t('Clesson\Silverstripe\HouseholdBudget\Common.LAST_EDITED', 'Last edited');
        return $labels;
    }

    /**
     * Validates required values, ownership and the optional date range.
     *
     * @return ValidationResult
     */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $this->validateRequiredValues($result);
        $this->validateAccountOwner($result);
        $this->validateDateRange($result);
        return $result;
    }

    /**
     * Sets the current member as owner when creating an entry.
     */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
        $member = Security::getCurrentUser();
        if ($member) {
            $this->setField(BudgetEntryField::OWNER_ID, $member->ID);
        }
    }

    /**
     * Returns the CMS form fields for editing this budget entry.
     *
     * @return FieldList
     */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName([
            BudgetEntryField::DESCRIPTION,
            BudgetEntryField::AMOUNT,
            BudgetEntryField::TYPE,
            BudgetEntryField::MONTHS,
            BudgetEntryField::START_MONTH,
            BudgetEntryField::END_MONTH,
            BudgetEntryField::ACCOUNT_ID,
            BudgetEntryField::CONTACT_ID,
            BudgetEntryField::OWNER_ID,
        ]);
        $this->addEntryFields($fields);
        return $fields;
    }

    /**
     * Returns the translated income or expense label.
     *
     * @return string
     */
    public function getTypeLabel(): string
    {
        return BudgetEntryType::label($this->getField(BudgetEntryField::TYPE));
    }

    /**
     * Only the owning member may view this entry.
     *
     * @param Member|null $member
     * @return bool
     */
    public function canView($member = null): bool
    {
        return $this->isOwnedBy($member);
    }

    /**
     * Only authenticated members may create their own entries.
     *
     * @param Member|null $member
     * @param array $context
     * @return bool
     */
    public function canCreate($member = null, $context = []): bool
    {
        return $this->resolveMember($member) !== null;
    }

    /**
     * Only the owning member may edit this entry.
     *
     * @param Member|null $member
     * @return bool
     */
    public function canEdit($member = null): bool
    {
        return $this->isOwnedBy($member);
    }

    /**
     * Only the owning member may delete this entry.
     *
     * @param Member|null $member
     * @return bool
     */
    public function canDelete($member = null): bool
    {
        return $this->isOwnedBy($member);
    }

    /**
     * Adds translated and owner-scoped form fields.
     *
     * @param FieldList $fields
     */
    private function addEntryFields(FieldList $fields): void
    {
        $fields->addFieldsToTab('Root.Main', [
            $this->descriptionField(),
            $this->amountField(),
            $this->typeField(),
            $this->monthsField(),
            $this->startMonthField(),
            $this->endMonthField(),
            $this->accountField(),
            $this->contactField(),
        ]);
    }

    /**
     * Creates the description field.
     *
     * @return TextField
     */
    private function descriptionField(): TextField
    {
        /** @var TextField $descriptionField */
        $descriptionField = TextField::create(
            BudgetEntryField::DESCRIPTION,
            $this->fieldLabel(BudgetEntryField::DESCRIPTION)
        );
        return $descriptionField;
    }

    /**
     * Creates the amount field.
     *
     * @return NumericField
     */
    private function amountField(): NumericField
    {
        /** @var NumericField $amountField */
        $amountField = NumericField::create(
            BudgetEntryField::AMOUNT,
            $this->fieldLabel(BudgetEntryField::AMOUNT)
        );
        $amountField->setScale(2)->setAttribute('step', '0.01')->setAttribute('inputmode', 'decimal');
        return $amountField;
    }

    /**
     * Creates the income or expense selector.
     *
     * @return DropdownField
     */
    private function typeField(): DropdownField
    {
        /** @var DropdownField $typeField */
        $typeField = DropdownField::create(
            BudgetEntryField::TYPE,
            $this->fieldLabel(BudgetEntryField::TYPE),
            BudgetEntryType::options()
        );
        return $typeField;
    }

    /**
     * Creates the recurring month selector.
     *
     * @return CheckboxSetField
     */
    private function monthsField(): CheckboxSetField
    {
        /** @var BudgetMonthsField $monthsField */
        $monthsField = BudgetMonthsField::create(
            BudgetEntryField::MONTHS,
            $this->fieldLabel(BudgetEntryField::MONTHS),
            BudgetMonth::options()
        );
        $monthsField->addExtraClass('household-budget-months');
        return $monthsField;
    }

    /**
     * Creates the optional start month and year field.
     *
     * @return TextField
     */
    private function startMonthField(): TextField
    {
        /** @var TextField $startMonthField */
        $startMonthField = TextField::create(
            BudgetEntryField::START_MONTH,
            $this->fieldLabel(BudgetEntryField::START_MONTH)
        )->setAttribute('type', 'month');
        return $startMonthField;
    }

    /**
     * Creates the optional end month and year field.
     *
     * @return TextField
     */
    private function endMonthField(): TextField
    {
        /** @var TextField $endMonthField */
        $endMonthField = TextField::create(
            BudgetEntryField::END_MONTH,
            $this->fieldLabel(BudgetEntryField::END_MONTH)
        )->setAttribute('type', 'month');
        return $endMonthField;
    }

    /**
     * Creates an account selector limited to accounts linked to the owner's contact.
     *
     * @return DropdownField
     */
    private function accountField(): DropdownField
    {
        $member = $this->resolveMember();
        $contactID = $member ? (int)$member->Contact()->ID : 0;
        $accounts = BankAccount::get()->filter(BudgetEntryField::CONTACT_ID, $contactID);
        if ($contactID === 0) {
            $accounts = $accounts->filter('ID', 0);
        }
        $source = $accounts->map('ID', 'Summary');
        /** @var DropdownField $accountField */
        $accountField = DropdownField::create(
            BudgetEntryField::ACCOUNT_ID,
            $this->fieldLabel(BudgetEntryField::ACCOUNT),
            $source
        )->setEmptyString('');
        return $accountField;
    }

    /**
     * Creates a counterparty selector.
     *
     * @return DropdownField
     */
    private function contactField(): DropdownField
    {
        $source = Contact::get()->sort('Name ASC')->map('ID', 'Name');
        /** @var DropdownField $contactField */
        $contactField = DropdownField::create(
            BudgetEntryField::CONTACT_ID,
            $this->fieldLabel(BudgetEntryField::CONTACT),
            $source
        )->setEmptyString('');
        return $contactField;
    }

    /**
     * Adds required-field and allowed-value validation errors.
     *
     * @param ValidationResult $result
     */
    private function validateRequiredValues(ValidationResult $result): void
    {
        foreach ([
            BudgetEntryField::DESCRIPTION,
            BudgetEntryField::ACCOUNT_ID,
        ] as $field) {
            if (!$this->getField($field)) {
                $this->addRequiredError($result, $field);
            }
        }
        $this->validateAmount($result);
        $this->validateType($result);
        $this->validateMonths($result);
    }

    /**
     * Adds a required-field validation error.
     *
     * @param ValidationResult $result
     * @param string $field
     */
    private function addRequiredError(ValidationResult $result, string $field): void
    {
        $result->addError(_t(
            Form::class . '.FIELDISREQUIRED',
            '{name} is required',
            ['name' => $this->fieldLabel($field)]
        ));
    }

    /**
     * Validates that the amount is greater than zero.
     *
     * @param ValidationResult $result
     */
    private function validateAmount(ValidationResult $result): void
    {
        $amount = $this->getField(BudgetEntryField::AMOUNT);
        if ($amount === null || $amount === '') {
            $this->addRequiredError($result, BudgetEntryField::AMOUNT);
        } elseif ((float)$amount <= 0) {
            $result->addError(_t(__CLASS__ . '.AMOUNT_INVALID', 'The amount must be greater than zero.'));
        }
    }

    /**
     * Validates the transaction type.
     *
     * @param ValidationResult $result
     */
    private function validateType(ValidationResult $result): void
    {
        $type = $this->getField(BudgetEntryField::TYPE);
        if (!$type) {
            $this->addRequiredError($result, BudgetEntryField::TYPE);
        } elseif (!isset(BudgetEntryType::options()[$type])) {
            $result->addError(_t(__CLASS__ . '.TYPE_INVALID', 'Select a valid transaction type.'));
        }
    }

    /**
     * Validates that at least one valid month is selected.
     *
     * @param ValidationResult $result
     */
    private function validateMonths(ValidationResult $result): void
    {
        $values = array_filter(explode(',', (string)$this->getField(BudgetEntryField::MONTHS)));
        if (!$values) {
            $this->addRequiredError($result, BudgetEntryField::MONTHS);
        } elseif (array_diff($values, array_keys(BudgetMonth::options()))) {
            $result->addError(_t(__CLASS__ . '.MONTHS_INVALID', 'Select at least one valid month.'));
        }
    }

    /**
     * Checks that the selected account belongs to the current member.
     *
     * @param ValidationResult $result
     */
    private function validateAccountOwner(ValidationResult $result): void
    {
        $member = $this->resolveMember();
        $account = $this->Account();
        $contactID = $member ? (int)$member->Contact()->ID : 0;
        if ($contactID === 0 || !$account->exists() || (int)$account->ContactID !== $contactID) {
            $result->addError(_t(__CLASS__ . '.ACCOUNT_INVALID', 'Select a bank account belonging to your profile.'));
        }
    }

    /**
     * Validates the optional inclusive date range.
     *
     * @param ValidationResult $result
     */
    private function validateDateRange(ValidationResult $result): void
    {
        $start = (string)$this->getField(BudgetEntryField::START_MONTH);
        $end = (string)$this->getField(BudgetEntryField::END_MONTH);
        foreach ([$start, $end] as $month) {
            if ($month !== '' && !preg_match('/^(?!0000)[0-9]{4}-(0[1-9]|1[0-2])$/D', $month)) {
                $result->addError(_t(__CLASS__ . '.MONTH_BOUND_INVALID', 'Enter a valid month in YYYY-MM format.'));
            }
        }
        if ($start && $end && $start > $end) {
            $result->addError(_t(__CLASS__ . '.DATE_RANGE_INVALID', 'The end month must be on or after the start month.'));
        }
    }

    /**
     * Migrates legacy date bounds after the new month columns have been built.
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();
        (new BudgetMonthMigrationService())->migrate();
        DB::prepared_query(
            'UPDATE "' . static::getSchema()->tableName(static::class) . '" SET "Type" = ? WHERE "Type" = ?',
            [BudgetEntryType::FIXED, 'expense']
        );
    }

    /**
     * Returns whether the given member owns this record.
     *
     * @param Member|null $member
     * @return bool
     */
    private function isOwnedBy($member = null): bool
    {
        $member = $this->resolveMember($member);
        return $member !== null && (int)$this->getField(BudgetEntryField::OWNER_ID) === (int)$member->ID;
    }

    /**
     * Resolves the provided or currently authenticated member.
     *
     * @param Member|null $member
     * @return Member|null
     */
    private function resolveMember($member = null): ?Member
    {
        return $member instanceof Member ? $member : Security::getCurrentUser();
    }

    /**
     * Always keeps the owner tied to the authenticated member.
     */
    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $member = Security::getCurrentUser();
        if ($member) {
            $this->setField(BudgetEntryField::OWNER_ID, $member->ID);
        }
    }
}
