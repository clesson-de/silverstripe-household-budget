<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forms\GridField;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Model\List\SS_List;

/**
 * Filters bookings through mutually exclusive buttons stored in GridField state.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forms\GridField
 */
class GridFieldFilter_BudgetEntryType implements GridField_HTMLProvider, GridField_DataManipulator, GridField_ActionProvider
{
    use Injectable;

    /** GridField state key for the selected booking type. */
    private const STATE_KEY = 'BudgetEntryTypeFilter';

    /** Native AJAX action shared by the category filter buttons. */
    private const ACTION = 'filterbudgetentrytype';

    /** Returns the supported GridField action. */
    public function getActions($gridField): array
    {
        return [self::ACTION];
    }

    /** Stores the chosen type and returns pagination to the first page. */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data): void
    {
        if ($actionName !== self::ACTION) {
            return;
        }
        $type = $arguments[BudgetEntryField::TYPE] ?? '';
        if (!is_string($type) || ($type !== '' && !array_key_exists($type, BudgetEntryType::options()))) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.INVALID_TYPE', 'Select a valid transaction type.'), 400);
        }
        $key = self::STATE_KEY;
        $gridField->State->$key = $type;
        $gridField->State->GridFieldPaginator->currentPage = 1;
    }

    /** Renders exclusive All and booking category buttons above the list. */
    public function getHTMLFragments($gridField): array
    {
        $options = ['' => _t(__CLASS__ . '.ALL', 'All')] + BudgetEntryType::options();
        $buttons = '';
        foreach ($options as $type => $label) {
            $buttons .= $this->button($gridField, $type, $label)->forTemplate();
        }
        $label = Convert::raw2att(BudgetEntryField::label(BudgetEntryField::TYPE));
        return ['before' => '<div class="household-budget-entry-type-filter btn-group" role="group" aria-label="'
            . $label . '">' . $buttons . '</div>'];
    }

    /** Builds a native GridField action without triggering dirty-form tracking. */
    private function button(GridField $gridField, string $type, string $label): GridField_FormAction
    {
        $selected = $this->selectedType($gridField) === $type;
        /** @var GridField_FormAction $button */
        $button = GridField_FormAction::create(
            $gridField,
            self::ACTION . ($type ?: 'all'),
            $label,
            self::ACTION,
            [BudgetEntryField::TYPE => $type]
        );
        $button->addExtraClass('no-change-track btn ' . ($selected ? 'btn-primary' : 'btn-outline-primary'));
        $button->setAttribute('aria-pressed', $selected ? 'true' : 'false');
        return $button;
    }

    /** Filters the existing owner-scoped list before pagination is applied. */
    public function getManipulatedData(GridField $gridField, SS_List $dataList): SS_List
    {
        $type = $this->selectedType($gridField);
        return $type === '' ? $dataList : $dataList->filter(BudgetEntryField::TYPE, $type);
    }

    /** Reads the selection, initially displaying every booking type. */
    private function selectedType(GridField $gridField): string
    {
        $key = self::STATE_KEY;
        return (string)($gridField->State->$key ?? '');
    }
}
