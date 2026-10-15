<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Stable machine-readable forecast classifications and explanation codes.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class BudgetForecastInsight
{
    public const EXPENSIVE = 'expensive';
    public const CHEAP = 'cheap';
    public const USUAL = 'usual';
    public const NO_COMPARISON = 'no_comparison';
    public const DEFICIT = 'deficit';
    public const SURPLUS = 'surplus';
    public const BALANCED = 'balanced';
    public const NO_BOOKINGS = 'no_bookings';
    public const VARIABLE_CHECK = 'variable_check';
    public const VARIABLE_INSUFFICIENT = 'variable_insufficient';
    public const TRANSFERS_NEUTRAL = 'transfers_neutral';

    /**
     * Returns translated insight labels.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::EXPENSIVE => _t(__CLASS__ . '.EXPENSIVE', 'Higher than the annual monthly average'),
            self::CHEAP => _t(__CLASS__ . '.CHEAP', 'Lower than the annual monthly average'),
            self::USUAL => _t(__CLASS__ . '.USUAL', 'Within the usual range'),
            self::NO_COMPARISON => _t(__CLASS__ . '.NO_COMPARISON', 'No annual cost comparison is available'),
            self::DEFICIT => _t(__CLASS__ . '.DEFICIT', 'Planned deficit'),
            self::SURPLUS => _t(__CLASS__ . '.SURPLUS', 'Planned surplus'),
            self::BALANCED => _t(__CLASS__ . '.BALANCED', 'Balanced plan'),
            self::NO_BOOKINGS => _t(__CLASS__ . '.NO_BOOKINGS', 'No bookings are recorded for this month'),
            self::VARIABLE_CHECK => _t(__CLASS__ . '.VARIABLE_CHECK', 'Review variable bookings'),
            self::VARIABLE_INSUFFICIENT => _t(__CLASS__ . '.VARIABLE_INSUFFICIENT', 'Variable bookings alone do not cover the deficit'),
            self::TRANSFERS_NEUTRAL => _t(__CLASS__ . '.TRANSFERS_NEUTRAL', 'Transfers are not household costs'),
        ];
    }

    /**
     * Returns a translated insight label or the unknown identifier.
     */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string)$value;
    }
}
