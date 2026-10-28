<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetMonth;
use Clesson\Silverstripe\HouseholdBudget\Helpers\BudgetMoney;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;

/**
 * Calculates each month's income and expenses for one member.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
class MonthlyBudgetService
{
    private BudgetEntrySchedule $schedule;

    /**
     * Reuses the shared recurrence rules for all monthly panel operations.
     */
    public function __construct(?BudgetEntrySchedule $schedule = null)
    {
        $this->schedule = $schedule ?? new BudgetEntrySchedule();
    }

    /**
     * Returns translated monthly totals for the selected year.
     *
     * @param Member $member
     * @param int $year
     * @return ArrayList<ArrayData>
     */
    public function summariesFor(Member $member, int $year, ?array $accountIDs = null): ArrayList
    {
        $summaries = new ArrayList();
        $entries = $this->entriesFor($member, $accountIDs);
        foreach (BudgetMonth::options() as $month => $label) {
            $month = (string)$month;
            $totals = $this->totalsForMonth($entries, $month, $year);
            $summaries->push(new ArrayData($this->summaryData($month, $label, $totals)));
        }
        return $summaries;
    }

    /** Returns the same private account-scoped source for summaries and details. */
    private function entriesFor(Member $member, ?array $accountIDs): DataList
    {
        $entries = BudgetEntry::get()->filter(BudgetEntryField::OWNER_ID, $member->ID);
        return $accountIDs === null ? $entries : $entries->filter(BudgetEntryField::ACCOUNT_ID, $accountIDs ?: [0]);
    }

    /** Ranks individual bookings by effective annual cents, not their monthly amount. */
    public function annualRankingFor(Member $member, int $year, array $accountIDs, string $type, int $limit): ArrayList
    {
        $entries = $this->entriesFor($member, $accountIDs);
        if ($type !== '') {
            $entries = $entries->filter(BudgetEntryField::TYPE, $type);
        }
        $rows = [];
        foreach ($entries as $entry) {
            $row = $this->annualPosition($entry, $year);
            if ($row->AnnualCents > 0) {
                $rows[] = $row;
            }
        }
        return (new ArrayList($rows))->sort(['AnnualCents' => 'DESC', 'EntryID' => 'ASC'])->limit($limit);
    }

    /** Counts only selected recurring months inside the inclusive start/end bounds. */
    private function annualPosition(BudgetEntry $entry, int $year): ArrayData
    {
        $occurrences = 0;
        foreach (array_keys(BudgetMonth::options()) as $month) {
            $occurrences += $this->occursInMonth($entry, (string)$month, $year) ? 1 : 0;
        }
        $cents = BudgetMoney::toCents((string)$entry->Amount) * $occurrences;
        return new ArrayData([
            'EntryID' => (int)$entry->ID, 'Description' => $entry->Description,
            'Type' => $entry->Type, 'AnnualCents' => $cents,
            'AnnualAmount' => BudgetMoney::formatCents($cents),
        ]);
    }

    /** Groups one month's complete bookings with totals calculated in integer cents. */
    public function detailsFor(Member $member, int $year, string $month, array $accountIDs): ArrayData
    {
        $entries = $this->entriesInMonth($member, $year, $month, $accountIDs);
        $totals = $this->totalsForMonth($entries, $month, $year);
        return new ArrayData(array_merge($this->summaryData($month, BudgetMonth::label($month), $totals), [
            'IncomeEntries' => $this->detailRows($entries->filter(BudgetEntryField::TYPE, BudgetEntryType::INCOME)),
            'ExpenseEntries' => $this->detailRows($entries->filter(BudgetEntryField::TYPE, BudgetEntryType::expenseTypes())),
            'TransferEntries' => $this->detailRows($entries->filter(BudgetEntryField::TYPE, BudgetEntryType::TRANSFER)),
            'NegativeBalance' => $totals['income'] < $totals['expenses'],
        ]));
    }

    /** Compares selected categories and the full monthly balance using shared recurrence rules. */
    public function categoryChartDataFor(Member $member, int $year, string $month, array $accountIDs): array
    {
        $types = [BudgetEntryType::INCOME, BudgetEntryType::FIXED, BudgetEntryType::VARIABLE, BudgetEntryType::TRANSFER];
        $totals = array_fill_keys($types, 0);
        $entries = $this->entriesInMonth($member, $year, $month, $accountIDs);
        foreach ($entries as $entry) {
            if (array_key_exists($entry->Type, $totals)) {
                $totals[$entry->Type] += BudgetMoney::toCents((string)$entry->Amount);
            }
        }
        $monthlyTotals = $this->totalsForMonth($entries, $month, $year);
        $values = [...array_values($totals), $monthlyTotals['income'] - $monthlyTotals['expenses']];
        return [
            'labels' => [...array_map(BudgetEntryType::label(...), $types), _t(__CLASS__ . '.BALANCE', 'Balance')],
            'datasets' => [[
                'label' => BudgetMonth::label($month) . ' ' . $year,
                'data' => array_map(fn (int $cents): float => $cents / 100, $values),
                'backgroundColor' => ['#28a745', '#dc3545', '#dc3545', '#2093e5', '#ffc107'],
            ]],
        ];
    }

    /** Loads a complete sorted month once, using the summary's recurrence rules. */
    private function entriesInMonth(Member $member, int $year, string $month, array $accountIDs): ArrayList
    {
        $entries = $this->entriesFor($member, $accountIDs)->sort([BudgetEntryField::AMOUNT => 'DESC', 'ID' => 'ASC']);
        return new ArrayList(array_values(array_filter(
            $entries->toArray(),
            fn (BudgetEntry $entry): bool => $this->occursInMonth($entry, $month, $year)
        )));
    }

    /** Creates safely template-escaped detail rows with the existing currency format. */
    private function detailRows(ArrayList $entries): ArrayList
    {
        return new ArrayList(array_map(fn (BudgetEntry $entry): ArrayData => new ArrayData([
            'Description' => $entry->Description,
            'Counterparty' => $entry->Contact()->exists() ? $entry->Contact()->getTitle() : '',
            'Account' => $entry->Account()->Summary,
            'Months' => $this->monthBadges($entry),
            'Amount' => $this->detailAmount($entry),
        ]), $entries->toArray()));
    }

    /** Adds the booking direction without changing stored amounts or calculated totals. */
    private function detailAmount(BudgetEntry $entry): string
    {
        $prefix = match ($entry->Type) {
            BudgetEntryType::INCOME => '+',
            BudgetEntryType::TRANSFER => '+/-',
            default => '-',
        };
        return $prefix . BudgetMoney::formatCents(BudgetMoney::toCents((string)$entry->Amount));
    }

    /** Lists configured recurrence months chronologically, highlighting the current calendar month. */
    private function monthBadges(BudgetEntry $entry): ArrayList
    {
        $selected = explode(',', (string)$entry->getField(BudgetEntryField::MONTHS));
        $months = new ArrayList();
        foreach (BudgetMonth::options() as $number => $label) {
            if (in_array((string)$number, $selected, true)) {
                $months->push(new ArrayData([
                    'Label' => mb_substr($label, 0, 3),
                    'IsCurrent' => (string)$number === date('m'),
                ]));
            }
        }
        return $months;
    }

    /**
     * Accumulates income and expenses for entries occurring in one month.
     *
     * @param iterable<BudgetEntry> $entries
     * @param string $month
     * @param int $year
     * @return array{income: int, expenses: int, transfers: int}
     */
    private function totalsForMonth(iterable $entries, string $month, int $year): array
    {
        $totals = ['income' => 0, 'expenses' => 0, 'transfers' => 0];
        foreach ($entries as $entry) {
            if (!$this->occursInMonth($entry, $month, $year)) {
                continue;
            }
            $key = match ($entry->Type) {
                BudgetEntryType::INCOME => 'income',
                BudgetEntryType::TRANSFER => 'transfers',
                default => 'expenses',
            };
            $totals[$key] += BudgetMoney::toCents((string)$entry->getField(BudgetEntryField::AMOUNT));
        }
        return $totals;
    }

    /**
     * Checks the selected month and optional inclusive date bounds.
     *
     * @param BudgetEntry $entry
     * @param string $month
     * @param int $year
     * @return bool
     */
    private function occursInMonth(BudgetEntry $entry, string $month, int $year): bool
    {
        return $this->schedule->occursInMonth(
            (string)$entry->getField(BudgetEntryField::MONTHS),
            (string)$entry->getField(BudgetEntryField::START_MONTH),
            (string)$entry->getField(BudgetEntryField::END_MONTH),
            sprintf('%04d-%s', $year, $month)
        );
    }

    /**
     * Creates template data for one monthly summary row.
     *
     * @param string $month
     * @param string $label
     * @param array{income: int, expenses: int, transfers: int} $totals
     * @return array<string, string|int>
     */
    private function summaryData(string $month, string $label, array $totals): array
    {
        return [
            'Month' => $label,
            'Income' => BudgetMoney::formatCents($totals['income']),
            'Expenses' => BudgetMoney::formatCents($totals['expenses']),
            'Transfers' => BudgetMoney::formatCents($totals['transfers']),
            'TransfersCents' => $totals['transfers'],
            'Balance' => BudgetMoney::formatCents($totals['income'] - $totals['expenses']),
            'MonthNumber' => $month,
            'IncomeCents' => $totals['income'],
            'ExpensesCents' => $totals['expenses'],
            'BalanceCents' => $totals['income'] - $totals['expenses'],
        ];
    }

    /**
     * Builds numeric chart datasets from the same monthly totals as the table.
     */
    public function chartDataFor(Member $member, int $year, ?array $accountIDs = null): array
    {
        $rows = $this->summariesFor($member, $year, $accountIDs)->toArray();
        return [
            'labels' => array_map(fn (ArrayData $row): string => $row->Month, $rows),
            'datasets' => [
                $this->chartDataset($rows, 'IncomeCents', '#28a745', _t(__CLASS__ . '.INCOME', 'Income')),
                $this->chartDataset($rows, 'ExpensesCents', '#808080', _t(__CLASS__ . '.EXPENSES', 'Expenses')),
                $this->chartDataset($rows, 'BalanceCents', '#ffc107', _t(__CLASS__ . '.BALANCE', 'Balance')),
                $this->chartDataset($rows, 'TransfersCents', '#2093e5', BudgetEntryType::label(BudgetEntryType::TRANSFER)),
            ],
        ];
    }

    /**
     * Converts integer cents to numeric euro values for a stacked dataset.
     */
    private function chartDataset(array $rows, string $field, string $colour, string $label): array
    {
        return [
            'label' => $label,
            'data' => array_map(fn (ArrayData $row): float => $row->getField($field) / 100, $rows),
            'backgroundColor' => $colour,
            'stack' => 'household-budget',
        ];
    }

}
