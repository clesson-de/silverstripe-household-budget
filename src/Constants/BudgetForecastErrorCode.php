<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Safe, non-sensitive result codes stored for delivery troubleshooting.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class BudgetForecastErrorCode
{
    public const CONFIGURATION = 'configuration';
    public const RECIPIENT = 'recipient';
    public const ACCOUNT_SCOPE = 'account_scope';
    public const PREPARATION = 'preparation';
    public const TRANSPORT = 'transport';
    public const CLAIM_EXPIRED = 'claim_expired';
    public const STATE_UPDATE = 'state_update';
    public const ACCESS = 'access';
    public const DISPATCH = 'dispatch';

    /**
     * Returns translated descriptions for safe technical outcome codes.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::CONFIGURATION => _t(__CLASS__ . '.CONFIGURATION', 'Forecast dispatch configuration is incomplete.'),
            self::RECIPIENT => _t(__CLASS__ . '.RECIPIENT', 'The member recipient address is unavailable or invalid.'),
            self::ACCOUNT_SCOPE => _t(__CLASS__ . '.ACCOUNT_SCOPE', 'The selected account is no longer linked to the owner.'),
            self::PREPARATION => _t(__CLASS__ . '.PREPARATION', 'The forecast or PDF could not be prepared.'),
            self::TRANSPORT => _t(__CLASS__ . '.TRANSPORT', 'Transport outcome is uncertain; manual review is required.'),
            self::CLAIM_EXPIRED => _t(__CLASS__ . '.CLAIM_EXPIRED', 'The preparation claim expired before sending.'),
            self::STATE_UPDATE => _t(__CLASS__ . '.STATE_UPDATE', 'The delivery state could not be confirmed.'),
            self::ACCESS => _t(__CLASS__ . '.ACCESS', 'The household owner is not currently authorized for budget access.'),
            self::DISPATCH => _t(__CLASS__ . '.DISPATCH', 'Forecast dispatch stopped before it could complete.'),
        ];
    }

    /**
     * Returns a translated safe code or a generic review message.
     */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? _t(__CLASS__ . '.UNKNOWN', 'Review the delivery status.');
    }
}
