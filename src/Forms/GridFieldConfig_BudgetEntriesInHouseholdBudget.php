<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forms;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetMonth;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\HouseholdBudget\Forms\GridField\GridFieldFilter_BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Forms\GridField\BudgetAmountBar;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Dedicated editor configuration for the personal household budget entry list.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forms
 */
class GridFieldConfig_BudgetEntriesInHouseholdBudget extends GridFieldConfig
{
    /** Shares the amount scale across all rows rendered by this configuration. */
    private BudgetAmountBar $amountBar;

    /**
     * Preserves standard record editing while explicitly configuring each column.
     */
    public function __construct()
    {
        parent::__construct();
        $this->amountBar = new BudgetAmountBar();
        $this->addComponents(GridFieldConfig_RecordEditor::create()->getComponents()->toArray());
        $this->addComponent(GridFieldFilter_BudgetEntryType::create(), GridFieldPaginator::class);
        $columns = $this->getComponentByType(GridFieldDataColumns::class);
        $columns->setDisplayFields($this->displayFields());
        $this->extend('updateConfig');
    }

    /**
     * Orders the amount bar before the description and amount.
     */
    private function displayFields(): array
    {
        $fields = [
            'AmountBar' => [
                'title' => '',
                'callback' => fn (BudgetEntry $record, string $column, GridField $grid): DBField =>
                    $this->amountBar->render($record, $grid),
            ],
            BudgetEntryField::TYPE => [
                'title' => BudgetEntryField::label(BudgetEntryField::TYPE),
                'callback' => fn (BudgetEntry $record): DBField =>
                    $this->typeIcon($record, null, BudgetEntryType::icon((string)$record->Type)),
            ],
            BudgetEntryField::DESCRIPTION => [
                'title' => BudgetEntryField::label(BudgetEntryField::DESCRIPTION),
                'callback' => fn (BudgetEntry $record): DBField => $this->descriptionWithContact($record),
            ],
            BudgetEntryField::AMOUNT => $this->valueColumn(BudgetEntryField::AMOUNT, 'Decimal(12,2)'),
        ];
        foreach (BudgetMonth::options() as $month => $label) {
            $fields['Month' . $month] = $this->monthColumn((string)$month, $label);
        }
        return $fields;
    }

    /**
     * Creates a translated, typed column for a stored entry value.
     */
    private function valueColumn(string $field, string $type): array
    {
        return [
            'title' => BudgetEntryField::label($field),
            'callback' => fn (BudgetEntry $record): DBField => DBField::create_field($type, $record->getField($field)),
        ];
    }

    /**
     * Adds the optional counterparty below the escaped booking description.
     */
    private function descriptionWithContact(BudgetEntry $record): DBField
    {
        $content = Convert::raw2xml((string)$record->Description) . $this->monthBoundsBadge($record);
        $contact = $record->Contact();
        if ($contact->exists()) {
            $content .= '<br><small>' . Convert::raw2xml($contact->getTitle()) . '</small>';
        }
        return DBField::create_field('HTMLText', $content);
    }

    /** Keeps optional inclusive month bounds beside the title, above the counterparty. */
    private function monthBoundsBadge(BudgetEntry $record): string
    {
        $start = (string)$record->getField(BudgetEntryField::START_MONTH);
        $end = (string)$record->getField(BudgetEntryField::END_MONTH);
        if ($start === '' && $end === '') {
            return '';
        }
        $format = fn (string $month): string => substr($month, 5, 2) . '/' . substr($month, 0, 4);
        $label = match (true) {
            $start !== '' && $end !== '' => $format($start) . '–' . $format($end),
            $start !== '' => _t(__CLASS__ . '.FROM_MONTH', 'from {month}', ['month' => $format($start)]),
            default => _t(__CLASS__ . '.UNTIL_MONTH', 'until {month}', ['month' => $format($end)]),
        };
        return ' <span class="household-budget-month-bounds">' . Convert::raw2xml($label) . '</span>';
    }

    /**
     * Shows a recurring month under its two-character translated abbreviation.
     */
    private function monthColumn(string $month, string $label): array
    {
        return [
            'title' => mb_substr($label, 0, 2, 'UTF-8'),
            'callback' => fn (BudgetEntry $record): DBField =>
                $this->monthIndicator($record, $month, $label),
        ];
    }

    /**
     * Marks selected recurring months with the booking type's colored icon.
     */
    private function monthIndicator(BudgetEntry $record, string $month, string $label): DBField
    {
        $months = explode(',', (string)$record->getField(BudgetEntryField::MONTHS));
        return in_array($month, $months, true)
            ? $this->typeIcon($record, $label . ': ' . $record->getTypeLabel())
            : DBField::create_field('HTMLText', '');
    }

    /**
     * Displays an accessible income or expense icon without exposing raw record HTML.
     */
    private function typeIcon(BudgetEntry $record, ?string $label = null, ?string $icon = null): DBField
    {
        $icon ??= match ($record->Type) {
            BudgetEntryType::INCOME => 'plus-circled',
            BudgetEntryType::TRANSFER => 'sync',
            default => 'minus-circle',
        };
        $type = match ($record->Type) {
            BudgetEntryType::INCOME => 'income',
            BudgetEntryType::TRANSFER => 'transfer',
            default => 'expense',
        };
        $label = Convert::raw2att($label ?? $record->getTypeLabel());
        return DBField::create_field(
            'HTMLText',
            '<span class="font-icon-' . $icon . ' household-budget-type-icon--' . $type . '" role="img" aria-label="' . $label
                . '" title="' . $label . '"></span>'
        );
    }
}
