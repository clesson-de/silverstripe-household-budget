<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Reports;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use DateTimeImmutable;
use IntlDateFormatter;
use NumberFormatter;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * Prepares translated display values without calculating financial amounts.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Reports
 */
final class BudgetReportViewData
{
    /**
     * Uses the report locale for date and currency presentation.
     */
    public function __construct(private readonly string $locale)
    {
    }

    /**
     * Renders the title, disclaimer, and headline totals for the first page.
     */
    public function overviewTemplate(HouseholdBudgetReport $report): string
    {
        return (string)$this->overviewData($report)->renderWith(
            'Clesson\Silverstripe\HouseholdBudget\Reports\HouseholdBudgetReport'
        );
    }

    /**
     * Renders one month table with every included month and its exact totals.
     *
     * @param list<BudgetReportMonth> $months
     */
    public function monthlyTableTemplate(array $months): string
    {
        return (string)$this->tableData($this->monthlyRows($months))->renderWith(
            'Clesson\Silverstripe\HouseholdBudget\Reports\Includes\MonthlyTable'
        );
    }

    /**
     * Renders the cost category table without recomputing category shares.
     *
     * @param list<BudgetReportCategory> $categories
     */
    public function categoryTableTemplate(array $categories): string
    {
        return (string)$this->tableData($this->categoryRows($categories))->renderWith(
            'Clesson\Silverstripe\HouseholdBudget\Reports\Includes\CategoryTable'
        );
    }

    /**
     * Renders annual sums with partial-year annotations from precomputed month counts.
     */
    public function yearTableTemplate(HouseholdBudgetReport $report): string
    {
        $data = $this->tableData($this->yearRows($report));
        return (string)$data->renderWith('Clesson\Silverstripe\HouseholdBudget\Reports\Includes\YearTable');
    }

    /**
     * Renders a group of booking positions with occurrence and period totals.
     *
     * @param list<BudgetReportPosition> $positions
     */
    public function positionTableTemplate(array $positions): string
    {
        return (string)$this->tableData($this->positionRows($positions))->renderWith(
            'Clesson\Silverstripe\HouseholdBudget\Reports\Includes\PositionTable'
        );
    }

    /**
     * Returns translated report copy with an English fallback.
     */
    public function text(string $key, string $fallback): string
    {
        return $this->label($key, $fallback);
    }

    /**
     * Creates escaped template data for the first-page overview.
     */
    private function overviewData(HouseholdBudgetReport $report): ArrayData
    {
        return ArrayData::create(array_merge(
            $this->overviewMetadata($report),
            $this->overviewMetrics($report),
            $this->overviewLabels()
        ));
    }

    /**
     * Creates localized overview title, range, timestamp, account, and caveat data.
     */
    private function overviewMetadata(HouseholdBudgetReport $report): array
    {
        return [
            'Title' => $this->label('REPORT_TITLE', 'Household budget report'),
            'Period' => $this->periodLabel($report->period),
            'Created' => $this->dateLabel($report->createdAt),
            'Accounts' => implode(', ', $report->accountLabels),
            'Disclaimer' => $this->label(
                'DISCLAIMER',
                'Planning based on recorded bookings, not actual bank transactions. Historical periods reflect the current booking data; no change history is retained.'
            ),
            'AverageNote' => $this->label(
                'AVERAGE_NOTE',
                'Averages include every selected calendar month. Periodic bookings are counted in their selected month, not prorated.'
            ),
        ];
    }

    /**
     * Formats all overview amounts directly from the immutable totals DTO.
     */
    private function overviewMetrics(HouseholdBudgetReport $report): array
    {
        return [
            'Income' => $this->money($report->totals->incomeCents),
            'Expenses' => $this->money($report->totals->expenseCents),
            'Balance' => $this->money($report->totals->balanceCents),
            'BalanceClass' => $report->totals->balanceCents < 0 ? 'negative' : 'positive',
            'BalanceColor' => $report->totals->balanceCents < 0 ? '#b42318' : '#18743b',
            'NormalAverage' => $this->money($report->totals->averageNormalCostsCents),
            'TotalAverage' => $this->money($report->totals->averageExpenseCents),
            'Exceptional' => $this->money($report->totals->exceptionalCents),
            'Transfers' => $this->money($report->totals->transferCents),
        ];
    }

