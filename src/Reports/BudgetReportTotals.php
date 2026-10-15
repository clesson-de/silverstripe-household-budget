<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Reports;

/**
 * Immutable exact period totals and calendar-month averages.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Reports
 */
final readonly class BudgetReportTotals
{
    /**
     * Stores all category sums plus the report's balance and average metrics.
     *
     * @param array<string, int> $typeTotals
     */
    public function __construct(
        public array $typeTotals,
        public int $incomeCents,
        public int $normalCostsCents,
        public int $exceptionalCents,
        public int $expenseCents,
        public int $transferCents,
        public int $balanceCents,
        public int $averageNormalCostsCents,
        public int $averageExpenseCents,
        public int $monthCount
    ) {
    }
}
