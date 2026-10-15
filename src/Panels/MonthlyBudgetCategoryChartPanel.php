<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Panels;

use SilverStripe\Control\Director;

/**
 * Compares income, fixed expenses, variable expenses, transfers and balance for one month.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Panels
 */
class MonthlyBudgetCategoryChartPanel extends MonthlyBudgetChartPanel
{
    /** Returns the translated monthly comparison title. */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Monthly income and cost comparison');
    }

    /** Loads the private chart with month, year and account selectors. */
    public function getResourceURL(): string
    {
        return Director::absoluteURL('household-budget-api/categorychart');
    }
}
