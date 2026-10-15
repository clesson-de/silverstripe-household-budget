<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Reports;

use DateTimeImmutable;

/**
 * Complete immutable report payload shared by all presentation layers.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Reports
 */
final readonly class HouseholdBudgetReport
{
    /**
     * Stores the complete calculated report without ORM or renderer objects.
     *
     * @param list<string> $accountLabels
     * @param list<BudgetReportMonth> $months
     * @param array<string, BudgetReportTotals> $years
     * @param array<string, int> $yearMonthCounts
     * @param list<BudgetReportPosition> $positions
     * @param list<BudgetReportCategory> $categories
     * @param array<string, list<BudgetReportPosition>> $regularPositions
     * @param list<BudgetReportPosition> $exceptionalPositions
     * @param list<BudgetReportPosition> $transfers
     */
    public function __construct(
        public BudgetReportPeriod $period,
        public DateTimeImmutable $createdAt,
        public string $locale,
        public array $accountLabels,
        public array $months,
        public array $years,
        public array $yearMonthCounts,
        public BudgetReportTotals $totals,
        public array $positions,
        public array $categories,
        public array $regularPositions,
        public array $exceptionalPositions,
        public array $transfers
    ) {
    }
}
