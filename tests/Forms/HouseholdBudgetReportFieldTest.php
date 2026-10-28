<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Forms;

use Clesson\Silverstripe\HouseholdBudget\Forms\HouseholdBudgetReportField;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetReportService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers report controls, own-account defaults, and empty-account guidance.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class HouseholdBudgetReportFieldTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /**
     * Selects all and only the current member's linked accounts by default.
     */
    public function testOwnAccountControlsDefaultSelected(): void
    {
        $member = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($member);
        self::assertSame(2, (new BudgetReportService())->accountsFor($member)->count());
        $html = HouseholdBudgetReportField::create('Report', $member)->Field();
        self::assertStringContainsString('<select multiple class="household-budget-report__account-select no-change-track"', $html);
        self::assertStringContainsString('name="accountIDs[]"', $html);
        self::assertSame(2, substr_count($html, 'selected="selected"'));
        self::assertStringNotContainsString('type="checkbox"', $html);
        self::assertStringContainsString('type="month"', $html);
        foreach (['startMonth', 'endMonth'] as $name) {
            self::assertStringContainsString(
                'class="form-control no-change-track" type="month" name="' . $name . '"',
                $html
            );
        }
        self::assertStringContainsString('household-budget-report/download', $html);
        self::assertStringNotContainsString('Other bank', $html);
    }

    /**
     * Disables export and explains how an unlinked member can activate the report.
     */
    public function testMemberWithoutAccountsGetsDisabledExport(): void
    {
        $member = $this->objFromFixture(Member::class, 'unlinked');
        Security::setCurrentUser($member);
        $html = HouseholdBudgetReportField::create('Report', $member)->Field();
        self::assertStringContainsString('Link a bank account', $html);
        self::assertStringContainsString('disabled>', $html);
        self::assertStringNotContainsString('name="accountIDs[]"', $html);
    }
}
