<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Helpers\BudgetMoney;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportEntry;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetPeriodCalculator;
use SilverStripe\Dev\SapphireTest;
use InvalidArgumentException;

/**
 * Covers pure month allocation and exact report arithmetic.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetPeriodCalculatorTest extends SapphireTest
{
    /**
     * Matches the approved full-year reference values and neutral transfer rule.
     */
    public function testReferenceYearTotalsAndMonthDistribution(): void
    {
        $entries = [
            $this->entry(1, 'income', '3000.00', range(1, 12)),
            $this->entry(2, 'fixed', '1000.00', range(1, 12)),
            $this->entry(3, 'variable', '400.00', range(1, 12)),
            $this->entry(4, 'periodic', '1200.00', [12]),
            $this->entry(5, 'exceptional', '600.00', [6]),
            $this->entry(6, 'transfer', '200.00', range(1, 12)),
        ];
        $report = (new BudgetPeriodCalculator())->calculate($entries, new BudgetReportPeriod('2026-01', '2026-12'));
        $totals = $report['totals'];
        self::assertSame(3600000, $totals->incomeCents);
        self::assertSame(1800000, $totals->normalCostsCents);
        self::assertSame(1860000, $totals->expenseCents);
        self::assertSame(240000, $totals->transferCents);
        self::assertSame(1740000, $totals->balanceCents);
        self::assertSame(150000, $totals->averageNormalCostsCents);
        self::assertSame(155000, $totals->averageExpenseCents);
        self::assertSame([6452, 2581, 645, 323], array_column($report['categories'], 'shareBasisPoints'));
        self::assertSame(10001, array_sum(array_column($report['categories'], 'shareBasisPoints')));
        self::assertSame(60000, $report['months'][5]->exceptionalCents);
        self::assertSame(120000, $report['months'][11]->typeTotals[BudgetEntryType::PERIODIC]);
    }

    /**
     * Preserves cents, half-up averages, and consistent position/month/category sums.
     */
    public function testExactCentSumsAndRoundedAverages(): void
    {
        $entries = [
            $this->entry(1, 'income', '10.01', [1, 2]),
            $this->entry(2, 'fixed', '1.01', [1]),
            $this->entry(3, 'fixed', '1.01', [1]),
            $this->entry(4, 'exceptional', '0.01', [2]),
        ];
        $data = (new BudgetPeriodCalculator())->calculate($entries, new BudgetReportPeriod('2026-01', '2026-03'));
        self::assertSame(202, $data['totals']->typeTotals[BudgetEntryType::FIXED]);
        self::assertSame(68, $data['totals']->averageExpenseCents);
        self::assertSame(2205, array_sum(array_map(fn ($row) => $row->totalCents, $data['positions'])));
        self::assertSame(
            $data['totals']->expenseCents,
            array_sum(array_map(fn ($category) => $category->amountCents, $data['categories']))
        );
        self::assertCount(3, $data['months']);
        self::assertSame(0, $data['months'][2]->expenseCents);
    }

    /**
     * Keeps repeated descriptions separate and leaves transfer-only periods cost-free.
     */
    public function testDuplicateDescriptionsAndTransferOnlyPeriod(): void
    {
        $entries = [
            $this->entry(1, 'transfer', '5.00', [1], 'Same'),
            $this->entry(2, 'transfer', '5.00', [1], 'Same'),
        ];
        $data = (new BudgetPeriodCalculator())->calculate($entries, new BudgetReportPeriod('2026-01', '2026-01'));
        self::assertCount(2, $data['positions']);
        self::assertSame(1000, $data['totals']->transferCents);
        self::assertSame(0, $data['totals']->expenseCents);
        self::assertSame(0, $data['totals']->averageNormalCostsCents);
        self::assertSame([0, 0, 0, 0], array_column($data['categories'], 'shareBasisPoints'));
    }

    /**
     * Calculates inclusive selected months across a year boundary.
     */
    public function testInclusiveBoundsAcrossYears(): void
    {
        $entry = $this->entry(1, 'fixed', '4.00', range(1, 12), 'Rent', '2026-11', '2027-02');
        $data = (new BudgetPeriodCalculator())->calculate(
            [$entry],
            new BudgetReportPeriod('2026-10', '2027-03')
        );
        self::assertSame(['2026-11', '2026-12', '2027-01', '2027-02'], $data['positions'][0]->occurrenceMonths);
        self::assertSame(800, $data['years']['2026']->expenseCents);
        self::assertSame(800, $data['years']['2027']->expenseCents);
        self::assertSame(['2026' => 3, '2027' => 3], $data['yearMonthCounts']);
    }

    /**
     * Keeps exceptional-only needs separate and supports signed deficits.
     */
    public function testExceptionalOnlyAndNegativeBalance(): void
    {
        $entries = [
            $this->entry(1, 'income', '1.00', [1]),
            $this->entry(2, 'exceptional', '2.00', [1]),
        ];
        $data = (new BudgetPeriodCalculator())->calculate($entries, new BudgetReportPeriod('2026-01', '2026-01'));
        self::assertSame(0, $data['totals']->normalCostsCents);
        self::assertSame(200, $data['totals']->averageExpenseCents);
        self::assertSame(-100, $data['totals']->balanceCents);
        self::assertSame(10000, $data['categories'][3]->shareBasisPoints);
    }

    /**
     * Represents an empty range with zero totals and zero-safe category shares.
     */
    public function testEmptyReportHasZeroTotals(): void
    {
        $data = (new BudgetPeriodCalculator())->calculate([], new BudgetReportPeriod('2026-04', '2026-06'));
        self::assertSame(0, $data['totals']->incomeCents);
        self::assertSame(0, $data['totals']->averageExpenseCents);
        self::assertSame([0, 0, 0, 0], array_column($data['categories'], 'shareBasisPoints'));
        self::assertSame([0, 0, 0], array_map(fn ($month) => $month->balanceCents, $data['months']));
    }

    /**
     * Includes a full 120-month period and rejects unsupported currency precision.
     */
    public function testMaximumPeriodAndMoneyPrecision(): void
    {
        $period = new BudgetReportPeriod('2000-01', '2009-12');
        self::assertSame(120, $period->monthCount);
        self::assertSame('2026-01', (new BudgetReportPeriod('2026-01', '2026-01'))->months[0]);
        $this->expectException(InvalidArgumentException::class);
        BudgetMoney::toCents('12.345');
    }

    /**
     * Creates a renderer-independent input fixture.
     *
     * @param list<int> $months
     */
    private function entry(
        int $id,
        string $type,
        string $amount,
        array $months,
        string $description = 'Booking',
        string $start = '',
        string $end = ''
    ): BudgetReportEntry {
        return new BudgetReportEntry(
            $id, $description, $type, BudgetMoney::toCents($amount),
            array_map(fn (int $month): string => sprintf('%02d', $month), $months),
            $start, $end, 'Account', ''
        );
    }

    /**
     * Rejects malformed, reversed, and overlong report periods.
     */
    public function testPeriodValidation(): void
    {
        foreach ([['2026-13', '2026-12'], ['2026-03', '2026-02'], ['2020-01', '2030-01']] as $range) {
            try {
                new BudgetReportPeriod($range[0], $range[1], 120);
                self::fail('The invalid report period was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
