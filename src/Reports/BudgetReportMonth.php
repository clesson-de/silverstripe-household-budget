<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Reports;

/**
 * Immutable monthly totals derived from booking snapshots.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Reports
 */
final readonly class BudgetReportMonth
{
    /**
     * Stores every booking type alongside the headline monthly values.
     *
     * @param array<string, int> $typeTotals
     */
    public function __construct(
        public string $month,
        public array $typeTotals,
        public int $incomeCents,
        public int $normalCostsCents,
        public int $exceptionalCents,
        public int $expenseCents,
        public int $transferCents,
        public int $balanceCents
    ) {
    }
}
