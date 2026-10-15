<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Reports;

use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use InvalidArgumentException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers inclusive report ranges and configurable month limits.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetReportPeriodTest extends SapphireTest
{
    /**
     * Enumerates every month in inclusive ranges through the 120-month maximum.
     */
    public function testInclusiveRangeBoundaries(): void
    {
        $oneMonth = new BudgetReportPeriod('2026-01', '2026-01');
        $year = new BudgetReportPeriod('2026-01', '2026-12');
        $maximum = new BudgetReportPeriod('2000-01', '2009-12');
        self::assertSame(['2026-01'], $oneMonth->months);
        self::assertCount(12, $year->months);
        self::assertSame(120, $maximum->monthCount);
    }

    /**
     * Rejects invalid month syntax, reversed ranges, and ranges beyond the limit.
     */
    public function testInvalidPeriodsAreRejected(): void
    {
        foreach ([['2026-00', '2026-12'], ['2026-03', '2026-02'], ['2020-01', '2030-01']] as $range) {
            $this->assertInvalidRange($range[0], $range[1]);
        }
    }

    /**
     * Reads the YAML-overridable maximum used by the download controller.
     */
    public function testConfiguredMaximumCanBeOverridden(): void
    {
        Config::modify()->set(BudgetReportPeriod::class, 'maximum_months', 24);
        self::assertSame(24, BudgetReportPeriod::maximumMonths());
    }

    /**
     * Asserts construction fails for one invalid pair of month values.
     */
    private function assertInvalidRange(string $start, string $end): void
    {
        try {
            new BudgetReportPeriod($start, $end);
            self::fail('The invalid report period was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
