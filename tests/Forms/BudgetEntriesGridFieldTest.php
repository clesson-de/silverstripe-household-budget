<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Forms;

use Clesson\Silverstripe\HouseholdBudget\Forms\GridFieldConfig_BudgetEntriesInHouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\Contacts\Models\Person;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Clesson\Silverstripe\Forms\GridField\GridFieldToolbar;
use Clesson\Silverstripe\Forms\GridField\GridFieldAddNewDropdown;
use Clesson\Silverstripe\Forms\GridField\GridFieldSearch;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use DOMDocument;
use DOMXPath;

/**
 * Covers budget table column ordering and typed cell rendering.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetEntriesGridFieldTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /** Keeps native filters and creation inside one toolbar without duplicate buttons. */
    public function testToolbarPlacement(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $grid = GridField::create('Entries', '', BudgetEntry::get()->filter('ID', 0), $config);
        $grid->setModelClass(BudgetEntry::class);
        $form = Form::create(Controller::curr(), 'BudgetToolbarTest', FieldList::create($grid), FieldList::create());
        $form->setFormAction('/test');
        self::assertInstanceOf(GridFieldToolbar::class, $config->getComponentByType(GridFieldButtonRow::class));
        self::assertCount(1, $config->getComponentsByType(GridFieldButtonRow::class));
        self::assertNull($config->getComponentByType(GridFieldAddNewButton::class));
        self::assertNull($config->getComponentByType(GridFieldFilterHeader::class));
        self::assertSame(
            ['Description', 'Contact.Name', 'Account.Name'],
            $config->getComponentByType(GridFieldSearch::class)->getSearchFields()
        );
        self::assertSame([BudgetEntry::class => BudgetEntry::singleton()->i18n_singular_name()], $config->getComponentByType(GridFieldAddNewDropdown::class)->getClasses($grid));
        $this->assertToolbarMarkup((string)$grid->FieldHolder());
    }

    /** Checks resolved fragment ownership rather than the presence of isolated controls. */
    private function assertToolbarMarkup(string $html): void
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        self::assertCount(7, $xpath->query('//*[contains(@class, "gridfield-pro-toolbar__group--left")]//button'));
        self::assertCount(1, $xpath->query('//*[contains(@class, "gridfield-pro-toolbar__group--right")]//input[contains(@class, "gridfield-search__input")]'));
        self::assertCount(1, $xpath->query(
            '//*[contains(@class, "gridfield-pro-toolbar__group--right")]'
            . '/*[not(contains(@class, "gridfield-pro-toolbar__item--grow"))]'
            . '[.//input[contains(@class, "gridfield-search__input")]]'
            . '/following-sibling::*[1]//a[contains(@class, "gridfield-add-new-dropdown__direct")]'
        ));
        self::assertCount(1, $xpath->query('//*[contains(@class, "gridfield-pro-toolbar__group--right")]//a[contains(@class, "gridfield-add-new-dropdown__direct")]'));
        self::assertStringNotContainsString('$DefineFragment', $html);
    }

    /** Searches each configured field while retaining owner scope and resetting native paging. */
    public function testToolbarSearch(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($owner);
        $entry = BudgetEntry::create([
            'Description' => 'Needle booking', 'Amount' => '10.00', 'Type' => 'fixed', 'Months' => '01',
            'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
            'ContactID' => $this->idFromFixture(Person::class, 'other'),
        ]);
        $entry->write();
        $list = BudgetEntry::get()->filter('OwnerID', $owner->ID);
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $grid = GridField::create('Entries', '', $list, $config);
        $search = $config->getComponentByType(GridFieldSearch::class);
        foreach (['needle', 'Other', 'First bank', 'missing', ''] as $term) {
            $grid->State->GridFieldPaginator->currentPage = 4;
            $search->handleAction($grid, GridFieldSearch::ACTION, [], ['Entries' => ['SearchQuery' => $term]]);
            self::assertSame(
                $term === 'missing' ? [] : [$entry->ID],
                $search->getManipulatedData($grid, $list)->column('ID')
            );
            self::assertSame(1, $grid->State->GridFieldPaginator->currentPage);
        }
        $search->handleAction($grid, GridFieldSearch::ACTION, [], ['Entries' => ['SearchQuery' => 'needle']]);
        self::assertSame([], $search->getManipulatedData($grid, $list->filter('Type', 'income'))->column('ID'));
        self::assertSame([], $search->getManipulatedData(
            $grid,
            BudgetEntry::get()->filter('OwnerID', $this->idFromFixture(Member::class, 'other'))
        )->column('ID'));
    }

    /** Ensures the untitled bar precedes description, amount and recurring months. */
    public function testColumns(): void
    {
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $entry = BudgetEntry::create(['Type' => 'income', 'Amount' => '123.45']);
        $grid = GridField::create('Entries', 'Entries', ArrayList::create([$entry]), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class)->getDisplayFields($grid);
        $this->assertSame(
            array_merge(['AmountBar', 'Type', 'Description', 'Amount'], array_map(
                fn (int $month): string => 'Month' . sprintf('%02d', $month),
                range(1, 12)
            )),
            array_keys($columns)
        );
        $this->assertSame('', $columns['AmountBar']['title']);
        $amount = $columns['Amount']['callback']($entry);
        $this->assertInstanceOf(DBField::class, $amount);
        $this->assertEquals('123.45', $amount->getValue());
    }

    /** Uses localized two-character headings and marks only the selected months. */
    public function testMonthIndicators(): void
    {
        i18n::set_locale('de_DE');
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $entry = BudgetEntry::create(['Months' => '01,03,10,12', 'Type' => 'income']);
        $grid = GridField::create('Entries', 'Entries', ArrayList::create([$entry]), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class)->getDisplayFields($grid);
        $this->assertSame(
            ['Ja', 'Fe', 'Mä', 'Ap', 'Ma', 'Ju', 'Ju', 'Au', 'Se', 'Ok', 'No', 'De'],
            array_column(array_slice($columns, 4), 'title')
        );
        foreach (range(1, 12) as $month) {
            $cell = $columns['Month' . sprintf('%02d', $month)]['callback']($entry);
            $this->assertInstanceOf(DBField::class, $cell);
            $this->assertSame(in_array($month, [1, 3, 10, 12], true), str_contains($cell->forTemplate(), 'font-icon-plus-circled'));
        }
        $this->assertStringContainsString('aria-label="Januar: Einnahme"', $columns['Month01']['callback']($entry)->forTemplate());
        $this->assertStringContainsString('household-budget-type-icon--income', $columns['Month01']['callback']($entry)->forTemplate());
        $entry->Type = 'fixed';
        $expense = $columns['Month01']['callback']($entry)->forTemplate();
        $this->assertStringContainsString('font-icon-minus-circle', $expense);
        $this->assertStringContainsString('household-budget-type-icon--expense', $expense);
        $this->assertSame('', $columns['Month02']['callback']($entry)->forTemplate());
        $entry->Type = 'transfer';
        $transfer = $columns['Month01']['callback']($entry)->forTemplate();
        $this->assertStringContainsString('font-icon-sync', $transfer);
        $this->assertStringContainsString('household-budget-type-icon--transfer', $transfer);
        $entry->Months = '';
        $this->assertSame('', $columns['Month01']['callback']($entry)->forTemplate());
    }

    /** Shows distinct, accessible category icons while retaining category colors. */
    public function testCategoryIcons(): void
    {
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $entry = BudgetEntry::create();
        $grid = GridField::create('Entries', 'Entries', ArrayList::create([$entry]), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class)->getDisplayFields($grid);
        foreach ([
            'income' => 'plus-circled', 'fixed' => 'lock', 'variable' => 'sliders',
            'periodic' => 'calendar', 'transfer' => 'sync', 'exceptional' => 'attention',
        ] as $type => $icon) {
            $entry->Type = $type;
            $html = $columns['Type']['callback']($entry)->forTemplate();
            $this->assertStringContainsString('font-icon-' . $icon, $html);
            $this->assertStringContainsString('aria-label="' . $entry->getTypeLabel() . '"', $html);
            $color = in_array($type, ['income', 'transfer'], true) ? $type : 'expense';
            $this->assertStringContainsString('household-budget-type-icon--' . $color, $html);
        }
    }

    /** Renders an optional smaller counterparty line while escaping both values. */
    public function testDescriptionWithContact(): void
    {
        $contact = $this->objFromFixture(Person::class, 'other');
        $contact->Name = 'Company <script>alert(1)</script> & Co';
        $entry = BudgetEntry::create(['Description' => 'Rent <b>office</b>']);
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $grid = GridField::create('Entries', 'Entries', ArrayList::create([$entry]), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class)->getDisplayFields($grid);
        $render = $columns['Description']['callback'];
        $this->assertSame('Rent &lt;b&gt;office&lt;/b&gt;', $render($entry)->forTemplate());
        $entry->setComponent('Contact', $contact);
        $this->assertSame(
            'Rent &lt;b&gt;office&lt;/b&gt;<br><small>'
                . 'Company &lt;script&gt;alert(1)&lt;/script&gt; &amp; Co</small>',
            $render($entry)->forTemplate()
        );
    }

    /** Displays compact month limits on the description line without adding columns. */
    public function testMonthBoundsBadges(): void
    {
        i18n::set_locale('de_DE');
        $entry = BudgetEntry::create(['Description' => 'Rent <office>']);
        $entry->setComponent('Contact', $this->objFromFixture(Person::class, 'other'));
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $grid = GridField::create('Entries', '', ArrayList::create([$entry]), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class)->getDisplayFields($grid);
        $render = $columns['Description']['callback'];
        foreach ([
            ['2026-05', '', 'ab 05/2026'],
            ['', '2027-08', 'bis 08/2027'],
            ['2026-05', '2027-08', '05/2026–08/2027'],
            ['2026-05', '2026-05', '05/2026–05/2026'],
        ] as [$start, $end, $label]) {
            $entry->StartMonth = $start;
            $entry->EndMonth = $end;
            $html = $render($entry)->forTemplate();
            $this->assertStringContainsString(
                'Rent &lt;office&gt; <span class="household-budget-month-bounds">' . $label . '</span><br><small>',
                $html
            );
        }
        $entry->StartMonth = '';
        $entry->EndMonth = '';
        $this->assertStringNotContainsString('household-budget-month-bounds', $render($entry)->forTemplate());
    }

    /** Scales expenses and income independently against all source rows. */
    public function testAmountBars(): void
    {
        $entries = ArrayList::create([
            BudgetEntry::create(['Type' => 'fixed', 'Amount' => '200']),
            BudgetEntry::create(['Type' => 'variable', 'Amount' => '50']),
            BudgetEntry::create(['Type' => 'income', 'Amount' => '1000']),
            BudgetEntry::create(['Type' => 'income', 'Amount' => '500']),
        ]);
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $grid = GridField::create('Entries', 'Entries', $entries, $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class)->getDisplayFields($grid);
        $render = $columns['AmountBar']['callback'];
        foreach ([50, 12.5, 50, 25] as $index => $width) {
            $entry = $entries->toArray()[$index];
            $html = $render($entry, 'AmountBar', $grid)->forTemplate();
            $this->assertStringContainsString('width:' . number_format($width, 6, '.', '') . '%', $html);
            $this->assertStringContainsString('household-budget-amount-bar__fill--' . ($entry->Type === 'income' ? 'income' : 'expense'), $html);
        }
        $grid->State->GridFieldPaginator->currentPage = 2;
        $grid->State->BudgetEntryTypeFilter = 'fixed';
        $this->assertStringContainsString('width:12.500000%', $render($entries->toArray()[1], 'AmountBar', $grid)->forTemplate());
    }

    /** Handles absent or zero-valued scales without division by zero. */
    public function testEmptyAmountScale(): void
    {
        $entry = BudgetEntry::create(['Type' => 'income', 'Amount' => '0']);
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $grid = GridField::create('Entries', 'Entries', ArrayList::create([$entry]), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class)->getDisplayFields($grid);
        $this->assertStringContainsString('width:0.000000%', $columns['AmountBar']['callback']($entry, 'AmountBar', $grid)->forTemplate());
        $entry->Type = 'fixed';
        $this->assertStringContainsString('width:0.000000%', $columns['AmountBar']['callback']($entry, 'AmountBar', $grid)->forTemplate());
    }

    /** Database scales include off-page bookings but exclude other owners' amounts. */
    public function testDatabaseAmountScale(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        foreach (['owner' => ['first', '200'], 'other' => ['other', '10000']] as $name => [$account, $amount]) {
            Security::setCurrentUser($this->objFromFixture(Member::class, $name));
            BudgetEntry::create([
                'Description' => 'Expense', 'Type' => 'fixed', 'Amount' => $amount, 'Months' => '01',
                'AccountID' => $this->idFromFixture(BankAccount::class, $account),
            ])->write();
        }
        Security::setCurrentUser($owner);
        $config = GridFieldConfig_BudgetEntriesInHouseholdBudget::create();
        $grid = GridField::create('Entries', 'Entries', BudgetEntry::get()->filter('OwnerID', $owner->ID), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class)->getDisplayFields($grid);
        $entry = BudgetEntry::create(['Type' => 'fixed', 'Amount' => '50']);
        $this->assertStringContainsString('width:12.500000%', $columns['AmountBar']['callback']($entry, 'AmountBar', $grid)->forTemplate());
    }
}
