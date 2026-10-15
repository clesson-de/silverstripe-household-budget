<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Helpers\BudgetMoney;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportCategory;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportEntry;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportMonth;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPosition;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportTotals;
use InvalidArgumentException;

/**
 * Calculates report data from immutable entries without ORM or rendering.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class BudgetPeriodCalculator
{
    private BudgetEntrySchedule $schedule;

    /**
     * Initializes the shared booking recurrence evaluator.
     */
    public function __construct(?BudgetEntrySchedule $schedule = null)
    {
        $this->schedule = $schedule ?? new BudgetEntrySchedule();
    }

    /**
     * Returns complete month, year, position, category, and period summaries.
     *
     * @param list<BudgetReportEntry> $entries
     * @return array{months: list<BudgetReportMonth>, years: array<string, BudgetReportTotals>,
     *     yearMonthCounts: array<string, int>, totals: BudgetReportTotals, positions: list<BudgetReportPosition>,
     *     categories: list<BudgetReportCategory>, regularPositions: array<string, list<BudgetReportPosition>>,
     *     exceptionalPositions: list<BudgetReportPosition>, transfers: list<BudgetReportPosition>}
     */
    public function calculate(array $entries, BudgetReportPeriod $period): array
    {
        $this->validateEntries($entries);
        $positions = $this->positions($entries, $period);
        $months = $this->months($positions, $period);
        [$years, $yearMonthCounts] = $this->years($months);
        $totals = $this->totals($months, $period->monthCount);
        return $this->result($months, $years, $yearMonthCounts, $totals, $positions);
    }

    /**
     * Ensures calculator inputs are snapshots with recognized budget types.
     */
    private function validateEntries(array $entries): void
    {
        $validTypes = BudgetEntryType::values();
        foreach ($entries as $entry) {
            if (!$entry instanceof BudgetReportEntry
                || !in_array($entry->type, $validTypes, true)
                || $entry->amountCents < 1) {
                throw new InvalidArgumentException('Report calculation requires entries with recognized booking types.');
            }
        }
    }

    /**
     * Builds one position for each source entry that occurs in the range.
     *
     * @param list<BudgetReportEntry> $entries
     * @return list<BudgetReportPosition>
     */
    private function positions(array $entries, BudgetReportPeriod $period): array
    {
        $positions = [];
        foreach ($entries as $entry) {
            $position = $this->position($entry, $period);
            if ($position !== null) {
                $positions[] = $position;
            }
        }
        return $positions;
    }

    /**
     * Creates one period position when the entry has any scheduled occurrences.
     */
    private function position(BudgetReportEntry $entry, BudgetReportPeriod $period): ?BudgetReportPosition
    {
        $occurrences = $this->occurrenceMonths($entry, $period);
        if ($occurrences === []) {
            return null;
        }
        $count = count($occurrences);
        return new BudgetReportPosition($entry, $occurrences, $count, $entry->amountCents * $count);
    }

    /**
     * Returns selected months matching recurrence and inclusive booking bounds.
     *
     * @return list<string>
     */
    private function occurrenceMonths(BudgetReportEntry $entry, BudgetReportPeriod $period): array
    {
        return array_values(array_filter(
            $period->months,
            fn (string $month): bool => $this->schedule->occursInMonth(
                implode(',', $entry->months), $entry->startMonth, $entry->endMonth, $month
            )
        ));
    }

    /**
     * Accumulates type values into each selected month, including empty months.
     *
     * @param list<BudgetReportPosition> $positions
     * @return list<BudgetReportMonth>
     */
    private function months(array $positions, BudgetReportPeriod $period): array
    {
        $rows = array_map(fn (string $month): array => [$month, $this->emptyTypeTotals()], $period->months);
        $index = array_flip($period->months);
        foreach ($positions as $position) {
            foreach ($position->occurrenceMonths as $month) {
                $rows[$index[$month]][1][$position->entry->type] += $position->entry->amountCents;
            }
        }
        return array_map(fn (array $row): BudgetReportMonth => $this->monthFromTotals($row[0], $row[1]), $rows);
    }

    /**
     * Creates month-specific headline totals from its per-type amounts.
     *
     * @param array<string, int> $types
     */
    private function monthFromTotals(string $month, array $types): BudgetReportMonth
    {
        $income = $types[BudgetEntryType::INCOME];
        $regular = array_sum(array_map(fn (string $type): int => $types[$type], BudgetEntryType::normalTypes()));
        $exceptional = $types[BudgetEntryType::EXCEPTIONAL];
        $expenses = $regular + $exceptional;
        return new BudgetReportMonth(
            $month, $types, $income, $regular, $exceptional, $expenses,
            $types[BudgetEntryType::TRANSFER], $income - $expenses
        );
    }

    /**
     * Aggregates only selected months into partial or complete calendar years.
     *
     * @param list<BudgetReportMonth> $months
     * @return array{array<string, BudgetReportTotals>, array<string, int>}
     */
    private function years(array $months): array
    {
        $groups = [];
        foreach ($months as $month) {
            $year = substr($month->month, 0, 4);
            $groups[$year][] = $month;
        }
        $yearTotals = [];
        $counts = [];
        foreach ($groups as $year => $rows) {
            $counts[$year] = count($rows);
            $yearTotals[$year] = $this->totals($rows, count($rows));
        }
        return [$yearTotals, $counts];
    }

    /**
     * Sums month totals and derives nearest-cent averages over all months.
     *
     * @param list<BudgetReportMonth> $months
     */
    private function totals(array $months, int $monthCount): BudgetReportTotals
    {
        $types = $this->emptyTypeTotals();
        foreach ($months as $month) {
            foreach ($types as $type => $unused) {
                $types[$type] += $month->typeTotals[$type];
            }
        }
        $headline = $this->monthFromTotals('period', $types);
        return new BudgetReportTotals(
            $types, $headline->incomeCents, $headline->normalCostsCents, $headline->exceptionalCents,
            $headline->expenseCents, $headline->transferCents, $headline->balanceCents,
            BudgetMoney::averageCents($headline->normalCostsCents, $monthCount),
            BudgetMoney::averageCents($headline->expenseCents, $monthCount),
            $monthCount
        );
    }

    /**
     * Organizes positions, orders expense groups, and calculates category shares.
     *
     * @param list<BudgetReportMonth> $months
     * @param array<string, BudgetReportTotals> $years
     * @param array<string, int> $yearMonthCounts
     * @param list<BudgetReportPosition> $positions
     * @return array<string, mixed>
     */
    private function result(
        array $months,
        array $years,
        array $yearMonthCounts,
        BudgetReportTotals $totals,
        array $positions
    ): array {
        $groups = $this->positionGroups($positions);
        return [
            'months' => $months, 'years' => $years, 'yearMonthCounts' => $yearMonthCounts,
            'totals' => $totals, 'positions' => $positions,
            'categories' => $this->categories($totals),
            'regularPositions' => array_intersect_key($groups, array_flip(BudgetEntryType::normalTypes())),
            'exceptionalPositions' => $groups[BudgetEntryType::EXCEPTIONAL],
            'transfers' => $groups[BudgetEntryType::TRANSFER],
        ];
    }

    /**
     * Creates the four expense categories with zero-safe rounded shares.
     *
     * @return list<BudgetReportCategory>
     */
    private function categories(BudgetReportTotals $totals): array
    {
        return array_map(function (string $type) use ($totals): BudgetReportCategory {
            $amount = $totals->typeTotals[$type];
            $share = $totals->expenseCents === 0
                ? 0
                : intdiv(($amount * 10000) + intdiv($totals->expenseCents, 2), $totals->expenseCents);
            return new BudgetReportCategory($type, $amount, $share);
        }, BudgetEntryType::expenseTypes());
    }

    /**
     * Separates regular, exceptional, and neutral-transfer positions.
     *
     * @param list<BudgetReportPosition> $positions
     * @return array<string, mixed>
     */
    private function positionGroups(array $positions): array
    {
        $groups = array_fill_keys([
            ...BudgetEntryType::normalTypes(),
            BudgetEntryType::EXCEPTIONAL,
            BudgetEntryType::TRANSFER,
        ], []);
        foreach ($positions as $position) {
            $groups[$position->entry->type][] = $position;
        }
        foreach (array_keys($groups) as $type) {
            $groups[$type] = $this->sortPositions($groups[$type]);
        }
        return $groups;
    }

    /**
     * Sorts same-category entries by period total, then source ID.
     *
     * @param list<BudgetReportPosition> $positions
     * @return list<BudgetReportPosition>
     */
    private function sortPositions(array $positions): array
    {
        usort($positions, fn (BudgetReportPosition $left, BudgetReportPosition $right): int =>
            $right->totalCents <=> $left->totalCents ?: $left->entry->id <=> $right->entry->id
        );
        return $positions;
    }

    /**
     * Initializes exact integer-cent values for each supported booking type.
     *
     * @return array<string, int>
     */
    private function emptyTypeTotals(): array
    {
        return array_fill_keys(BudgetEntryType::values(), 0);
    }
}
