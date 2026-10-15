<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Models;

use Clesson\Silverstripe\Dashboard\Forms\DashboardField;
use Clesson\Silverstripe\Hints\Forms\HintField;
use Clesson\Silverstripe\HouseholdBudget\Admins\HouseholdBudgetDashboardAdmin;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Forms\GridFieldConfig_BudgetEntriesInHouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Forms\MonthlyBudgetDetailsField;
use Clesson\Silverstripe\HouseholdBudget\Forms\HouseholdBudgetReportField;
use Clesson\Silverstripe\HouseholdBudget\Forms\HouseholdBudgetForecastSettingsField;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetPanel;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetChartPanel;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetLineChartPanel;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetCategoryChartPanel;
use Clesson\Silverstripe\HouseholdBudget\Panels\AnnualBudgetRankingPanel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\TabSet;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers private workspaces and the combined SingleRecordAdmin form.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class HouseholdBudgetTest extends SapphireTest
{
    /** Ensures each test uses an isolated database. */
    protected $usesDatabase = true;

    /** Verifies repeated access reuses one workspace per member. */
    public function testWorkspaceIsPrivateAndReused(): void
    {
        $owner = Member::create();
        $owner->write();
        Security::setCurrentUser($owner);
        $budget = HouseholdBudget::currentRecord();
        $this->assertSame($budget->ID, HouseholdBudget::currentRecord()->ID);
        $other = Member::create();
        $other->write();
        Security::setCurrentUser($other);
        $otherBudget = HouseholdBudget::currentRecord();
        $this->assertNotSame($budget->ID, $otherBudget->ID);
        $this->assertFalse($budget->canView($other));
        $this->assertFalse($budget->canEdit($other));
        $this->assertFalse($budget->canDelete($owner));
        $this->assertNull(HouseholdBudgetDashboardAdmin::create()->getRecord($budget->ID));
    }

    /** Verifies the combined form contains one dashboard and an owner-filtered grid. */
    public function testCombinedForm(): void
    {
        $owner = Member::create();
        $owner->write();
        Security::setCurrentUser($owner);
        $budget = HouseholdBudget::currentRecord();
        $this->assertFalse((bool)$budget->ForecastEmailsEnabled);
        $this->assertFalse((bool)$budget->CombinedForecastEmailEnabled);
        $fields = $budget->getCMSFields();
        $dashboard = $fields->fieldByName('Root.Dashboard.Dashboard');
        $this->assertInstanceOf(DashboardField::class, $dashboard);
        $panels = [
            MonthlyBudgetPanel::class, MonthlyBudgetChartPanel::class,
            MonthlyBudgetLineChartPanel::class, AnnualBudgetRankingPanel::class,
            MonthlyBudgetCategoryChartPanel::class,
        ];
        $this->assertSame($panels, $dashboard->getDefaultPanels());
        $this->assertSame($panels, $dashboard->getAllowedPanels());
        $grid = $fields->fieldByName('Root.Administration.AdministrationTabs.Entries.Entries');
        $this->assertInstanceOf(GridField::class, $grid);
        $this->assertSame('', $grid->Title());
        $this->assertInstanceOf(GridFieldConfig_BudgetEntriesInHouseholdBudget::class, $grid->getConfig());
        $this->assertSame(0, $grid->getList()->count());
        $entriesHint = $fields->fieldByName('Root.Administration.AdministrationTabs.Entries.EntriesHint');
        $this->assertInstanceOf(HintField::class, $entriesHint);
        $this->assertStringContainsString('counterparty is optional', $entriesHint->getContent()->getValue());
        $this->assertSame(
            ['EntriesHint', 'Entries'],
            array_map(fn ($field): string => $field->getName(), $fields->fieldByName('Root.Administration.AdministrationTabs.Entries')->Fields()->toArray())
        );
        $details = $fields->fieldByName('Root.MonthlyDetails.MonthlyDetails');
        $hint = $fields->fieldByName('Root.MonthlyDetails.MonthlyDetailsHint');
        $this->assertInstanceOf(HintField::class, $hint);
        $this->assertStringContainsString('Select a year, month', $hint->getContent()->getValue());
        $this->assertStringContainsString('do not affect the balance', $hint->getContent()->getValue());
        $this->assertSame(
            ['MonthlyDetailsHint', 'MonthlyDetails'],
            array_map(fn ($field): string => $field->getName(), $fields->fieldByName('Root.MonthlyDetails')->Fields()->toArray())
        );
        $this->assertInstanceOf(MonthlyBudgetDetailsField::class, $details);
        $this->assertStringContainsString('household-budget-details', $details->getContent());
        $report = $fields->fieldByName('Root.Report.Report');
        $reportHint = $fields->fieldByName('Root.Report.ReportHint');
        $this->assertInstanceOf(HintField::class, $reportHint);
        $this->assertStringContainsString('both months are included', $reportHint->getContent()->getValue());
        $this->assertStringContainsString('at least one', $reportHint->getContent()->getValue());
        $this->assertSame(
            ['ReportHint', 'Report'],
            array_map(fn ($field): string => $field->getName(), $fields->fieldByName('Root.Report')->Fields()->toArray())
        );
        $this->assertInstanceOf(HouseholdBudgetReportField::class, $report);
        $this->assertStringContainsString('Link a bank account', $report->getContent());
        $forecastMaster = $fields->fieldByName('Root.Administration.AdministrationTabs.Settings.ForecastEmailsEnabled');
        $this->assertInstanceOf(CheckboxField::class, $forecastMaster);
        $forecastSettings = $fields->fieldByName('Root.Administration.AdministrationTabs.Settings.ForecastSettings');
        $this->assertInstanceOf(HouseholdBudgetForecastSettingsField::class, $forecastSettings);
        $settingsHint = $fields->fieldByName('Root.Administration.AdministrationTabs.Settings.SettingsHint');
        $this->assertInstanceOf(HintField::class, $settingsHint);
        $this->assertStringContainsString('Save the CMS form', $settingsHint->getContent()->getValue());
        $this->assertStringContainsString('last calendar day', $settingsHint->getContent()->getValue());
        $this->assertSame(
            ['SettingsHint', 'ForecastEmailsEnabled', 'CombinedForecastEmailEnabled', 'ForecastSettings'],
            array_map(fn ($field): string => $field->getName(), $fields->fieldByName('Root.Administration.AdministrationTabs.Settings')->Fields()->toArray())
        );
        $rootTabs = $fields->fieldByName('Root')->Tabs()->toArray();
        $this->assertSame(
            ['Dashboard', 'MonthlyDetails', 'Report', 'Administration'],
            array_map(fn ($tab): string => $tab->getName(), $rootTabs)
        );
        $this->assertNull($fields->dataFieldByName(BudgetForecastField::ACCOUNT_FORECAST_SETTINGS));
        $this->assertNull($fields->dataFieldByName(BudgetForecastField::FORECAST_DELIVERIES));
        $this->assertSame('Administration', end($rootTabs)->getName());
        $administrationTabs = $fields->fieldByName('Root.Administration.AdministrationTabs');
        $this->assertInstanceOf(TabSet::class, $administrationTabs);
        $this->assertSame(
            ['Entries', 'Settings'],
            array_map(fn ($tab): string => $tab->getName(), $administrationTabs->Tabs()->toArray())
        );
        $this->assertNull($fields->dataFieldByName('OwnerID'));
    }
}
