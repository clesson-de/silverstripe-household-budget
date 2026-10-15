<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Models;

use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\HouseholdBudget\Services\MonthlyBudgetService;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetMonthMigrationService;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers native month inputs, validation, persistence and inclusive bounds.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetMonthBoundsTest extends SapphireTest
{
    protected static $fixture_file = 'BudgetEntryTest.yml';

    /** Builds a valid booking for month-bound tests. */
    private function entry(): BudgetEntry
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        return BudgetEntry::create([
            'Description' => 'Income', 'Amount' => '170.61', 'Type' => 'income',
            'Months' => implode(',', array_map(fn (int $month): string => sprintf('%02d', $month), range(1, 12))),
            'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
        ]);
    }

    /** Uses native month inputs and persists their YYYY-MM submissions. */
    public function testMonthInputs(): void
    {
        $entry = $this->entry();
        $fields = $entry->getCMSFields();
        $this->assertNull($fields->dataFieldByName('StartDate'));
        $this->assertNull($fields->dataFieldByName('EndDate'));
        foreach (['StartMonth' => '2002-05', 'EndMonth' => '2012-08'] as $name => $value) {
            $field = $fields->dataFieldByName($name);
            $this->assertInstanceOf(TextField::class, $field);
            $this->assertSame('month', $field->getAttributes()['type']);
            $field->setSubmittedValue($value);
            $field->saveInto($entry);
        }
        $entry->write();
        $saved = BudgetEntry::get()->byID($entry->ID);
        $this->assertSame('2002-05', $saved->StartMonth);
        $this->assertSame('2012-08', $saved->EndMonth);
    }

    /** Accepts optional bounds and rejects malformed months or reversed ranges. */
    public function testValidation(): void
    {
        $entry = $this->entry();
        $this->assertTrue($entry->validate()->isValid());
        foreach (['2026-00', '2026-13', '0000-01', '26-01', '2026-01-01', 'invalid'] as $value) {
            $entry->StartMonth = $value;
            $this->assertFalse($entry->validate()->isValid(), $value);
        }
        $entry->StartMonth = '2026-05';
        $entry->EndMonth = '2026-04';
        $this->assertFalse($entry->validate()->isValid());
        $entry->EndMonth = '2026-05';
        $this->assertTrue($entry->validate()->isValid());
    }

    /** Includes both bounds and handles start-only and end-only periods. */
    public function testInclusiveBounds(): void
    {
        $entry = $this->entry();
        $member = $this->objFromFixture(Member::class, 'owner');
        $service = new MonthlyBudgetService();
        foreach ([['2026-05', '2026-08', 5, 8], ['2026-05', '', 5, 12], ['', '2026-08', 1, 8]] as $case) {
            [$entry->StartMonth, $entry->EndMonth, $first, $last] = $case;
            $entry->write();
            $values = $service->chartDataFor($member, 2026)['datasets'][0]['data'];
            foreach (range(1, 12) as $month) {
                $this->assertSame($month >= $first && $month <= $last ? 170.61 : 0.0, $values[$month - 1]);
            }
        }
    }

    /** Migrates existing dates once without overwriting or restoring month bounds. */
    public function testLegacyMigration(): void
    {
        $entry = $this->entry();
        $entry->StartMonth = '2026-06';
        $entry->write();
        $table = BudgetEntry::getSchema()->tableName(BudgetEntry::class);
        DB::get_schema()->schemaUpdate(function () use ($table): void {
            DB::require_field($table, 'StartDate', 'Date');
            DB::require_field($table, 'EndDate', 'Date');
        });
        DB::prepared_query(
            'UPDATE "' . $table . '" SET "StartDate" = ?, "EndDate" = ? WHERE "ID" = ?',
            ['2026-05-23', '2026-08-15', $entry->ID]
        );
        (new BudgetMonthMigrationService())->migrate();
        $saved = BudgetEntry::get()->byID($entry->ID);
        $this->assertSame('2026-06', $saved->StartMonth);
        $this->assertSame('2026-08', $saved->EndMonth);
        $saved->EndMonth = '';
        $saved->write();
        (new BudgetMonthMigrationService())->migrate();
        $this->assertEmpty(BudgetEntry::get()->byID($entry->ID)->EndMonth);
    }
}
