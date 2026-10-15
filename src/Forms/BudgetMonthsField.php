<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forms;

use SilverStripe\Forms\CheckboxSetField;

/**
 * Preserves the compact comma-separated month format used by budget entries.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forms
 */
class BudgetMonthsField extends CheckboxSetField
{
    /**
     * Stores at most twelve two-digit months and eleven separators.
     */
    public function stringEncode($value): ?string
    {
        return $this->csvEncode($value);
    }

    /**
     * Restores selected months from the persisted comma-separated value.
     */
    protected function stringDecode($value): array
    {
        return $this->csvDecode($value);
    }
}
