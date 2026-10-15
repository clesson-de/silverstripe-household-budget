<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Panels;

use Clesson\Silverstripe\Dashboard\Panels\DashboardPanel;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use InvalidArgumentException;

/**
 * Dashboard panel showing the current year's household budget by month.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Panels
 */
class MonthlyBudgetPanel extends DashboardPanel
{
    use Configurable;

    /**
     * Number of previous years offered in addition to the current year.
     *
     * @config
     */
    private static int $years_before_current = 5;

    /** Returns the configured inclusive year range, newest first. */
    public static function availableYears(): array
    {
        $previousYears = static::config()->get('years_before_current');
        $currentYear = (int)date('Y');
        if (!is_int($previousYears) || $previousYears < 0 || $previousYears >= $currentYear) {
            throw new InvalidArgumentException('years_before_current must be a non-negative integer below the current year.');
        }
        return range($currentYear + 1, $currentYear - $previousYears);
    }

    /**
     * Returns the translated panel title.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Monthly household budget');
    }

    /**
     * Returns the endpoint that renders the monthly overview.
     *
     * @return string
     */
    public function getResourceURL(): string
    {
        return Director::absoluteURL('household-budget-api/summary');
    }

    /**
     * Returns a wide default layout for the monthly table.
     *
     * @return string
     */
    public function getDefaultWidth(): string
    {
        return 'double';
    }
}
