<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Models;

use Clesson\Silverstripe\Contacts\Models\Person;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\View\SSViewer;
use SilverStripe\Forms\NumericField;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DB;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetMonth;
use Clesson\Silverstripe\HouseholdBudget\Services\MonthlyBudgetService;
use Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController;

/**
 * Covers account selection through the member's inverse contact relation.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetEntryTest extends SapphireTest
{
    protected static $fixture_file = 'BudgetEntryTest.yml';

    /** Upgrades legacy expense records without altering their amounts or owner. */
    public function testLegacyExpenseMigration(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $entry = BudgetEntry::create([
            'Description' => 'Legacy', 'Amount' => '170.61', 'Type' => 'fixed', 'Months' => '01',
            'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
        ]);
        $entry->write();
        DB::prepared_query(
            'UPDATE "HouseholdBudget_BudgetEntry" SET "Type" = ? WHERE "ID" = ?', ['expense', $entry->ID]
        );
        BudgetEntry::create()->requireDefaultRecords();
        $saved = BudgetEntry::get()->byID($entry->ID);
        $this->assertSame('fixed', $saved->Type);
        $this->assertSame('170.61', (string)$saved->Amount);
        $this->assertSame($entry->OwnerID, $saved->OwnerID);
        $saved->Type = 'expense';
        $this->assertFalse($saved->validate()->isValid());
    }
    /** Preserves cents from localized input through saving and reloading the entry. */
    public function testLocalizedAmountPersistence(): void
    {
        i18n::set_locale('de_DE');
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $entry = BudgetEntry::create([
            'Description' => 'Rent',
            'Type' => 'fixed',
            'Months' => '01',
            'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
        ]);
        $field = $entry->getCMSFields()->dataFieldByName('Amount');
        $this->assertInstanceOf(NumericField::class, $field);
        $this->assertSame(2, $field->getScale());
        $this->assertSame('0.01', $field->getAttributes()['step']);
        $field->setSubmittedValue('170,61');
        $this->assertTrue($field->validate()->isValid());
        $field->saveInto($entry);
        $entry->write();
        $this->assertSame('170.61', (string)BudgetEntry::get()->byID($entry->ID)->Amount);
    }

    /** Shows both own accounts and excludes other members' accounts. */
    public function testAccountOptions(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $field = BudgetEntry::create()->getCMSFields()->dataFieldByName('AccountID');
        $source = $field->getSource();
        $this->assertArrayHasKey($this->idFromFixture(BankAccount::class, 'first'), $source);
        $this->assertArrayHasKey($this->idFromFixture(BankAccount::class, 'second'), $source);
        $this->assertArrayNotHasKey($this->idFromFixture(BankAccount::class, 'other'), $source);
        $this->assertArrayNotHasKey($this->idFromFixture(BankAccount::class, 'unassigned'), $source);
    }

    /** Accepts an owner's bank account and rejects a foreign account. */
    public function testAccountValidation(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $entry = BudgetEntry::create([
            'Description' => 'Rent',
            'Amount' => '100.00',
            'Type' => 'fixed',
            'Months' => '01,10',
            'ContactID' => $this->idFromFixture(Person::class, 'other'),
            'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
        ]);
        $this->assertTrue($entry->validate()->isValid());
        $entry->ContactID = 0;
        $this->assertTrue($entry->validate()->isValid());
        $entry->write();
        $this->assertSame(0, (int)BudgetEntry::get()->byID($entry->ID)->ContactID);
        $entry->AccountID = $this->idFromFixture(BankAccount::class, 'other');
        $this->assertFalse($entry->validate()->isValid());
    }

    /** Does not expose unassigned accounts to a member without a contact. */
    public function testUnlinkedMemberHasNoAccounts(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'unlinked'));
        $entry = BudgetEntry::create();
        $source = $entry->getCMSFields()->dataFieldByName('AccountID')->getSource();
        $this->assertArrayNotHasKey($this->idFromFixture(BankAccount::class, 'unassigned'), $source);
        $entry->AccountID = $this->idFromFixture(BankAccount::class, 'unassigned');
        $this->assertFalse($entry->validate()->isValid());
    }

    /** Renders all twelve months in a three-column grid without changing their values. */
    public function testMonthCheckboxLayout(): void
    {
        SSViewer::set_themes(['silverstripe/admin:cms-forms', '$default']);
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $field = BudgetEntry::create()->getCMSFields()->dataFieldByName('Months');
        $this->assertCount(12, $field->getSource());
        $html = $field->Field()->forTemplate();
        $this->assertStringContainsString('household-budget-months', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringStartsWith('<div ', $html);
        $this->assertSame(12, substr_count($html, 'type="checkbox"'));
    }

    /** Saves and restores all twelve selected months using the real checkbox field. */
    public function testAllMonthsCanBeSaved(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $entry = BudgetEntry::create([
            'Description' => 'Monthly rent',
            'Amount' => '100.00',
            'Type' => 'fixed',
            'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
        ]);
        $field = $entry->getCMSFields()->dataFieldByName('Months');
        $months = array_map('strval', array_keys(BudgetMonth::options()));
        $field->setSubmittedValue(array_combine($months, $months));
        $field->saveInto($entry);
        $this->assertSame(implode(',', $months), $entry->Months);
        $this->assertSame(35, strlen($entry->Months));
        $this->assertTrue($entry->validate()->isValid());
        $entry->write();
        $field->loadFrom(BudgetEntry::get()->byID($entry->ID));
        $this->assertSame($months, $field->getValueArray());
    }

    /** Charts exact monthly values, including negative balances and date bounds. */
    public function testChartValues(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($owner);
        $entry = BudgetEntry::create([
            'Description' => 'Expense', 'Amount' => '100.25', 'Type' => 'fixed',
            'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
            'Months' => '01,10,11,12', 'StartMonth' => '2026-10', 'EndMonth' => '2026-11',
        ]);
        $entry->write();
        $data = (new MonthlyBudgetService())->chartDataFor($owner, 2026);
        $this->assertCount(12, $data['labels']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 100.25, 100.25, 0.0], $data['datasets'][1]['data']);
        $this->assertSame(-100.25, $data['datasets'][2]['data'][9]);
        $this->assertSame(['#28a745', '#808080', '#ffc107', '#2093e5'], array_column($data['datasets'], 'backgroundColor'));
        $other = $this->objFromFixture(Member::class, 'other');
        $this->assertSame(array_fill(0, 12, 0.0), (new MonthlyBudgetService())->chartDataFor($other, 2026)['datasets'][1]['data']);
    }

    /** Restricts monthly totals to the chosen accounts while retaining owner isolation. */
    public function testChartAccountSelection(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($owner);
        foreach (['first' => '100.25', 'second' => '200.50'] as $account => $amount) {
            BudgetEntry::create([
                'Description' => 'Income', 'Amount' => $amount, 'Type' => 'income', 'Months' => '01',
                'AccountID' => $this->idFromFixture(BankAccount::class, $account),
            ])->write();
        }
        $service = new MonthlyBudgetService();
        $selected = [$this->idFromFixture(BankAccount::class, 'first')];
        $this->assertSame(100.25, $service->chartDataFor($owner, 2026, $selected)['datasets'][0]['data'][0]);
        $this->assertSame(300.75, $service->chartDataFor($owner, 2026)['datasets'][0]['data'][0]);
        $this->assertSame(0.0, $service->chartDataFor($owner, 2026, [])['datasets'][0]['data'][0]);
    }

    /** Renders exactly the owner's two bank account toggles, initially active. */
    public function testChartAccountButtons(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $controller = MonthlyBudgetController::create();
        $html = $controller->chart()->forTemplate();
        $this->assertSame(2, substr_count($html, 'aria-pressed="true"'));
        $this->assertStringContainsString('First bank', $html);
        $this->assertStringContainsString('Second bank', $html);
        $this->assertStringNotContainsString('Other bank', $html);
        $this->assertStringNotContainsString('Unassigned bank', $html);
        $summary = $controller->summary()->forTemplate();
        $this->assertSame(2, substr_count($summary, 'aria-pressed="true"'));
        $this->assertSame(12, substr_count($summary, 'data-budget-series="0"'));
        $this->assertStringNotContainsString('Other bank', $summary);
    }
}
