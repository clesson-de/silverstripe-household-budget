<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastInsight;
use Clesson\Silverstripe\HouseholdBudget\Forecasts\BudgetForecast;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportEntry;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use Clesson\Silverstripe\HouseholdBudget\Reports\HouseholdBudgetReport;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastInsightCalculator;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetPeriodCalculator;
use DateTimeImmutable;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers exact forecast thresholds, cost categories, deficits and empty snapshots.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetForecastInsightCalculatorTest extends SapphireTest
{
    /**
     * Verifies the documented December, June and ordinary-month reference figures.
     */
    public function testAnnualComparisonsAndTransferIsolation(): void
    {
        $entries = $this->referenceEntries();
        $december = $this->forecast($entries, '2026-12');
        $june = $this->forecast($entries, '2026-06');
        $november = $this->forecast($entries, '2026-11');

        self::assertSame(1860000, $december->annualCostsCents);
        self::assertSame(155000, $december->annualAverageCostsCents);
        self::assertSame(260000, $december->report->totals->expenseCents);
        self::assertSame(40000, $december->report->totals->balanceCents);
        self::assertSame(20000, $december->report->totals->transferCents);
        self::assertSame(BudgetForecastInsight::EXPENSIVE, $december->comparison);
        self::assertSame(200000, $june->report->totals->expenseCents);
        self::assertSame(60000, $june->report->totals->exceptionalCents);
        self::assertSame(BudgetForecastInsight::USUAL, $november->comparison);
    }

    /**
     * States an exact deficit and avoids claiming variable costs can cover an impossible gap.
     */
    public function testDeficitAndVariableReviewAmounts(): void
    {
        $covered = $this->forecast([
            $this->entry(1, BudgetEntryType::INCOME, 100000, ['01']),
            $this->entry(2, BudgetEntryType::FIXED, 90000, ['01']),
            $this->entry(3, BudgetEntryType::VARIABLE, 40000, ['01']),
        ], '2026-01');
        $residual = $this->forecast([
            $this->entry(4, BudgetEntryType::INCOME, 100000, ['01']),
            $this->entry(5, BudgetEntryType::FIXED, 120000, ['01']),
            $this->entry(6, BudgetEntryType::VARIABLE, 10000, ['01']),
        ], '2026-01');

        self::assertSame(30000, $covered->deficitCents);
        self::assertSame(30000, $covered->variableReviewCents);
        self::assertSame(0, $covered->remainingDeficitCents);
        self::assertSame(30000, $residual->deficitCents);
        self::assertSame(10000, $residual->variableCostsCents);
        self::assertSame(20000, $residual->remainingDeficitCents);
        self::assertContains(BudgetForecastInsight::VARIABLE_INSUFFICIENT, $residual->insightCodes);
    }

    /**
     * Uses exact unrounded comparisons at inclusive positive and negative ten-percent boundaries.
     */
    public function testExactTenPercentBoundariesAndOneCentBeyond(): void
    {
        $upper = [11000, ...array_fill(0, 10, 9909), 9910];
        $lower = [9000, ...array_fill(0, 10, 10000), 11000];
        self::assertSame(BudgetForecastInsight::EXPENSIVE, $this->monthlyCostForecast($upper)->comparison);
        self::assertSame(BudgetForecastInsight::EXPENSIVE, $this->monthlyCostForecast([11001, ...array_slice($upper, 1)])->comparison);
        self::assertSame(BudgetForecastInsight::CHEAP, $this->monthlyCostForecast($lower)->comparison);
        self::assertSame(BudgetForecastInsight::CHEAP, $this->monthlyCostForecast([8999, ...array_slice($lower, 1)])->comparison);
    }

    /**
     * Keeps empty booking and zero annual-cost cases free of invented comparisons.
     */
    public function testNoBookingsAndZeroAnnualCostsHaveNoRatio(): void
    {
        $empty = $this->forecast([], '2026-03');
        self::assertSame(BudgetForecastInsight::NO_COMPARISON, $empty->comparison);
        self::assertContains(BudgetForecastInsight::NO_BOOKINGS, $empty->insightCodes);
        self::assertNull($empty->comparisonPercentBasisPoints);
        self::assertSame(BudgetForecastInsight::NO_COMPARISON, $this->forecast([
            $this->entry(1, BudgetEntryType::TRANSFER, 5000, ['01']),
        ], '2026-01')->comparison);
    }

    /**
     * Creates the approved twelve-month financial reference in exact cents.
     *
     * @return list<BudgetReportEntry>
     */
    private function referenceEntries(): array
    {
        $months = array_map(fn (int $month): string => str_pad((string)$month, 2, '0', STR_PAD_LEFT), range(1, 12));
        return [
            $this->entry(1, BudgetEntryType::INCOME, 300000, $months),
            $this->entry(2, BudgetEntryType::FIXED, 100000, $months),
            $this->entry(3, BudgetEntryType::VARIABLE, 40000, $months),
            $this->entry(4, BudgetEntryType::PERIODIC, 120000, ['12']),
            $this->entry(5, BudgetEntryType::EXCEPTIONAL, 60000, ['06']),
            $this->entry(6, BudgetEntryType::TRANSFER, 20000, $months),
        ];
    }

    /**
     * Builds a pure annual report and derives its target month from that same snapshot.
     *
     * @param list<BudgetReportEntry> $entries
     */
    private function forecast(array $entries, string $month, string $scope = 'overall'): BudgetForecast
    {
        $calculator = new BudgetPeriodCalculator();
        $year = substr($month, 0, 4);
        $annual = $this->report($calculator, $entries, new BudgetReportPeriod($year . '-01', $year . '-12'));
        $monthEntries = array_map(fn ($position): BudgetReportEntry => $position->entry, $annual->positions);
        $monthly = $this->report($calculator, $monthEntries, new BudgetReportPeriod($month, $month));
        return (new BudgetForecastInsightCalculator())->calculate($annual, $monthly, $scope, 'All own accounts');
    }

    /**
     * Tests an arbitrary exact annual allocation against a selected first month.
     *
     * @param list<int> $monthlyCosts
     */
    private function monthlyCostForecast(array $monthlyCosts): BudgetForecast
    {
        $entries = [];
        foreach ($monthlyCosts as $index => $amount) {
            $month = str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
            $entries[] = $this->entry($index + 1, BudgetEntryType::FIXED, $amount, [$month]);
        }
        return $this->forecast($entries, '2026-01');
    }

    /**
     * Constructs the immutable report value using the shared production calculator.
     *
     * @param list<BudgetReportEntry> $entries
     */
    private function report(
        BudgetPeriodCalculator $calculator,
        array $entries,
        BudgetReportPeriod $period
    ): HouseholdBudgetReport {
        $data = $calculator->calculate($entries, $period);
        return new HouseholdBudgetReport(
            $period,
            new DateTimeImmutable('2026-10-15T10:00:00+02:00'),
            'en_US',
            ['All own accounts'],
            $data['months'],
            $data['years'],
            $data['yearMonthCounts'],
            $data['totals'],
            $data['positions'],
            $data['categories'],
            $data['regularPositions'],
            $data['exceptionalPositions'],
            $data['transfers']
        );
    }

    /**
     * Creates one budget snapshot with the requested recurrence months.
     *
     * @param list<string> $months
     */
    private function entry(int $id, string $type, int $cents, array $months): BudgetReportEntry
    {
        return new BudgetReportEntry($id, 'Position ' . $id, $type, $cents, $months, '', '', 'Account', '');
    }
}
