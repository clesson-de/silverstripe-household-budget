<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Permission required to access private household budget screens and reports.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class HouseholdBudgetPermission
{
    /** Grants access to a member's own household budget workspace. */
    public const ACCESS = 'CMS_ACCESS_HOUSEHOLD_BUDGET';

    /**
     * Returns the translated permission label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [self::ACCESS => _t(__CLASS__ . '.ACCESS', 'Access household budget')];
    }

    /**
     * Returns the translated label for a permission.
     */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string)$value;
    }
}
