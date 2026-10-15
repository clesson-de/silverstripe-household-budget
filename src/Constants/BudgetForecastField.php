<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Database and form identifiers used by monthly household forecasts.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class BudgetForecastField
{
    public const HOUSEHOLD_BUDGET = 'HouseholdBudget';
    public const HOUSEHOLD_BUDGET_ID = 'HouseholdBudgetID';
    public const ACCOUNT = 'Account';
    public const ACCOUNT_ID = 'AccountID';
    public const ENABLED = 'Enabled';
    public const FORECAST_EMAILS_ENABLED = 'ForecastEmailsEnabled';
    public const COMBINED_FORECAST_EMAIL_ENABLED = 'CombinedForecastEmailEnabled';
    public const ACCOUNT_FORECAST_SETTINGS = 'AccountForecastSettings';
    public const FORECAST_DELIVERIES = 'ForecastDeliveries';
    public const TARGET_MONTH = 'TargetMonth';
    public const SCOPE = 'Scope';
    public const STATUS = 'Status';
    public const ATTEMPT_COUNT = 'AttemptCount';
    public const CLAIM_TOKEN = 'ClaimToken';
    public const CLAIMED_AT = 'ClaimedAt';
    public const SENT_AT = 'SentAt';
    public const LAST_ERROR_CODE = 'LastErrorCode';
    public const OWNER_ID = 'OwnerID';

    /**
     * Returns translated field labels.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::HOUSEHOLD_BUDGET => _t(__CLASS__ . '.HOUSEHOLD_BUDGET', 'Household budget'),
            self::ACCOUNT => _t(__CLASS__ . '.ACCOUNT', 'Bank account'),
            self::ENABLED => _t(__CLASS__ . '.ENABLED', 'Enabled'),
            self::FORECAST_EMAILS_ENABLED => _t(__CLASS__ . '.FORECAST_EMAILS_ENABLED', 'Monthly forecast emails'),
            self::COMBINED_FORECAST_EMAIL_ENABLED => _t(__CLASS__ . '.COMBINED_FORECAST_EMAIL_ENABLED', 'All own accounts'),
            self::TARGET_MONTH => _t(__CLASS__ . '.TARGET_MONTH', 'Forecast month'),
            self::SCOPE => _t(__CLASS__ . '.SCOPE', 'Scope'),
            self::STATUS => _t(__CLASS__ . '.STATUS', 'Delivery status'),
            self::ATTEMPT_COUNT => _t(__CLASS__ . '.ATTEMPT_COUNT', 'Attempts'),
            self::CLAIM_TOKEN => _t(__CLASS__ . '.CLAIM_TOKEN', 'Claim token'),
            self::CLAIMED_AT => _t(__CLASS__ . '.CLAIMED_AT', 'Claimed at'),
            self::SENT_AT => _t(__CLASS__ . '.SENT_AT', 'Accepted at'),
            self::LAST_ERROR_CODE => _t(__CLASS__ . '.LAST_ERROR_CODE', 'Last result'),
        ];
    }

    /**
     * Returns a translated field label or the unknown identifier.
     */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string)$value;
    }
}
