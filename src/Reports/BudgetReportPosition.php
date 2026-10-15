<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Reports;

/**
 * Immutable booking snapshot with its occurrences in the selected period.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Reports
 */
final readonly class BudgetReportPosition
{
    /**
     * Stores each matching month separately so duplicate labels remain distinct.
     *
     * @param list<string> $occurrenceMonths
     */
    public function __construct(
        public BudgetReportEntry $entry,
        public array $occurrenceMonths,
        public int $occurrenceCount,
        public int $totalCents
    ) {
    }
}
