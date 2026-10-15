<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Controllers;

use Clesson\Silverstripe\HouseholdBudget\Services\MonthlyBudgetService;
use Clesson\Silverstripe\Graphing\Forms\BarChartField;
use Clesson\Silverstripe\Graphing\Forms\LineChartField;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetPanel;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetMonth;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Panels\AnnualBudgetRankingPanel;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Renders the signed-in member's monthly household budget summary.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Controllers
 */
class MonthlyBudgetController extends Controller
{
    private static array $allowed_actions = [
        'summary',
        'chart',
        'linechart',
        'categorychart',
        'details',
        'ranking',
    ];

    private static array $url_handlers = [
        'summary' => 'summary',
        'chart' => 'chart',
        'linechart' => 'linechart',
        'categorychart' => 'categorychart',
        'details' => 'details',
        'ranking' => 'ranking',
    ];

    /**
     * Returns the monthly summary for the authenticated member.
     *
     * @return DBHTMLText
     */
    public function summary(): DBHTMLText
    {
        $member = $this->currentMember();
        i18n::set_locale($member->Locale ?: i18n::get_locale());
        $service = new MonthlyBudgetService();
        $accounts = $this->accountsFor($member);
        $year = $this->selectedYear();
        $templateData = [
            'Year' => $year,
            'Years' => $this->yearOptions($year),
            'SummaryURL' => Director::absoluteURL('household-budget-api/summary'),
            'Months' => $service->summariesFor($member, $year, $accounts->column('ID')),
            'Accounts' => $this->accountChartData($accounts, $member, $service, $year),
            'IncomeLabel' => _t(__CLASS__ . '.INCOME', 'Income'),
            'ExpensesLabel' => _t(__CLASS__ . '.EXPENSES', 'Expenses'),
            'BalanceLabel' => _t(__CLASS__ . '.BALANCE', 'Balance'),
        ];
        return $this->customise($templateData)
            ->renderWith('Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController');
    }

    /** Renders one complete month without exposing another member's bookings. */
    public function details(): DBHTMLText
    {
        $member = $this->currentMember();
        i18n::set_locale($member->Locale ?: i18n::get_locale());
        $year = $this->selectedYear();
        $month = $this->selectedMonth();
        $accounts = $this->accountsFor($member);
        $selectedIDs = $this->selectedAccountIDs($accounts);
        $details = (new MonthlyBudgetService())->detailsFor($member, $year, $month, $selectedIDs);
        return $this->customise($this->detailsOptions($year, $month, $accounts, $selectedIDs))
            ->customise(['Details' => $details])
            ->renderWith('Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetDetails');
    }

    /** Renders the configurable annual top positions for the selected private accounts. */
    public function ranking(): DBHTMLText
    {
        $member = $this->currentMember();
        i18n::set_locale($member->Locale ?: i18n::get_locale());
        $year = $this->selectedYear();
        $type = $this->selectedType();
        $accounts = $this->accountsFor($member);
        $ids = $this->selectedAccountIDs($accounts);
        $rows = (new MonthlyBudgetService())->annualRankingFor(
            $member, $year, $ids, $type, AnnualBudgetRankingPanel::maximumPositions()
        );
        return $this->customise($this->rankingOptions($year, $type, $accounts, $ids, $rows))
            ->renderWith('Clesson\Silverstripe\HouseholdBudget\Controllers\AnnualBudgetRanking');
    }

    /** Provides reusable year/account controls and an exclusive booking type selector. */
    private function rankingOptions(int $year, string $type, DataList $accounts, array $ids, ArrayList $rows): array
    {
        $types = ['' => _t(__CLASS__ . '.ALL', 'All')] + BudgetEntryType::options();
        return [
            'Year' => $year, 'Years' => $this->yearOptions($year),
            'Accounts' => $this->accountOptions($accounts, $ids),
            'Types' => new ArrayList(array_map(fn ($value, $label): ArrayData => new ArrayData([
                'Value' => $value, 'Title' => $label, 'Selected' => $value === $type,
            ]), array_keys($types), array_values($types))),
            'DetailsURL' => Director::absoluteURL('household-budget-api/ranking'),
            'RankingChart' => $rows->count() ? $this->rankingChart($rows, $year) : null,
            'HasPositions' => $rows->count() > 0,
        ];
    }

    /** Uses the existing graphing module with a separate color for each booking. */
    private function rankingChart(ArrayList $rows, int $year): DBHTMLText
    {
        /** @var BarChartField $chart */
        $chart = BarChartField::create('AnnualBudgetRanking')->setHorizontal(true)->setData([
            'labels' => array_map(
                fn (ArrayData $row): string => $row->Description . ' (' . $row->AnnualAmount . ')',
                $rows->toArray()
            ),
            'datasets' => [[
                'label' => _t(__CLASS__ . '.ANNUAL_AMOUNT', 'Annual amount (€)'),
                'data' => array_map(fn (ArrayData $row): float => $row->AnnualCents / 100, $rows->toArray()),
                'backgroundColor' => array_map(
                    fn (ArrayData $row): string => match ($row->Type) {
                        BudgetEntryType::INCOME => '#28a745',
                        BudgetEntryType::TRANSFER => '#2093e5',
                        default => '#dc3545',
                    },
                    $rows->toArray()
                ),
            ]],
        ])->setChartHeight((string)max(200, $rows->count() * 40 + 80) . 'px')->setChartTitle((string)$year);
        return DBField::create_field('HTMLText', $chart->Field());
    }

