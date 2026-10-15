<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastInsight;
use Clesson\Silverstripe\HouseholdBudget\Forecasts\BudgetForecast;
use Clesson\Silverstripe\HouseholdBudget\Helpers\BudgetMoney;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportMonth;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPosition;
use Clesson\Silverstripe\HouseholdBudget\Reports\HouseholdBudgetReport;
use InvalidArgumentException;

/**
 * Derives transparent forecast comparisons and review cues from existing report totals.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class BudgetForecastInsightCalculator
{
    /**
     * Creates analysis using integer-cent comparisons and an unrounded threshold.
     */
    public function calculate(
        HouseholdBudgetReport $annualReport,
        HouseholdBudgetReport $monthReport,
        string $scope,
        string $accountLabel,
        int $thresholdPercent = 10
    ): BudgetForecast {
        $month = $this->forecastMonth($annualReport, $monthReport, $thresholdPercent);
        $annualCosts = $annualReport->totals->expenseCents;
        $comparison = $this->comparison($month, $annualCosts, $thresholdPercent, $monthReport);
        return $this->forecast($monthReport, $scope, $accountLabel, $annualCosts, $comparison);
    }

    /**
     * Requires an exact one-month report contained in a complete target-year report.
     */
    private function forecastMonth(
        HouseholdBudgetReport $annual,
        HouseholdBudgetReport $month,
        int $thresholdPercent
    ): BudgetReportMonth {
        $targetMonth = $month->period->startMonth;
        $targetYear = substr($targetMonth, 0, 4);
        if ($thresholdPercent < 0 || $thresholdPercent > 100 || $month->period->monthCount !== 1
            || $month->period->endMonth !== $targetMonth
            || $annual->period->startMonth !== $targetYear . '-01'
            || $annual->period->endMonth !== $targetYear . '-12'
            || count($month->months) !== 1) {
            throw new InvalidArgumentException('Forecast analysis requires a single month and its complete calendar year.');
        }
        return $month->months[0];
    }

    /**
     * Compares full costs against all twelve target-year months without rounding first.
     *
     * @return array{int, int, ?int, string}
     */
    private function comparison(
        BudgetReportMonth $month,
        int $annualCosts,
        int $thresholdPercent,
        HouseholdBudgetReport $monthReport
    ): array {
        if ($annualCosts === 0 || $monthReport->positions === []) {
            return [$annualCosts, 0, null, BudgetForecastInsight::NO_COMPARISON];
        }
        $deviation = ($month->expenseCents * 12) - $annualCosts;
        $category = $this->comparisonCategory($deviation, $annualCosts, $thresholdPercent);
        return [$annualCosts, $deviation, $this->basisPoints($deviation, $annualCosts), $category];
    }

    /**
     * Applies the configured boundary against exact annual and target-month sums.
     */
    private function comparisonCategory(int $deviation, int $annualCosts, int $threshold): string
    {
        if ($deviation > 0 && $deviation * 100 >= $annualCosts * $threshold) {
            return BudgetForecastInsight::EXPENSIVE;
        }
        if ($deviation < 0 && $deviation * 100 <= -($annualCosts * $threshold)) {
            return BudgetForecastInsight::CHEAP;
        }
        return BudgetForecastInsight::USUAL;
    }

    /**
     * Converts an exact ratio to display-only basis points, rounding only for presentation.
     */
    private function basisPoints(int $deviation, int $annualCosts): int
    {
        $absolute = intdiv((abs($deviation) * 10000) + intdiv($annualCosts, 2), $annualCosts);
        return $deviation < 0 ? -$absolute : $absolute;
    }

    /**
     * Builds the final immutable DTO and deterministic, non-prescriptive insight codes.
     *
     * @param array{int, int, ?int, string} $comparison
     */
    private function forecast(
        HouseholdBudgetReport $report,
        string $scope,
        string $accountLabel,
        int $annualCosts,
        array $comparison
    ): BudgetForecast {
        $month = $report->months[0];
        $variableCosts = $month->typeTotals[BudgetEntryType::VARIABLE];
        $deficit = max(0, -$month->balanceCents);
        $codes = $this->insightCodes($report, $month, $comparison[3], $deficit, $variableCosts);
        return new BudgetForecast(
            $report,
            $scope,
            $accountLabel,
            $annualCosts,
            BudgetMoney::averageCents($annualCosts, 12),
            $comparison[1],
            $comparison[2],
            $comparison[3],
            $deficit,
            $variableCosts,
            min($deficit, $variableCosts),
            max(0, $deficit - $variableCosts),
            $this->topExpenses($report->positions),
            $this->limit($report->regularPositions[BudgetEntryType::VARIABLE] ?? []),
            $this->limit($report->regularPositions[BudgetEntryType::PERIODIC] ?? []),
            $this->limit($report->exceptionalPositions),
            $this->limit($report->transfers),
            $codes
        );
    }

    /**
     * Collects only applicable factual classifications for this report.
     *
     * @return list<string>
     */
    private function insightCodes(
        HouseholdBudgetReport $report,
        BudgetReportMonth $month,
        string $comparison,
        int $deficit,
        int $variableCosts
    ): array {
        $codes = $report->positions === [] ? [BudgetForecastInsight::NO_BOOKINGS] : [$comparison];
        $codes[] = $month->balanceCents < 0
            ? BudgetForecastInsight::DEFICIT
            : ($month->balanceCents > 0 ? BudgetForecastInsight::SURPLUS : BudgetForecastInsight::BALANCED);
        if ($deficit > 0 && $variableCosts > 0) {
            $codes[] = BudgetForecastInsight::VARIABLE_CHECK;
            if ($variableCosts < $deficit) {
                $codes[] = BudgetForecastInsight::VARIABLE_INSUFFICIENT;
            }
        }
        if ($month->transferCents > 0) {
            $codes[] = BudgetForecastInsight::TRANSFERS_NEUTRAL;
        }
        return $codes;
    }

    /**
     * Selects the five largest expense bookings without merging equal labels.
     *
     * @param list<BudgetReportPosition> $positions
     * @return list<BudgetReportPosition>
     */
    private function topExpenses(array $positions): array
    {
        return $this->limit(array_values(array_filter(
            $positions,
            fn (BudgetReportPosition $position): bool => in_array(
                $position->entry->type,
                BudgetEntryType::expenseTypes(),
                true
            )
        )));
    }

    /**
     * Sorts a display group by amount and stable source ID, retaining at most five rows.
     *
     * @param list<BudgetReportPosition> $positions
     * @return list<BudgetReportPosition>
     */
    private function limit(array $positions): array
    {
        usort($positions, fn (BudgetReportPosition $left, BudgetReportPosition $right): int =>
            $right->totalCents <=> $left->totalCents ?: $left->entry->id <=> $right->entry->id
        );
        return array_slice($positions, 0, 5);
    }
}
