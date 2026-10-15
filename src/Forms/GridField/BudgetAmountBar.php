<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forms\GridField;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBField;
use WeakMap;

/**
 * Renders diverging amount bars scaled against the original owner-scoped list.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forms\GridField
 */
class BudgetAmountBar
{
    /** Request-local maxima per original list, independent of filtering and pagination. */
    private WeakMap $maxima;

    /** Initializes the request-local scale cache. */
    public function __construct()
    {
        $this->maxima = new WeakMap();
    }

    /** Renders a bar occupying a proportional part of its type's half-width. */
    public function render(BudgetEntry $record, GridField $grid): DBField
    {
        $type = match ($record->Type) {
            BudgetEntryType::INCOME => 'income',
            BudgetEntryType::TRANSFER => 'transfer',
            default => 'expense',
        };
        $maximum = $this->maximum($grid->getList(), $type);
        $ratio = $maximum > 0 ? min(1, max(0, (float)$record->Amount / $maximum)) : 0;
        $width = number_format($ratio * 50, 6, '.', '');
        $label = Convert::raw2att($record->getTypeLabel() . ': ' . $record->Amount);
        return DBField::create_field(
            'HTMLText',
            '<div class="household-budget-amount-bar" role="img" aria-label="' . $label . '" title="' . $label . '">'
                . '<span class="household-budget-amount-bar__fill household-budget-amount-bar__fill--'
                . $type . '" style="width:' . $width . '%"></span></div>'
        );
    }

    /** Calculates each scale once from the unpaginated, unfiltered source list. */
    private function maximum(SS_List $list, string $type): float
    {
        if (!isset($this->maxima[$list])) {
            $maxima = [];
            foreach ([
                'income' => [BudgetEntryType::INCOME],
                'expense' => BudgetEntryType::expenseTypes(),
                'transfer' => [BudgetEntryType::TRANSFER],
            ] as $entryType => $types) {
                $entries = $list->filter(BudgetEntryField::TYPE, $types);
                $maxima[$entryType] = $entries instanceof DataList
                    ? (float)$entries->max(BudgetEntryField::AMOUNT)
                    : (float)max(array_merge([0], $entries->column(BudgetEntryField::AMOUNT)));
            }
            $this->maxima[$list] = $maxima;
        }
        return $this->maxima[$list][$type];
    }
}