    /** Rejects unsupported ranking filters instead of ignoring them. */
    private function selectedType(): string
    {
        $type = $this->getRequest()?->getVar('type') ?? '';
        if (!is_string($type) || ($type !== '' && !array_key_exists($type, BudgetEntryType::options()))) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.INVALID_TYPE', 'Select a valid transaction type.'), 400);
        }
        return $type;
    }

    /** Provides selectors and a dedicated reload URL for the read-only CMS field. */
    private function detailsOptions(int $year, string $month, DataList $accounts, array $selectedIDs): array
    {
        return [
            'Year' => $year,
            'Years' => $this->yearOptions($year),
            'Months' => $this->monthOptions($month),
            'Accounts' => $this->accountOptions($accounts, $selectedIDs),
            'DetailsURL' => Director::absoluteURL('household-budget-api/details'),
        ];
    }

    /** Builds localized month options while normalizing numeric array keys. */
    private function monthOptions(string $month): ArrayList
    {
        return new ArrayList(array_map(
            fn ($value, $label): ArrayData => new ArrayData([
                'Value' => (string)$value, 'Title' => $label, 'Selected' => (string)$value === $month,
            ]),
            array_keys(BudgetMonth::options()), array_values(BudgetMonth::options())
        ));
    }

    /** Presents account controls independently of chart dataset structures. */
    private function accountOptions(DataList $accounts, array $selectedIDs): ArrayList
    {
        return new ArrayList(array_map(fn (BankAccount $account): ArrayData => new ArrayData([
            'AccountID' => $account->ID, 'Title' => $account->Summary,
            'Selected' => in_array((int)$account->ID, $selectedIDs, true),
        ]), $accounts->toArray()));
    }

    /** Accepts only valid two-digit calendar months, defaulting to the current month. */
    private function selectedMonth(): string
    {
        $month = $this->getRequest()?->getVar('month') ?? date('m');
        if (!is_string($month) || !array_key_exists($month, BudgetMonth::options())) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.INVALID_MONTH', 'Select a valid month.'), 400);
        }
        return $month;
    }

    /** Validates every selected account against the authenticated member's accounts. */
    private function selectedAccountIDs(DataList $accounts): array
    {
        $allowed = array_map('intval', $accounts->column('ID'));
        $value = $this->getRequest()?->getVar('accounts');
        if ($value === null) {
            return $allowed;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $value)) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.INVALID_ACCOUNTS', 'Select valid bank accounts.'), 400);
        }
        $selected = array_map('intval', explode(',', $value));
        if (array_diff($selected, $allowed)) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.INVALID_ACCOUNTS', 'Select valid bank accounts.'), 400);
        }
        return array_values(array_unique($selected));
    }

    /**
     * Requires an authenticated member before exposing budget information.
     *
     * @return Member
     */
    private function currentMember(): Member
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            throw new HTTPResponse_Exception(
                _t(__CLASS__ . '.UNAUTHORISED', 'You must be signed in to view this dashboard.'),
                403
            );
        }
        return $member;
    }

    /**
     * Renders a stacked chart with the same ownership and locale as the table.
     */
    public function chart(): DBHTMLText
    {
        $member = $this->currentMember();
        i18n::set_locale($member->Locale ?: i18n::get_locale());
        $service = new MonthlyBudgetService();
        $accounts = $this->accountsFor($member);
        $year = $this->selectedYear();
        $selectedIDs = $this->selectedAccountIDs($accounts);
        /** @var BarChartField $chartField */
        $chartField = BarChartField::create('MonthlyBudgetChart')
            ->setData($service->chartDataFor($member, $year, $selectedIDs))
            ->setStacked(true)
            ->setChartHeight('350px')
            ->setChartTitle((string)$year);
        return $this->customise([
            'Chart' => DBField::create_field('HTMLText', $chartField->Field()),
            'Year' => $year,
            'Years' => $this->yearOptions($year),
            'DetailsURL' => Director::absoluteURL('household-budget-api/chart'),
            'Accounts' => $this->accountOptions($accounts, $selectedIDs),
        ])->renderWith('Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetChart');
    }

    /** Renders independent monthly lines using the existing private annual totals. */
    public function linechart(): DBHTMLText
    {
        $member = $this->currentMember();
        i18n::set_locale($member->Locale ?: i18n::get_locale());
        $accounts = $this->accountsFor($member);
        $year = $this->selectedYear();
        $ids = $this->selectedAccountIDs($accounts);
        /** @var LineChartField $lineChartField */
        $lineChartField = LineChartField::create('MonthlyBudgetLineChart')
            ->setData($this->lineChartData($member, $year, $ids))
            ->setOptions(['scales' => ['x' => ['stacked' => false], 'y' => ['stacked' => false, 'beginAtZero' => true]]])
            ->setChartHeight('350px')->setChartTitle((string)$year);
        return $this->lineChartView($lineChartField, $year, $accounts, $ids);
    }

    /** Reuses totals and established colors without the bar chart's shared stack. */
    private function lineChartData(Member $member, int $year, array $ids): array
    {
        $data = (new MonthlyBudgetService())->chartDataFor($member, $year, $ids);
        foreach ($data['datasets'] as &$dataset) {
            unset($dataset['stack']);
            $dataset['borderColor'] = $dataset['backgroundColor'];
            $dataset['borderWidth'] = 2;
            $dataset['pointRadius'] = 3;
        }
        unset($dataset);
        return $data;
    }

    /** Uses the existing async year/account controls for the additional panel. */
    private function lineChartView(LineChartField $field, int $year, DataList $accounts, array $ids): DBHTMLText
    {
        return $this->customise([
            'Chart' => DBField::create_field('HTMLText', $field->Field()),
            'Year' => $year,
            'Years' => $this->yearOptions($year),
            'DetailsURL' => Director::absoluteURL('household-budget-api/linechart'),
            'Accounts' => $this->accountOptions($accounts, $ids),
        ])->renderWith('Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetChart');
    }

    /** Renders the current or selected month with four distinct category bars. */
    public function categorychart(): DBHTMLText
    {
        $member = $this->currentMember();
        i18n::set_locale($member->Locale ?: i18n::get_locale());
        $year = $this->selectedYear();
        $month = $this->selectedMonth();
        $accounts = $this->accountsFor($member);
        $ids = $this->selectedAccountIDs($accounts);
        /** @var BarChartField $categoryChartField */
        $categoryChartField = BarChartField::create('MonthlyBudgetCategoryChart')
            ->setData((new MonthlyBudgetService())->categoryChartDataFor($member, $year, $month, $ids))
            ->setOptions(['scales' => ['x' => ['stacked' => false], 'y' => ['stacked' => false, 'beginAtZero' => true]]])
            ->setChartHeight('350px')->setChartTitle(BudgetMonth::label($month) . ' ' . $year);
        return $this->categoryChartView($categoryChartField, $year, $month, $accounts, $ids);
    }

    /** Reuses the existing reloadable chart controls with an additional month selector. */
    private function categoryChartView(BarChartField $field, int $year, string $month, DataList $accounts, array $ids): DBHTMLText
    {
        return $this->customise([
            'Chart' => DBField::create_field('HTMLText', $field->Field()),
            'Year' => $year,
            'Years' => $this->yearOptions($year),
            'Months' => $this->monthOptions($month),
            'DetailsURL' => Director::absoluteURL('household-budget-api/categorychart'),
            'Accounts' => $this->accountOptions($accounts, $ids),
        ])->renderWith('Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetChart');
    }

    /** Returns only bank accounts linked to the authenticated member's contact. */
    private function accountsFor(Member $member): DataList
    {
        $contactID = (int)$member->Contact()->ID;
        $accounts = BankAccount::get()->filter('ContactID', $contactID);
        return $contactID > 0 ? $accounts : $accounts->filter('ID', 0);
    }

    /** Embeds numeric monthly series for each account to enable local toggling. */
    private function accountChartData(
        DataList $accounts,
        Member $member,
        MonthlyBudgetService $service,
        ?int $year = null
    ): ArrayList
    {
        $rows = new ArrayList();
        foreach ($accounts as $account) {
            $rows->push(new ArrayData([
                'AccountID' => $account->ID,
                'Title' => $account->Summary,
                'ChartData' => json_encode(
                    $service->chartDataFor($member, $year ?? (int)date('Y'), [(int)$account->ID]),
                    JSON_THROW_ON_ERROR
                ),
            ]));
        }
        return $rows;
    }

    /** Rejects requested years outside the configured panel range. */
    private function selectedYear(): int
    {
        $value = $this->getRequest()?->getVar('year');
        if ($value === null) {
            return (int)date('Y');
        }
        $year = filter_var($value, FILTER_VALIDATE_INT);
        if ($year === false || !in_array($year, MonthlyBudgetPanel::availableYears(), true)) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.INVALID_YEAR', 'Select a valid year.'), 400);
        }
        return $year;
    }

    /** Builds selected dropdown options for the configured range. */
    private function yearOptions(int $selectedYear): ArrayList
    {
        return new ArrayList(array_map(
            fn (int $year): ArrayData => new ArrayData(['Value' => $year, 'Selected' => $year === $selectedYear]),
            MonthlyBudgetPanel::availableYears()
        ));
    }
}
