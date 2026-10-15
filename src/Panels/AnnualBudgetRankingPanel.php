<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Panels;

use InvalidArgumentException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;

/**
 * Ranks individual bookings by their effective annual totals.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Panels
 */
class AnnualBudgetRankingPanel extends MonthlyBudgetChartPanel
{
    use Configurable;

    /** Maximum number of annual positions displayed, configurable through YAML. */
    private static int $maximum_positions = 10;

    /** Returns the validated ranking limit. */
    public static function maximumPositions(): int
    {
        $limit = static::config()->get('maximum_positions');
        if (!is_int($limit) || $limit < 1) {
            throw new InvalidArgumentException('maximum_positions must be a positive integer.');
        }
        return $limit;
    }

    /** Returns the localized dashboard panel title. */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Largest annual income and expenses');
    }

    /** Loads the authenticated annual ranking. */
    public function getResourceURL(): string
    {
        return Director::absoluteURL('household-budget-api/ranking');
    }
}
