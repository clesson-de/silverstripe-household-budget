<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forecasts;

use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPosition;
use Clesson\Silverstripe\HouseholdBudget\Reports\HouseholdBudgetReport;

/**
 * Immutable analysis for one account scope and one forecast month.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forecasts
 */
final readonly class BudgetForecast
{
    /**
     * Stores precomputed values and source report snapshots without ORM objects.
     *
     * @param list<BudgetReportPosition> $topExpenses
     * @param list<BudgetReportPosition> $variablePositions
     * @param list<BudgetReportPosition> $periodicPositions
     * @param list<BudgetReportPosition> $exceptionalPositions
     * @param list<BudgetReportPosition> $transfers
     * @param list<string> $insightCodes
     */
    public function __construct(
        public HouseholdBudgetReport $report,
        public string $scope,
        public string $accountLabel,
        public int $annualCostsCents,
        public int $annualAverageCostsCents,
        public int $comparisonDeviationCents,
        public ?int $comparisonPercentBasisPoints,
        public string $comparison,
        public int $deficitCents,
        public int $variableCostsCents,
        public int $variableReviewCents,
        public int $remainingDeficitCents,
        public array $topExpenses,
        public array $variablePositions,
        public array $periodicPositions,
        public array $exceptionalPositions,
        public array $transfers,
        public array $insightCodes
    ) {
    }
}
