<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Helpers;

use InvalidArgumentException;

/**
 * Converts and formats budget amounts without floating-point arithmetic.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Helpers
 */
final class BudgetMoney
{
    /**
     * Converts a decimal database value to exact integer cents.
     */
    public static function toCents(string $amount): int
    {
        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $amount, $matches)) {
            throw new InvalidArgumentException('Budget amounts must be positive decimal values with at most two decimals.');
        }
        return ((int)$matches[1] * 100) + (int)str_pad($matches[2] ?? '', 2, '0');
    }

    /**
     * Formats signed cents with the module's established German-style output.
     */
    public static function formatCents(int $cents): string
    {
        $absolute = abs($cents);
        return ($cents < 0 ? '-' : '')
            . number_format(intdiv($absolute, 100), 0, ',', '.') . ','
            . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT) . ' €';
    }

    /**
     * Returns a nearest-cent average using conventional half-up rounding.
     */
    public static function averageCents(int $cents, int $months): int
    {
        if ($months < 1) {
            throw new InvalidArgumentException('An average requires at least one calendar month.');
        }
        $sign = $cents < 0 ? -1 : 1;
        return $sign * intdiv(abs($cents) + intdiv($months, 2), $months);
    }
}
