<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forecasts;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastInsight;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastScope;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPosition;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Security\Member;
use DateTimeImmutable;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Produces localized email copy and rows without performing forecast calculations.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forecasts
 */
final class BudgetForecastEmailViewData
{
    /**
     * Uses the already-resolved report locale for every displayed amount and month.
     */
    public function __construct(
        private readonly BudgetForecast $forecast,
        private readonly Member $member
    ) {
    }

    /**
     * Returns all presentation values consumed by the HTML and plaintext templates.
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        $month = $this->forecast->report->months[0];
        $codes = $this->forecast->insightCodes;
        $monthLabel = $this->monthLabel($this->forecast->report->period->startMonth);
        return [
            'Subject' => $this->subject($monthLabel),
            'Greeting' => $this->greeting(),
            'Month' => $monthLabel,
            'Scope' => $this->scopeLabel(),
            'NoBookings' => in_array(BudgetForecastInsight::NO_BOOKINGS, $codes, true),
            'NoBookingsMessage' => _t(__CLASS__ . '.NO_BOOKINGS', 'No bookings are recorded for this month. Zero values do not mean that the household has no costs.'),
            'IncomeLabel' => _t(__CLASS__ . '.INCOME', 'Planned income'),
            'Income' => $this->money($month->incomeCents),
            'NormalCostsLabel' => _t(__CLASS__ . '.NORMAL_COSTS', 'Normal costs (fixed, variable and periodic)'),
            'NormalCosts' => $this->money($month->normalCostsCents),
            'PeriodicCostsLabel' => _t(__CLASS__ . '.PERIODIC_COSTS', 'Periodic costs'),
            'PeriodicCosts' => $this->money($month->typeTotals[BudgetEntryType::PERIODIC]),
            'ExceptionalLabel' => _t(__CLASS__ . '.EXCEPTIONAL', 'Exceptional costs'),
            'Exceptional' => $this->money($month->exceptionalCents),
            'TotalCostsLabel' => _t(__CLASS__ . '.TOTAL_COSTS', 'Total planned costs'),
            'TotalCosts' => $this->money($month->expenseCents),
            'TransfersLabel' => _t(__CLASS__ . '.TRANSFERS', 'Transfers / reserves (separate; not costs)'),
            'Transfers' => $this->money($month->transferCents),
            'BalanceLabel' => _t(__CLASS__ . '.BALANCE', 'Planned balance'),
            'Balance' => $this->money($month->balanceCents),
            'BalanceMessage' => $this->balanceMessage($month->balanceCents),
            'ComparisonLabel' => _t(__CLASS__ . '.COMPARISON', 'Cost comparison'),
            'Comparison' => $this->comparisonLabel(),
            'AnnualAverageLabel' => _t(__CLASS__ . '.ANNUAL_AVERAGE', 'Target-year monthly average, including exceptional costs'),
            'AnnualAverage' => $this->money($this->forecast->annualAverageCostsCents),
            'ComparisonPercent' => $this->comparisonPercent(),
            'HasComparison' => $this->forecast->comparisonPercentBasisPoints !== null,
            'VariableCostsLabel' => _t(__CLASS__ . '.VARIABLE_COSTS', 'Variable bookings'),
            'VariableCosts' => $this->money($this->forecast->variableCostsCents),
            'VariableReview' => $this->money($this->forecast->variableReviewCents),
            'RemainingDeficit' => $this->money($this->forecast->remainingDeficitCents),
            'VariableGuidance' => $this->variableGuidance(),
            'HasVariable' => $this->forecast->variablePositions !== [],
            'TopExpenseTitle' => _t(__CLASS__ . '.TOP_EXPENSES', 'Largest upcoming planned expenses'),
            'VariableTitle' => _t(__CLASS__ . '.VARIABLE', 'Variable bookings to review'),
            'PeriodicTitle' => _t(__CLASS__ . '.PERIODIC', 'Periodic cost bookings'),
            'ExceptionalTitle' => _t(__CLASS__ . '.EXCEPTIONAL_GROUP', 'Exceptional costs'),
            'TransferTitle' => _t(__CLASS__ . '.TRANSFER_GROUP', 'Transfers / reserves (neutral)'),
            'TopExpenses' => $this->rows($this->forecast->topExpenses),
            'VariablePositions' => $this->rows($this->forecast->variablePositions),
            'PeriodicPositions' => $this->rows($this->forecast->periodicPositions),
            'ExceptionalPositions' => $this->rows($this->forecast->exceptionalPositions),
            'TransferPositions' => $this->rows($this->forecast->transfers),
            'HasTopExpenses' => $this->forecast->topExpenses !== [],
            'HasPeriodic' => $this->forecast->periodicPositions !== [],
            'HasExceptional' => $this->forecast->exceptionalPositions !== [],
            'HasTransfers' => $this->forecast->transfers !== [],
            'Disclaimer' => _t(__CLASS__ . '.DISCLAIMER', 'This is a planning snapshot based on currently saved bookings, not actual transactions, a due-date schedule, an account balance or guaranteed savings.'),
        ];
    }

    /**
     * Localizes the subject using only the forecast month and selected account scope.
     */
    private function subject(string $month): string
    {
        return _t(__CLASS__ . '.SUBJECT', 'Household forecast for {month} — {scope}', [
            'month' => $month,
            'scope' => $this->scopeLabel(),
        ]);
    }

