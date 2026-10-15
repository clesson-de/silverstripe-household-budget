<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Exceptions;

use InvalidArgumentException;

/**
 * Indicates an empty, malformed, or non-owned report account selection.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Exceptions
 */
final class InvalidBudgetReportAccountSelection extends InvalidArgumentException
{
}
