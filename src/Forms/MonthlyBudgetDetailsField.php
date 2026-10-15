<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forms;

use Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController;
use SilverStripe\Forms\LiteralField;

/**
 * Embeds an initially populated read-only month view inside the CMS workspace.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forms
 */
class MonthlyBudgetDetailsField extends LiteralField
{
    /** Renders the current member's current month without requiring an initial AJAX call. */
    public function __construct(string $name)
    {
        parent::__construct($name, MonthlyBudgetController::create()->details()->forTemplate());
    }
}