    /**
     * Translates overall scope only after preparation has selected the recipient locale.
     */
    private function scopeLabel(): string
    {
        return $this->forecast->scope === BudgetForecastScope::OVERALL
            ? _t(__CLASS__ . '.ALL_ACCOUNTS', 'All own accounts')
            : $this->forecast->accountLabel;
    }

    /**
     * Uses the current member's name without changing the configured mail recipient.
     */
    private function greeting(): string
    {
        $name = trim((string)$this->member->FirstName);
        return $name === ''
            ? _t(__CLASS__ . '.GREETING', 'Hello,')
            : _t(__CLASS__ . '.GREETING_NAME', 'Hello {name},', ['name' => $name]);
    }

    /**
     * Explains a planned deficit, surplus, or zero balance without implying liquidity.
     */
    private function balanceMessage(int $balanceCents): string
    {
        if ($this->forecast->report->positions === []) {
            return _t(__CLASS__ . '.NO_BALANCE_CLAIM', 'No balance conclusion is drawn because no bookings are recorded.');
        }
        if ($balanceCents < 0) {
            return _t(__CLASS__ . '.DEFICIT', 'The plan has a shortfall of {amount}.', [
                'amount' => $this->money(abs($balanceCents)),
            ]);
        }
        if ($balanceCents > 0) {
            return _t(__CLASS__ . '.SURPLUS', 'The plan shows a surplus of {amount}; this is not available bank cash.', [
                'amount' => $this->money($balanceCents),
            ]);
        }
        return _t(__CLASS__ . '.BALANCED', 'The plan is balanced for this month.');
    }

    /**
     * Describes any optional variable-cost review without promising savings.
     */
    private function variableGuidance(): string
    {
        if ($this->forecast->deficitCents === 0 || $this->forecast->variableCostsCents === 0) {
            return '';
        }
        if ($this->forecast->remainingDeficitCents > 0) {
            return _t(__CLASS__ . '.VARIABLE_INSUFFICIENT', 'Even a hypothetical full reduction of variable bookings ({variable}) would leave a shortfall of {remaining}. No saving is guaranteed.', [
                'variable' => $this->money($this->forecast->variableCostsCents),
                'remaining' => $this->money($this->forecast->remainingDeficitCents),
            ]);
        }
        return _t(__CLASS__ . '.VARIABLE_REVIEW', 'You could review up to {amount} in variable bookings against the planned shortfall. This is not a recommendation or a guaranteed saving.', [
            'amount' => $this->money($this->forecast->variableReviewCents),
        ]);
    }

    /**
     * Resolves the comparison category to translated explanatory copy.
     */
    private function comparisonLabel(): string
    {
        return match ($this->forecast->comparison) {
            BudgetForecastInsight::EXPENSIVE => _t(__CLASS__ . '.EXPENSIVE', 'Rather high: at least 10% above the target-year average.'),
            BudgetForecastInsight::CHEAP => _t(__CLASS__ . '.CHEAP', 'Rather low: at least 10% below the target-year average.'),
            BudgetForecastInsight::USUAL => _t(__CLASS__ . '.USUAL', 'Within 10% of the target-year average.'),
            default => _t(__CLASS__ . '.NO_COMPARISON', 'No cost comparison is available for this month.'),
        };
    }

    /**
     * Formats display-only percentage data after the exact classification was decided.
     */
    private function comparisonPercent(): string
    {
        $basisPoints = $this->forecast->comparisonPercentBasisPoints;
        if ($basisPoints === null) {
            return '';
        }
        $locale = $this->forecast->report->locale;
        $formatter = new NumberFormatter($locale, NumberFormatter::PERCENT);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 1);
        return (string)$formatter->format($basisPoints / 10000);
    }

    /**
     * Formats cents with the recipient's locale.
     */
    private function money(int $cents): string
    {
        $formatter = new NumberFormatter($this->forecast->report->locale, NumberFormatter::CURRENCY);
        return (string)$formatter->formatCurrency($cents / 100, 'EUR');
    }

    /**
     * Formats the forecast month in the recipient's locale.
     */
    private function monthLabel(string $month): string
    {
        return (string)(new IntlDateFormatter(
            $this->forecast->report->locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            null,
            null,
            'LLLL yyyy'
        ))->format(new DateTimeImmutable($month . '-01'));
    }

    /**
     * Escapes financial labels at template render time, preserving optional counterparties.
     *
     * @param list<BudgetReportPosition> $positions
     */
    private function rows(array $positions): ArrayList
    {
        return new ArrayList(array_map(fn (BudgetReportPosition $position): ArrayData => ArrayData::create([
            'Description' => $position->entry->description,
            'Counterparty' => $position->entry->counterparty,
            'Amount' => $this->money($position->totalCents),
        ]), $positions));
    }
}
