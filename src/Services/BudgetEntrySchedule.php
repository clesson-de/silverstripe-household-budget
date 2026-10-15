<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

/**
 * Applies selected recurrence months and inclusive YYYY-MM booking bounds.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class BudgetEntrySchedule
{
    /**
     * Determines whether a booking occurs in a calendar month.
     */
    public function occursInMonth(string $months, string $start, string $end, string $month): bool
    {
        $monthNumber = substr($month, -2);
        if (!in_array($monthNumber, explode(',', $months), true)) {
            return false;
        }
        return ($start === '' || $start <= $month) && ($end === '' || $end >= $month);
    }
}