    /**
     * Prepares translated metric labels and explanatory notes.
     */
    private function overviewLabels(): array
    {
        return [
            'PeriodLabel' => $this->label('PERIOD', 'Period'),
            'CreatedLabel' => $this->label('CREATED_ON', 'Created on'),
            'AccountsLabel' => $this->label('SELECTED_ACCOUNTS', 'Selected accounts'),
            'IncomeLabel' => $this->label('INCOME', 'Income'),
            'ExpenseLabel' => $this->label('TOTAL_EXPENSES', 'Planned household costs'),
            'BalanceLabel' => $this->label('BALANCE', 'Surplus / deficit'),
            'NormalAverageLabel' => $this->label('NORMAL_AVERAGE', 'Normal monthly household need'),
            'TotalAverageLabel' => $this->label('TOTAL_AVERAGE', 'Average monthly costs including exceptional items'),
            'ExceptionalLabel' => $this->label('EXCEPTIONAL', 'Exceptional costs'),
            'TransferLabel' => $this->label('TRANSFERS', 'Transfers / reserves'),
            'TransferNote' => $this->label('TRANSFER_NOTE', 'Transfers are shown separately and do not affect costs or balance.'),
            'Legend' => $this->label('LEGEND', 'Normal costs include fixed, variable, and periodic bookings; exceptional costs are additional.'),
        ];
    }

    /**
     * Builds localized display rows for monthly totals.
     *
     * @param list<BudgetReportMonth> $months
     */
    private function monthlyRows(array $months): ArrayList
    {
        return new ArrayList(array_map(fn (BudgetReportMonth $month): ArrayData => ArrayData::create([
            'Month' => $this->monthLabel($month->month),
            'Income' => $this->money($month->incomeCents),
            'NormalCosts' => $this->money($month->normalCostsCents),
            'Exceptional' => $this->money($month->exceptionalCents),
            'Expenses' => $this->money($month->expenseCents),
            'Transfers' => $this->money($month->transferCents),
            'Balance' => $this->money($month->balanceCents),
            'BalanceColor' => $month->balanceCents < 0 ? '#b42318' : '#18743b',
        ]), $months));
    }

    /**
     * Builds translated cost categories using their precomputed basis-point shares.
     *
     * @param list<BudgetReportCategory> $categories
     */
    private function categoryRows(array $categories): ArrayList
    {
        return new ArrayList(array_map(fn (BudgetReportCategory $category): ArrayData => ArrayData::create([
            'Category' => $this->typeLabel($category->type),
            'Amount' => $this->money($category->amountCents),
            'Share' => number_format($category->shareBasisPoints / 100, 2, $this->decimalSeparator(), $this->groupSeparator()) . '%',
            'ShareBasisPoints' => $category->shareBasisPoints,
        ]), $categories));
    }

    /**
     * Builds safely escaped position rows while preserving duplicate descriptions.
     *
     * @param list<BudgetReportPosition> $positions
     */
    private function positionRows(array $positions): ArrayList
    {
        return new ArrayList(array_map(fn (BudgetReportPosition $position): ArrayData => ArrayData::create([
            'Description' => $position->entry->description,
            'Counterparty' => $position->entry->counterparty,
            'Account' => $position->entry->account,
            'Amount' => $this->money($position->entry->amountCents),
            'Occurrences' => $position->occurrenceCount,
            'Months' => implode(', ', array_map(fn (string $month): string => $this->monthLabel($month), $position->occurrenceMonths)),
            'Total' => $this->money($position->totalCents),
        ]), $positions));
    }

    /**
     * Builds yearly presentation rows without changing the supplied year totals.
     */
    private function yearRows(HouseholdBudgetReport $report): ArrayList
    {
        return new ArrayList(array_map(fn (string $year): ArrayData => $this->yearRow($report, $year), array_keys($report->years)));
    }

    /**
     * Creates one formatted yearly table row.
     */
    private function yearRow(HouseholdBudgetReport $report, string $year): ArrayData
    {
        $totals = $report->years[$year];
        return ArrayData::create([
            'Year' => $year,
            'Partial' => $report->yearMonthCounts[$year] < 12 ? '*' : '',
            'Income' => $this->money($totals->incomeCents),
            'NormalCosts' => $this->money($totals->normalCostsCents),
            'Exceptional' => $this->money($totals->exceptionalCents),
            'Expenses' => $this->money($totals->expenseCents),
            'Transfers' => $this->money($totals->transferCents),
            'Balance' => $this->money($totals->balanceCents),
        ]);
    }

