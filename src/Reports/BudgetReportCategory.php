<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Reports;

/**
 * Immutable cost category total with a precomputed share in basis points.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Reports
 */
final readonly class BudgetReportCategory
{
    /**
     * Stores a share rounded to the nearest one-hundredth of a percent.
     */
    public function __construct(
        public string $type,
        public int $amountCents,
        public int $shareBasisPoints
    ) {
    }
}
