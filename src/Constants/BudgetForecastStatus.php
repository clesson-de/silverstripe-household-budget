<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Persistent states for an individual forecast email delivery.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class BudgetForecastStatus
{
    public const PREPARING = 'preparing';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const UNCERTAIN = 'uncertain';
    public const SKIPPED = 'skipped';

    /**
     * Returns translated delivery-state labels.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::PREPARING => _t(__CLASS__ . '.PREPARING', 'Preparing'),
            self::SENDING => _t(__CLASS__ . '.SENDING', 'Sending'),
            self::SENT => _t(__CLASS__ . '.SENT', 'Accepted by transport'),
            self::FAILED => _t(__CLASS__ . '.FAILED', 'Failed before transport'),
            self::UNCERTAIN => _t(__CLASS__ . '.UNCERTAIN', 'Outcome uncertain; manual review required'),
            self::SKIPPED => _t(__CLASS__ . '.SKIPPED', 'Skipped'),
        ];
    }

    /**
     * Returns a translated status label or the unknown identifier.
     */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string)$value;
    }
}
