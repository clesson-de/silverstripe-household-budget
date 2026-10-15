<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Income and expense types for budget entries.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class BudgetEntryType
{
    /** Income does not reduce the household balance. */
    public const INCOME = 'income';
    /** Recurring fixed expenses. */
    public const FIXED = 'fixed';
    /** Expenses that can be influenced. */
    public const VARIABLE = 'variable';
    /** Irregular or annual expenses. */
    public const PERIODIC = 'periodic';
    /** Balance-neutral transfers and reserves. */
    /** Balance-neutral transfers and reserves. */
    public const TRANSFER = 'transfer';
    /** Exceptional expenses. */
    public const EXCEPTIONAL = 'exceptional';

    /**
     * Returns every supported stored type without invoking translation services.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::INCOME, self::FIXED, self::VARIABLE, self::PERIODIC, self::TRANSFER, self::EXCEPTIONAL];
    }

    /** Returns only the types that reduce the budget balance. */
    public static function expenseTypes(): array
    {
        return [self::FIXED, self::VARIABLE, self::PERIODIC, self::EXCEPTIONAL];
    }

    /**
     * Returns costs included in the regular monthly household need.
     *
     * @return list<string>
     */
    public static function normalTypes(): array
    {
        return [self::FIXED, self::VARIABLE, self::PERIODIC];
    }

    /** Returns an existing CMS icon identifying each booking category. */
    public static function icon(string $type): string
    {
        return match ($type) {
            self::INCOME => 'plus-circled',
            self::FIXED => 'lock',
            self::VARIABLE => 'sliders',
            self::PERIODIC => 'calendar',
            self::TRANSFER => 'sync',
            self::EXCEPTIONAL => 'attention',
            default => 'help-circled',
        };
    }

    /**
     * Returns translated transaction type options.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::INCOME => _t(__CLASS__ . '.INCOME', 'Income'),
            self::FIXED => _t(__CLASS__ . '.FIXED', 'Recurring fixed expenses'),
            self::VARIABLE => _t(__CLASS__ . '.VARIABLE', 'Adjustable expenses'),
            self::PERIODIC => _t(__CLASS__ . '.PERIODIC', 'Irregular / annual expenses'),
            self::TRANSFER => _t(__CLASS__ . '.TRANSFER', 'Transfer / reserve'),
            self::EXCEPTIONAL => _t(__CLASS__ . '.EXCEPTIONAL', 'Exceptional expense'),
        ];
    }

    /**
     * Returns the translated label for a transaction type.
     *
     * @param mixed $value
     * @return string
     */
    public static function label(mixed $value): string
    {
        $options = self::options();
        return isset($options[$value]) ? $options[$value] : (string)$value;
    }
}
