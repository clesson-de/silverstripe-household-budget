<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Models;

use Clesson\Silverstripe\Dashboard\Forms\DashboardField;
use Clesson\Silverstripe\Hints\Forms\HintField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField;
use Clesson\Silverstripe\HouseholdBudget\Forms\GridFieldConfig_BudgetEntriesInHouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Forms\HouseholdBudgetForecastSettingsField;
use Clesson\Silverstripe\HouseholdBudget\Forms\MonthlyBudgetDetailsField;
use Clesson\Silverstripe\HouseholdBudget\Forms\HouseholdBudgetReportField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastAccountSetting;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastDelivery;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetPanel;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetChartPanel;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetLineChartPanel;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetCategoryChartPanel;
use Clesson\Silverstripe\HouseholdBudget\Panels\AnnualBudgetRankingPanel;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Personal CMS workspace combining the dashboard and owner-scoped budget entries.
 *
 * @property string $Title
 * @property bool $ForecastEmailsEnabled
 * @property bool $CombinedForecastEmailEnabled
 * @property int $OwnerID
 * @method Member Owner()
 * @method HasManyList|BudgetForecastAccountSetting[] AccountForecastSettings()
 * @method HasManyList|BudgetForecastDelivery[] ForecastDeliveries()
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Models
 */
class HouseholdBudget extends DataObject
{
    private static string $table_name = 'HouseholdBudget_HouseholdBudget';
    private static string $default_sort = 'ID ASC';
    private static string $general_search_field = 'Title';
    private static array $db = [
        'Title' => 'Varchar(100)',
        BudgetForecastField::FORECAST_EMAILS_ENABLED => 'Boolean',
        BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED => 'Boolean',
    ];
    private static array $has_one = [BudgetEntryField::OWNER => Member::class];
    private static array $has_many = [
        BudgetForecastField::ACCOUNT_FORECAST_SETTINGS => BudgetForecastAccountSetting::class,
        BudgetForecastField::FORECAST_DELIVERIES => BudgetForecastDelivery::class,
    ];
    private static array $indexes = [
        BudgetEntryField::OWNER_ID => ['type' => 'unique', 'columns' => [BudgetEntryField::OWNER_ID]],
    ];

