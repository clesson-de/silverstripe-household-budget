<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Distinguishes account-specific forecasts from combined forecasts.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class BudgetForecastScope
{
    public const ACCOUNT = 'account';
    public const OVERALL = 'overall';

    /**
     * Returns translated scope labels.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::ACCOUNT => _t(__CLASS__ . '.ACCOUNT', 'One bank account'),
            self::OVERALL => _t(__CLASS__ . '.OVERALL', 'All own accounts'),
        ];
    }

    /**
     * Returns a translated scope label or the unknown identifier.
     */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string)$value;
    }
}
