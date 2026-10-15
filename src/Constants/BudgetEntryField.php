<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Constants;

/**
 * Central field and relation identifiers used by household budget entries.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Constants
 */
final class BudgetEntryField
{
    public const DESCRIPTION = 'Description';
    public const AMOUNT = 'Amount';
    public const TYPE = 'Type';
    public const MONTHS = 'Months';
    public const START_MONTH = 'StartMonth';
    public const END_MONTH = 'EndMonth';
    public const ACCOUNT = 'Account';
    public const ACCOUNT_ID = 'AccountID';
    public const CONTACT = 'Contact';
    public const CONTACT_ID = 'ContactID';
    public const OWNER = 'Owner';
    public const OWNER_ID = 'OwnerID';

    /**
     * Returns translated labels for all identifiers.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::DESCRIPTION => _t(__CLASS__ . '.DESCRIPTION', 'Description'),
            self::AMOUNT => _t(__CLASS__ . '.AMOUNT', 'Amount'),
            self::TYPE => _t(__CLASS__ . '.TYPE', 'Transaction type'),
            self::MONTHS => _t(__CLASS__ . '.MONTHS', 'Recurring months'),
            self::START_MONTH => _t(__CLASS__ . '.START_MONTH', 'Start month'),
            self::END_MONTH => _t(__CLASS__ . '.END_MONTH', 'End month'),
            self::ACCOUNT => _t(__CLASS__ . '.ACCOUNT', 'Bank account'),
            self::CONTACT => _t(__CLASS__ . '.CONTACT', 'Counterparty'),
            self::OWNER => _t(__CLASS__ . '.OWNER', 'Owner'),
        ];
    }

    /**
     * Returns the translated label for an identifier.
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
