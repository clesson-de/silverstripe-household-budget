<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Forms;

use Clesson\Silverstripe\HouseholdBudget\Forms\GridField\GridFieldFilter_BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Forms\GridFieldConfig_BudgetEntriesInHouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\View\SSViewer;

/**
 * Covers exclusive booking type buttons and filtering before pagination.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetEntryTypeFilterTest extends SapphireTest
{
    /** Creates a grid containing both booking types. */
    private function grid(): GridField
    {
        Controller::config()->set('url_segment', 'budget-filter-test');
        $grid = GridField::create('Entries', 'Entries', ArrayList::create([
            BudgetEntry::create(['ID' => 1, 'Type' => 'income']),
            BudgetEntry::create(['ID' => 2, 'Type' => 'fixed']),
        ]), GridFieldConfig_BudgetEntriesInHouseholdBudget::create());
        Form::create(Controller::create(), 'TestForm', FieldList::create($grid), FieldList::create());
        return $grid;
    }

    /** Filters either type, resets the page and restores all entries. */
    public function testFiltering(): void
    {
        $grid = $this->grid();
        $filter = $grid->getConfig()->getComponentByType(GridFieldFilter_BudgetEntryType::class);
        $this->assertCount(2, $filter->getManipulatedData($grid, $grid->getList()));
        foreach (['income' => 1, 'fixed' => 2] as $type => $id) {
            $grid->State->GridFieldPaginator->currentPage = 3;
            $filter->handleAction($grid, $filter->getActions($grid)[0], ['Type' => $type], []);
            $this->assertSame([$id], $filter->getManipulatedData($grid, $grid->getList())->column('ID'));
            $this->assertSame(1, $grid->State->GridFieldPaginator->currentPage);
        }
        $filter->handleAction($grid, $filter->getActions($grid)[0], ['Type' => ''], []);
        $this->assertCount(2, $filter->getManipulatedData($grid, $grid->getList()));
        $classes = array_map(fn ($component): string => get_class($component), $grid->getConfig()->getComponents()->toArray());
        $this->assertLessThan(array_search(GridFieldPaginator::class, $classes), array_search(get_class($filter), $classes));
    }

    /** Renders exactly three AJAX buttons with only the selected type active. */
    public function testButtons(): void
    {
        SSViewer::set_themes(['silverstripe/admin:cms-forms', '$default']);
        $grid = $this->grid();
        $filter = $grid->getConfig()->getComponentByType(GridFieldFilter_BudgetEntryType::class);
        $html = $filter->getHTMLFragments($grid)['before'];
        $this->assertSame(7, substr_count($html, '<button'));
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
        $this->assertSame(7, substr_count($html, 'no-change-track'));
        $this->assertStringContainsString('action_gridFieldAlterAction', $html);
        $filter->handleAction($grid, $filter->getActions($grid)[0], ['Type' => 'fixed'], []);
        $this->assertSame(1, substr_count($filter->getHTMLFragments($grid)['before'], 'aria-pressed="true"'));
    }

    /** Rejects unsupported filter values explicitly. */
    public function testInvalidType(): void
    {
        $grid = $this->grid();
        $filter = $grid->getConfig()->getComponentByType(GridFieldFilter_BudgetEntryType::class);
        $this->expectException(HTTPResponse_Exception::class);
        $filter->handleAction($grid, $filter->getActions($grid)[0], ['Type' => 'invalid'], []);
    }

    /** Each new category is independently filterable, including transfers. */
    public function testCategoryFilters(): void
    {
        $grid = $this->grid();
        $types = ['income', 'fixed', 'variable', 'periodic', 'transfer', 'exceptional'];
        $grid->setList(new ArrayList(array_map(
            fn (string $type): BudgetEntry => BudgetEntry::create(['Type' => $type]),
            $types
        )));
        $filter = $grid->getConfig()->getComponentByType(GridFieldFilter_BudgetEntryType::class);
        foreach ($types as $type) {
            $filter->handleAction($grid, $filter->getActions($grid)[0], ['Type' => $type], []);
            $this->assertSame([$type], $filter->getManipulatedData($grid, $grid->getList())->column('Type'));
        }
    }
}