    /**
     * Packages rows and translated headings for a reusable report table.
     */
    private function tableData(ArrayList $rows): ArrayData
    {
        return ArrayData::create(array_merge(['Rows' => $rows], $this->monthlyLabels(), $this->positionLabels()));
    }

    /**
     * Prepares shared monthly, category, and yearly table column labels.
     */
    private function monthlyLabels(): array
    {
        return [
            'MonthLabel' => $this->label('MONTH', 'Month'),
            'IncomeLabel' => $this->label('INCOME', 'Income'),
            'NormalCostsLabel' => $this->label('NORMAL_COSTS', 'Normal costs'),
            'ExceptionalLabel' => $this->label('EXCEPTIONAL', 'Exceptional'),
            'ExpensesLabel' => $this->label('TOTAL_EXPENSES', 'Total costs'),
            'TransfersLabel' => $this->label('TRANSFERS', 'Transfers'),
            'BalanceLabel' => $this->label('BALANCE', 'Balance'),
            'CategoryLabel' => $this->label('CATEGORY', 'Category'),
            'AmountLabel' => $this->label('AMOUNT', 'Amount'),
            'ShareLabel' => $this->label('SHARE', 'Share'),
            'YearLabel' => $this->label('YEAR', 'Year'),
            'PartialNote' => $this->label('PARTIAL_YEAR', '* Totals include only the selected months in that year.'),
        ];
    }

    /**
     * Prepares position table column labels.
     */
    private function positionLabels(): array
    {
        return [
            'DescriptionLabel' => $this->label('DESCRIPTION', 'Description'),
            'CounterpartyLabel' => $this->label('COUNTERPARTY', 'Counterparty'),
            'AccountLabel' => $this->label('ACCOUNT', 'Account'),
            'OccurrencesLabel' => $this->label('OCCURRENCES', 'Occurrences'),
            'MonthsLabel' => $this->label('MONTHS', 'Months'),
            'TotalLabel' => $this->label('PERIOD_TOTAL', 'Period total'),
        ];
    }

    /**
     * Formats a signed integer-cent amount using locale-aware currency display.
     */
    private function money(int $cents): string
    {
        $formatter = new NumberFormatter($this->locale, NumberFormatter::CURRENCY);
        return (string)$formatter->formatCurrency($cents / 100, 'EUR');
    }

    /**
     * Formats a YYYY-MM key as a localized month and year.
     */
    private function monthLabel(string $month): string
    {
        return (string)(new IntlDateFormatter(
            $this->locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            null,
            null,
            'LLLL yyyy'
        ))
            ->format(new DateTimeImmutable($month . '-01'));
    }

    /**
     * Formats the selected inclusive period.
     */
    private function periodLabel(BudgetReportPeriod $period): string
    {
        return $this->monthLabel($period->startMonth) . ' - ' . $this->monthLabel($period->endMonth);
    }

    /**
     * Formats the report creation date according to the report locale.
     */
    private function dateLabel(DateTimeImmutable $date): string
    {
        return (string)(new IntlDateFormatter($this->locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE))
            ->format($date);
    }

    /**
     * Returns translated report copy with an English fallback.
     */
    private function label(string $key, string $fallback): string
    {
        return _t(__CLASS__ . '.' . $key, $fallback);
    }

    /**
     * Returns the localized label for a budget booking type.
     */
    public function typeLabel(string $type): string
    {
        return BudgetEntryType::label($type);
    }

    /**
     * Returns locale-specific decimal and grouping separators for percentages.
     *
     * @return array{string, string}
     */
    private function separators(): array
    {
        $formatter = new NumberFormatter($this->locale, NumberFormatter::DECIMAL);
        return [
            (string)$formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
            (string)$formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL),
        ];
    }

    /**
     * Returns the localized decimal separator.
     */
    private function decimalSeparator(): string
    {
        return $this->separators()[0];
    }

    /**
     * Returns the localized grouping separator.
     */
    private function groupSeparator(): string
    {
        return $this->separators()[1];
    }
}
