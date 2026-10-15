<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\HouseholdBudget\Forecasts\BudgetForecast;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\Member;
use InvalidArgumentException;

/**
 * Builds forecast reports from one target-year snapshot and its derived month.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class BudgetForecastService
{
    /**
     * Wires the existing report service and pure insight calculator.
     */
    public function __construct(
        private readonly BudgetReportService $reportService,
        private readonly BudgetForecastInsightCalculator $calculator
    ) {
    }

    /**
     * Creates one forecast without re-reading bookings between its annual and monthly reports.
     *
     * @param list<int> $accountIDs
     */
    public function create(
        Member $member,
        array $accountIDs,
        string $targetMonth,
        string $scope,
        string $accountLabel
    ): BudgetForecast {
        $year = substr($targetMonth, 0, 4);
        $annualPeriod = new BudgetReportPeriod($year . '-01', $year . '-12');
        $annual = $this->reportService->create($member, $annualPeriod, $accountIDs);
        $month = $this->reportService->createContainedPeriod(
            $annual,
            new BudgetReportPeriod($targetMonth, $targetMonth)
        );
        return $this->calculator->calculate(
            $annual,
            $month,
            $scope,
            $accountLabel,
            $this->thresholdPercent()
        );
    }

    /**
     * Returns a safe configured integer threshold.
     */
    private function thresholdPercent(): int
    {
        $threshold = Config::inst()->get(self::class, 'threshold_percent');
        if (!is_int($threshold) || $threshold < 0 || $threshold > 100) {
            throw new InvalidArgumentException('Forecast threshold_percent must be an integer from 0 to 100.');
        }
        return $threshold;
    }
}
