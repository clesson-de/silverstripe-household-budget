<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Panels;

use SilverStripe\Control\Director;

/**
 * Displays twelve months of independent income, expense, balance and transfer lines.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Panels
 */
class MonthlyBudgetLineChartPanel extends MonthlyBudgetChartPanel
{
    /** Returns the translated annual line chart title. */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Annual budget development');
    }

    /** Loads the current member's annual line chart with year and account controls. */
    public function getResourceURL(): string
    {
        return Director::absoluteURL('household-budget-api/linechart');
    }
}
