<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Panels;

use Clesson\Silverstripe\Dashboard\Panels\DashboardPanel;
use SilverStripe\Control\Director;

/**
 * Stacked income, expenses and balance chart for the current member.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Panels
 */
class MonthlyBudgetChartPanel extends DashboardPanel
{
    /** Returns the translated chart panel title. */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Monthly budget chart');
    }

    /** Loads only the authenticated member's chart. */
    public function getResourceURL(): string
    {
        return Director::absoluteURL('household-budget-api/chart');
    }

    /** Provides enough width for all twelve calendar months. */
    public function getDefaultWidth(): string
    {
        return 'double';
    }

    /** Loads chart initialization before asynchronously fetched canvases appear. */
    public function getJavaScript(): ?string
    {
        return 'clesson-de/silverstripe-graphing:client/admin/dist/bundle.js';
    }

    /** Loads shared chart layout styles in the dashboard page itself. */
    public function getCSS(): ?string
    {
        return 'clesson-de/silverstripe-graphing:client/admin/dist/bundle.css';
    }
}