    /**
     * Creates or retrieves exactly one workspace for the authenticated member.
     */
    public static function currentRecord(): static
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.SIGN_IN_REQUIRED', 'You must be signed in.'), 403);
        }
        $record = static::get()->filter(BudgetEntryField::OWNER_ID, $member->ID)->setUseCache(true)->first();
        if (!$record) {
            $record = static::create();
            $record->write();
        }
        return $record;
    }

    /**
     * Populates the personal workspace without creating records for other members.
     */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
        $this->Title = _t(__CLASS__ . '.SINGULARNAME', 'Household budget');
        $this->OwnerID = Security::getCurrentUser()?->ID ?? 0;
        $this->ForecastEmailsEnabled = false;
        $this->CombinedForecastEmailEnabled = false;
    }

    /**
     * Requires a persisted owning member for every workspace.
     */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->Owner()->exists()) {
            $result->addError(_t(__CLASS__ . '.OWNER_REQUIRED', 'A household budget requires an owner.'));
        }
        return $result;
    }

    /**
     * Returns translated workspace field labels.
     */
    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Title'),
            BudgetForecastField::FORECAST_EMAILS_ENABLED => _t(
                __CLASS__ . '.FORECAST_EMAILS_ENABLED',
                'Enable monthly forecast emails'
            ),
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED => _t(
                __CLASS__ . '.COMBINED_FORECAST_EMAIL_ENABLED',
                'Send one forecast for all my accounts'
            ),
            BudgetEntryField::OWNER => BudgetEntryField::label(BudgetEntryField::OWNER),
            BudgetEntryField::OWNER_ID => BudgetEntryField::label(BudgetEntryField::OWNER),
            BudgetForecastField::ACCOUNT_FORECAST_SETTINGS => BudgetForecastField::label(
                BudgetForecastField::ACCOUNT
            ),
            BudgetForecastField::FORECAST_DELIVERIES => BudgetForecastField::label(
                BudgetForecastField::STATUS
            ),
            'ID' => _t('Clesson\Silverstripe\HouseholdBudget\Common.ID', 'ID'),
            'Created' => _t('Clesson\Silverstripe\HouseholdBudget\Common.CREATED', 'Created'),
            'LastEdited' => _t('Clesson\Silverstripe\HouseholdBudget\Common.LAST_EDITED', 'Last edited'),
        ]);
    }

    /**
     * Renders the dashboard, owner-scoped entries and read-only month details.
     */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName([
            'Title',
            BudgetEntryField::OWNER_ID,
            BudgetForecastField::FORECAST_EMAILS_ENABLED,
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED,
            BudgetForecastField::ACCOUNT_FORECAST_SETTINGS,
            BudgetForecastField::FORECAST_DELIVERIES,
            'Main',
        ]);
        $this->addDashboard($fields);
        $this->addMonthlyDetails($fields);
        $this->addReport($fields);
        $this->addAdministration($fields);
        $this->extend('updateHouseholdBudgetFields', $fields);
        return $fields;
    }

    /**
     * Embeds the existing personal dashboard with a single default panel.
     */
    private function addDashboard(FieldList $fields): void
    {
        /** @var DashboardField $dashboardField */
        $dashboardField = DashboardField::create('Dashboard', 'household-budget');
        $panels = [
            MonthlyBudgetPanel::class, MonthlyBudgetChartPanel::class,
            MonthlyBudgetLineChartPanel::class, AnnualBudgetRankingPanel::class,
            MonthlyBudgetCategoryChartPanel::class,
        ];
        $dashboardField->setAllowedPanels($panels);
        $dashboardField->setDefaultPanels($panels);
        $fields->addFieldToTab('Root.Dashboard', $dashboardField);
        $fields->findOrMakeTab('Root.Dashboard')->setTitle(_t(__CLASS__ . '.DASHBOARD', 'Dashboard'));
    }

    /**
     * Reuses existing entries without relocating or changing their ownership.
     */
    private function addEntries(FieldList $fields): void
    {
        $this->addEntriesHint($fields);
        $entries = BudgetEntry::get()->filter(BudgetEntryField::OWNER_ID, $this->OwnerID);
        /** @var GridField $entriesField */
        $entriesField = GridField::create(
            'Entries',
            '',
            $entries,
            GridFieldConfig_BudgetEntriesInHouseholdBudget::create()
        );
        $fields->addFieldToTab('Root.Administration.AdministrationTabs.Entries', $entriesField);
    }

    /** Explains booking creation, recurrence and the grid's visual controls. */
    private function addEntriesHint(FieldList $fields): void
    {
        /** @var HintField $entriesHintField */
        $entriesHintField = HintField::create(
            'EntriesHint',
            _t(__CLASS__ . '.ENTRIES_HINT_TITLE', 'Managing your planned bookings'),
            Convert::raw2xml(_t(
                __CLASS__ . '.ENTRIES_HINT',
                'Create or edit your planned bookings here. Enter a clear description, a positive amount, the booking type and your bank account; a counterparty is optional. Select every month in which the amount occurs. Optional start and end months limit the recurrence, including both boundary months. Use the type buttons to filter the list. The month icons show selected recurring months; optional date limits still apply. The comparison bars show each amount relative to the largest amount in its group. Income is green, expenses red and transfers blue. Transfers do not affect costs or balance. Save each booking after editing.'
            ))
        );
        $fields->addFieldToTab('Root.Administration.AdministrationTabs.Entries', $entriesHintField);
    }

    /** Adds a dedicated tab for complete monthly groups and their totals. */
    private function addMonthlyDetails(FieldList $fields): void
    {
        /** @var HintField $monthlyDetailsHintField */
        $monthlyDetailsHintField = HintField::create(
            'MonthlyDetailsHint',
            _t(__CLASS__ . '.MONTHLY_DETAILS_HINT_TITLE', 'Using the monthly details'),
            Convert::raw2xml(_t(
                __CLASS__ . '.MONTHLY_DETAILS_HINT',
                'Review the planned income, expenses and balance for one month. Select a year, month and the accounts to include; the view updates automatically. Income and expenses are listed separately with their totals. Transfers are shown separately and do not affect the balance. These are planned bookings, not actual account transactions or balances. To add or change bookings, open Administration → Budget entries.'
            ))
        );
        $fields->addFieldToTab('Root.MonthlyDetails', $monthlyDetailsHintField);
        /** @var MonthlyBudgetDetailsField $monthlyDetailsField */
        $monthlyDetailsField = MonthlyBudgetDetailsField::create('MonthlyDetails');
        $fields->addFieldToTab('Root.MonthlyDetails', $monthlyDetailsField);
        $fields->findOrMakeTab('Root.MonthlyDetails')->setTitle(_t(__CLASS__ . '.MONTHLY_DETAILS', 'Monthly details'));
    }

    /**
     * Adds a non-persistent export field for the current workspace owner.
     */
    private function addReport(FieldList $fields): void
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return;
        }
        $this->addReportHint($fields);
        /** @var HouseholdBudgetReportField $reportField */
        $reportField = HouseholdBudgetReportField::create('Report', $member);
        $fields->addFieldToTab('Root.Report', $reportField);
        $fields->findOrMakeTab('Root.Report')->setTitle(_t(__CLASS__ . '.REPORT', 'Report / PDF'));
    }

    /** Explains report contents and the non-persistent download controls. */
    private function addReportHint(FieldList $fields): void
    {
        /** @var HintField $reportHintField */
        $reportHintField = HintField::create(
            'ReportHint',
            _t(__CLASS__ . '.REPORT_HINT_TITLE', 'Creating a household report'),
            Convert::raw2xml(_t(
                __CLASS__ . '.REPORT_HINT',
                'Create a PDF overview of income, household costs, balance, monthly development and cost categories. Select a start and end month; both months are included, and the period may span multiple years. Then select at least one of your linked accounts and click "Download household report". All accounts are initially selected. The PDF is downloaded directly; you do not need to save the CMS form. Transfers are shown separately and do not affect costs or balance. Reports use the currently recorded planned bookings, including for past periods, not actual bank transactions or account balances.'
            ))
        );
        $fields->addFieldToTab('Root.Report', $reportHintField);
    }

    /**
     * Adds personal forecast controls as the final workspace tab.
     */
    private function addAdministration(FieldList $fields): void
    {
        /** @var TabSet $administrationTabsField */
        $administrationTabsField = TabSet::create(
            'AdministrationTabs',
            Tab::create('Entries', _t(__CLASS__ . '.ENTRIES', 'Budget entries')),
            Tab::create('Settings', _t(__CLASS__ . '.SETTINGS', 'Settings'))
        );
        $fields->addFieldToTab('Root.Administration', $administrationTabsField);
        $fields->findOrMakeTab('Root.Administration')->setTitle(_t(__CLASS__ . '.ADMINISTRATION', 'Administration'));
        $this->addEntries($fields);
        $this->addForecastSettings($fields);
    }

    /**
     * Places the personal email forecast form inside the administration settings tab.
     */
    private function addForecastSettings(FieldList $fields): void
    {
        $member = $this->Owner();
        if (!$member->exists() || (int)$member->ID !== (int)Security::getCurrentUser()?->ID) {
            return;
        }
        $this->addSettingsHint($fields);
        /** @var CheckboxField $forecastEmailsEnabledField */
        $forecastEmailsEnabledField = CheckboxField::create(
            BudgetForecastField::FORECAST_EMAILS_ENABLED,
            _t(__CLASS__ . '.FORECAST_EMAILS_ENABLED', 'Enable monthly forecast emails'),
            (bool)$this->ForecastEmailsEnabled
        );
        /** @var CheckboxField $combinedForecastEmailEnabledField */
        $combinedForecastEmailEnabledField = CheckboxField::create(
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED,
            _t(__CLASS__ . '.COMBINED_FORECAST_EMAIL_ENABLED', 'Send one forecast for all my accounts'),
            (bool)$this->CombinedForecastEmailEnabled
        );
        /** @var HouseholdBudgetForecastSettingsField $forecastSettingsField */
        $forecastSettingsField = HouseholdBudgetForecastSettingsField::create('ForecastSettings', $this);
        $fields->addFieldsToTab('Root.Administration.AdministrationTabs.Settings', [
            $forecastEmailsEnabledField,
            $combinedForecastEmailEnabledField,
            $forecastSettingsField,
        ]);
    }

    /** Explains personal opt-in, independent scopes and month-end delivery. */
    private function addSettingsHint(FieldList $fields): void
    {
        /** @var HintField $settingsHintField */
        $settingsHintField = HintField::create(
            'SettingsHint',
            _t(__CLASS__ . '.SETTINGS_HINT_TITLE', 'Configuring monthly forecast emails'),
            Convert::raw2xml(_t(
                __CLASS__ . '.SETTINGS_HINT',
                'Enable monthly forecast emails with the main switch, then select the accounts for which you want a separate email. You can also enable an additional forecast for all your linked accounts, independently of the individual selections. All emails go only to the email address in your user account and include a matching PDF. Save the CMS form to apply your settings. Turning off the main switch pauses all emails without clearing your selections. When the technical task is configured and running, emails are sent only on the last calendar day in the application time zone and describe the next month; there is no later catch-up. Forecasts use your current planned bookings, not actual account balances. The delivery overview below shows recent results; an accepted email is not a guarantee of delivery, and uncertain attempts are not retried automatically.'
            ))
        );
        $fields->addFieldToTab('Root.Administration.AdministrationTabs.Settings', $settingsHintField);
    }

    /**
     * Only the owner may view this workspace, including administrators.
     */
    public function canView($member = null): bool
    {
        $member = $member ?? Security::getCurrentUser();
        return $member instanceof Member && (int)$member->ID === (int)$this->OwnerID;
    }

    /**
     * Only authenticated members may create their own workspace.
     */
    public function canCreate($member = null, $context = []): bool
    {
        $member = $member ?? Security::getCurrentUser();
        return $member instanceof Member && $member->exists();
    }

    /**
     * Only the owner may edit this workspace.
     */
    public function canEdit($member = null): bool
    {
        return $this->canView($member);
    }

    /**
     * The personal workspace must remain available to its owner.
     */
    public function canDelete($member = null): bool
    {
        return false;
    }
}
