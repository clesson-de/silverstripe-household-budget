<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Reports;

use DateTimeImmutable;
use InvalidArgumentException;
use SilverStripe\Core\Config\Config;

/**
 * Validated, inclusive range of YYYY-MM report months.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Reports
 */
final class BudgetReportPeriod
{
    private static int $maximum_months = 120;

    /** @var list<string> */
    public readonly array $months;
    public readonly int $monthCount;

    /**
     * Creates a range bounded by the configured maximum number of months.
     */
    public function __construct(
        public readonly string $startMonth,
        public readonly string $endMonth,
        int $maximumMonths = 120
    ) {
        $this->validateInput($maximumMonths);
        $months = [];
        $current = new DateTimeImmutable($startMonth . '-01');
        $end = new DateTimeImmutable($endMonth . '-01');
        while ($current <= $end) {
            $months[] = $current->format('Y-m');
            $current = $current->modify('+1 month');
        }
        $this->months = $months;
        $this->monthCount = count($months);
    }

    /**
     * Returns the configured inclusive report length limit.
     */
    public static function maximumMonths(): int
    {
        $maximum = Config::inst()->get(self::class, 'maximum_months');
        if (!is_int($maximum) || $maximum < 1) {
            throw new InvalidArgumentException('The configured report month limit must be a positive integer.');
        }
        return $maximum;
    }

    /**
     * Rejects invalid month syntax, reversed dates, and oversized ranges.
     */
    private function validateInput(int $maximumMonths): void
    {
        if ($maximumMonths < 1 || !$this->validMonth($this->startMonth) || !$this->validMonth($this->endMonth)) {
            throw new InvalidArgumentException('Report months must use valid YYYY-MM values.');
        }
        if ($this->startMonth > $this->endMonth) {
            throw new InvalidArgumentException('The report end month must not precede the start month.');
        }
        if ($this->monthDistance() > $maximumMonths) {
            throw new InvalidArgumentException('The report range exceeds the configured maximum number of months.');
        }
    }

    /**
     * Checks month syntax and the calendar's supported year range.
     */
    private function validMonth(string $month): bool
    {
        return (bool)preg_match('/^(?!0000)[0-9]{4}-(0[1-9]|1[0-2])$/D', $month);
    }

    /**
     * Counts the inclusive month range without allocating its month list.
     */
    private function monthDistance(): int
    {
        $startYear = (int)substr($this->startMonth, 0, 4);
        $startNumber = (int)substr($this->startMonth, 5, 2);
        $endYear = (int)substr($this->endMonth, 0, 4);
        $endNumber = (int)substr($this->endMonth, 5, 2);
        return (($endYear - $startYear) * 12) + $endNumber - $startNumber + 1;
    }
}
