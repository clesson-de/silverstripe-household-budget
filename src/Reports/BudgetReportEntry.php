<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Reports;

/**
 * Immutable renderer-independent snapshot of one budget booking.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Reports
 */
final readonly class BudgetReportEntry
{
    /**
     * Captures the report-relevant values without retaining an ORM object.
     *
     * @param list<string> $months
     */
    public function __construct(
        public int $id,
        public string $description,
        public string $type,
        public int $amountCents,
        public array $months,
        public string $startMonth,
        public string $endMonth,
        public string $account,
        public string $counterparty
    ) {
    }
}
