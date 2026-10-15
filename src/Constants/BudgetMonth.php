<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Month values used to define recurring budget entries.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class BudgetMonth
{
    public const JANUARY = '01';
    public const FEBRUARY = '02';
    public const MARCH = '03';
    public const APRIL = '04';
    public const MAY = '05';
    public const JUNE = '06';
    public const JULY = '07';
    public const AUGUST = '08';
    public const SEPTEMBER = '09';
    public const OCTOBER = '10';
    public const NOVEMBER = '11';
    public const DECEMBER = '12';

    /**
     * Returns translated month labels keyed by their two-digit month number.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::JANUARY => _t(__CLASS__ . '.JANUARY', 'January'),
            self::FEBRUARY => _t(__CLASS__ . '.FEBRUARY', 'February'),
            self::MARCH => _t(__CLASS__ . '.MARCH', 'March'),
            self::APRIL => _t(__CLASS__ . '.APRIL', 'April'),
            self::MAY => _t(__CLASS__ . '.MAY', 'May'),
            self::JUNE => _t(__CLASS__ . '.JUNE', 'June'),
            self::JULY => _t(__CLASS__ . '.JULY', 'July'),
            self::AUGUST => _t(__CLASS__ . '.AUGUST', 'August'),
            self::SEPTEMBER => _t(__CLASS__ . '.SEPTEMBER', 'September'),
            self::OCTOBER => _t(__CLASS__ . '.OCTOBER', 'October'),
            self::NOVEMBER => _t(__CLASS__ . '.NOVEMBER', 'November'),
            self::DECEMBER => _t(__CLASS__ . '.DECEMBER', 'December'),
        ];
    }

    /**
     * Returns the translated label for a month value.
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
