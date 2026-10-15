<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Forms;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Forms\HouseholdBudgetForecastSettingsField;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastAccountSetting;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use InvalidArgumentException;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers persistent account settings, owner scope and read-only rendering behavior.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class HouseholdBudgetForecastSettingsFieldTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /**
     * Accepts an absent field in real clear-missing submissions only without linked accounts.
     */
    public function testAbsentSelectionWithoutAccountsCanSaveAndDisableForecasts(): void
    {
        $owner = $this->objFromFixture(Member::class, 'unlinked');
        Security::setCurrentUser($owner);
        $budget = HouseholdBudget::currentRecord();
        $budget->ForecastEmailsEnabled = true;
        $budget->write();
        $field = HouseholdBudgetForecastSettingsField::create('ForecastSettings', $budget);
        self::assertStringNotContainsString('name="ForecastSettings[', (string)$field->FieldHolder());
        $form = Form::create(Controller::create(), 'ForecastForm', FieldList::create(
            CheckboxField::create(BudgetForecastField::FORECAST_EMAILS_ENABLED),
            $field
        ), FieldList::create());
        $form->loadDataFrom([], Form::MERGE_CLEAR_MISSING);
        self::assertNull($field->getValue());
        self::assertTrue($form->validate()->isValid());
        $form->saveInto($budget);
        $budget->write();
        self::assertFalse((bool)$budget->ForecastEmailsEnabled);
        self::assertSame(0, BudgetForecastAccountSetting::get()->count());
        $field->setValue(['AccountIDs' => [(string)$this->idFromFixture(BankAccount::class, 'other')]]);
        self::assertFalse($field->validate()->isValid());
    }

    /**
     * Does not treat omission as a valid empty selection when accounts are linked.
     */
    public function testAbsentSelectionWithLinkedAccountsIsRejected(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $field = HouseholdBudgetForecastSettingsField::create('ForecastSettings', HouseholdBudget::currentRecord());
        $form = Form::create(Controller::create(), 'ForecastForm', FieldList::create($field), FieldList::create());
        $form->loadDataFrom([], Form::MERGE_CLEAR_MISSING);
        self::assertFalse($form->validate()->isValid());
    }

    /**
     * Renders all own accounts without writing rows and persists checked values on save.
     */
    public function testReadDoesNotCreateSettingsAndSavePersistsSelection(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($owner);
        $budget = HouseholdBudget::currentRecord();
        $accounts = [
            $this->idFromFixture(BankAccount::class, 'first'),
            $this->idFromFixture(BankAccount::class, 'second'),
        ];
        $field = HouseholdBudgetForecastSettingsField::create('ForecastSettings', $budget);

        self::assertSame(0, BudgetForecastAccountSetting::get()->count());
        self::assertStringContainsString('Only your member email address is used', (string)$field->FieldHolder());
        self::assertSame(0, BudgetForecastAccountSetting::get()->count());
        $fields = FieldList::create(
            CheckboxField::create(BudgetForecastField::FORECAST_EMAILS_ENABLED, 'Forecast emails'),
            CheckboxField::create(BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED, 'Combined forecast'),
            $field
        );
        $form = Form::create(Controller::create(), 'ForecastSettingsForm', $fields, FieldList::create());
        $form->loadDataFrom([
            BudgetForecastField::FORECAST_EMAILS_ENABLED => '1',
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED => '0',
            'ForecastSettings' => [
                'AccountIDs' => array_map('strval', $accounts),
                'EnabledIDs' => [(string)$accounts[1]],
            ],
        ]);
        self::assertTrue($form->validate()->isValid());
        $form->saveInto($budget);
        $budget->write();
        self::assertTrue((bool)$budget->ForecastEmailsEnabled);
        self::assertFalse((bool)$budget->CombinedForecastEmailEnabled);

        self::assertFalse((bool)BudgetForecastAccountSetting::get()->filter([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $accounts[0],
        ])->first()->Enabled);
        self::assertTrue((bool)BudgetForecastAccountSetting::get()->filter([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $accounts[1],
        ])->first()->Enabled);
        $offForm = Form::create(Controller::create(), 'ForecastOffForm', FieldList::create(
            CheckboxField::create(BudgetForecastField::FORECAST_EMAILS_ENABLED, 'Forecast emails'),
            CheckboxField::create(BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED, 'Combined forecast'),
            HouseholdBudgetForecastSettingsField::create('ForecastSettings', $budget)
        ), FieldList::create());
        $offForm->loadDataFrom([
            BudgetForecastField::FORECAST_EMAILS_ENABLED => '0',
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED => '0',
            'ForecastSettings' => [
                'AccountIDs' => array_map('strval', $accounts),
                'EnabledIDs' => [(string)$accounts[1]],
            ],
        ]);
        self::assertTrue($offForm->validate()->isValid());
        $offForm->saveInto($budget);
        $budget->write();
        self::assertFalse((bool)$budget->ForecastEmailsEnabled);
        self::assertTrue((bool)BudgetForecastAccountSetting::get()->filter([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $accounts[1],
        ])->first()->Enabled);
    }

    /**
     * Rejects a forged foreign account ID rather than ignoring it.
     */
    public function testForeignAccountIDsAreRejected(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($owner);
        $budget = HouseholdBudget::currentRecord();
        $field = HouseholdBudgetForecastSettingsField::create('ForecastSettings', $budget);
        $field->setValue([
            'AccountIDs' => [
                (string)$this->idFromFixture(BankAccount::class, 'first'),
                (string)$this->idFromFixture(BankAccount::class, 'other'),
            ],
            'EnabledIDs' => [],
        ]);
        self::assertFalse($field->validate()->isValid());
        $this->expectException(InvalidArgumentException::class);
        $field->saveInto($budget);
    }
}
