<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Controllers;

use Clesson\Silverstripe\Contacts\Models\Person;
use Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\HouseholdBudget\Services\MonthlyBudgetService;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers complete monthly groups, selectors, exact totals and private access.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class MonthlyBudgetDetailsTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /** Creates a valid booking in the owner's workspace. */
    private function booking(string $type, string $amount, string $name, string $account = 'first'): BudgetEntry
    {
        $member = $this->objFromFixture(Member::class, 'owner');
        $member->Locale = 'de_DE';
        $member->write();
        Security::setCurrentUser($member);
        $entry = BudgetEntry::create([
            'Description' => $name, 'Type' => $type, 'Amount' => $amount,
            'Months' => date('m'), 'AccountID' => $this->idFromFixture(BankAccount::class, $account),
        ]);
        $entry->write();
        return $entry;
    }

    /** Renders the endpoint with selected query parameters. */
    private function render(array $query = []): string
    {
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', $query));
        return $controller->details()->forTemplate();
    }

    /** Shows sorted groups, precise totals and smaller escaped counterparty text. */
    public function testGroupsAndTotals(): void
    {
        $income = $this->booking('income', '170.61', 'First <script>income</script>');
        $income->ContactID = $this->idFromFixture(Person::class, 'other');
        $income->write();
        $this->booking('income', '100.00', 'Second income', 'second');
        $this->booking('fixed', '300.62', 'Expense');
        $service = new MonthlyBudgetService();
        $owner = $this->objFromFixture(Member::class, 'owner');
        $ids = [$this->idFromFixture(BankAccount::class, 'first'), $this->idFromFixture(BankAccount::class, 'second')];
        $details = $service->detailsFor($owner, (int)date('Y'), date('m'), $ids);
        $this->assertSame(['First <script>income</script>', 'Second income'], $details->IncomeEntries->column('Description'));
        $this->assertSame(27061, $details->IncomeCents);
        $this->assertSame(30062, $details->ExpensesCents);
        $this->assertSame(-3001, $details->BalanceCents);
        $summary = $service->summariesFor($owner, (int)date('Y'), $ids)->toArray()[(int)date('m') - 1];
        $this->assertSame($summary->BalanceCents, $details->BalanceCents);
        $html = $this->render();
        $this->assertStringContainsString('First &lt;script&gt;income&lt;/script&gt;', $html);
        $this->assertStringContainsString('<small>Other</small>', $html);
        $this->assertStringContainsString('270,61', $html);
        $this->assertStringContainsString('300,62', $html);
        $this->assertStringContainsString('-30,01', $html);
        $this->assertStringContainsString('household-budget-details__balance--negative', $html);
        $this->assertSame(2, substr_count($html, '<tfoot>'));
        $this->assertLessThan(strpos($html, 'Expense</td>'), strpos($html, 'Second income'));
    }

    /** Scopes rows to selected accounts and retains the date/account controls. */
    public function testAccountSelection(): void
    {
        $this->booking('income', '170.61', 'Included');
        $this->booking('fixed', '200.00', 'Excluded', 'second');
        $html = $this->render(['accounts' => (string)$this->idFromFixture(BankAccount::class, 'first')]);
        $this->assertStringContainsString('Included', $html);
        $this->assertStringNotContainsString('Excluded', $html);
        $this->assertStringContainsString('household-budget-details__balance--positive', $html);
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
        $this->assertStringContainsString('value="' . date('m') . '" selected="selected"', $html);
        $this->assertStringContainsString('value="' . date('Y') . '" selected="selected"', $html);
    }

    /** Keeps empty sections visible with a green zero balance. */
    public function testEmptyMonth(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'unlinked'));
        $html = $this->render();
        $this->assertSame(2, substr_count($html, 'No bookings for this selection.'));
        $this->assertStringContainsString('household-budget-details__balance--positive', $html);
        $this->assertSame(3, substr_count($html, '0,00'));
    }

    /** Applies recurring months, inclusive bounds and cross-year periods. */
    public function testOccurrenceBounds(): void
    {
        $entry = $this->booking('income', '10.01', 'Bounded');
        $entry->Months = '01,12';
        $entry->StartMonth = '2025-12';
        $entry->EndMonth = '2026-01';
        $entry->write();
        $service = new MonthlyBudgetService();
        $owner = $this->objFromFixture(Member::class, 'owner');
        $ids = [$this->idFromFixture(BankAccount::class, 'first')];
        foreach ([[2025, '12', 1001], [2026, '01', 1001], [2025, '01', 0], [2026, '12', 0], [2026, '02', 0]] as $case) {
            [$year, $month, $expected] = $case;
            $this->assertSame($expected, $service->detailsFor($owner, $year, $month, $ids)->IncomeCents);
        }
    }

    /** Does not expose another member's data even through direct service account IDs. */
    public function testOwnerIsolation(): void
    {
        $this->booking('income', '99.99', 'Private booking');
        $other = $this->objFromFixture(Member::class, 'other');
        Security::setCurrentUser($other);
        $data = (new MonthlyBudgetService())->detailsFor($other, (int)date('Y'), date('m'), [
            $this->idFromFixture(BankAccount::class, 'first'),
        ]);
        $this->assertCount(0, $data->IncomeEntries);
        $this->assertStringNotContainsString('Private booking', $this->render());
    }

    /** Rejects an invalid month before querying the detail data. */
    public function testInvalidMonth(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $this->expectException(HTTPResponse_Exception::class);
        $this->render(['month' => '13']);
    }

    /** Rejects foreign accounts instead of returning success-shaped empty results. */
    public function testForeignAccount(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $this->expectException(HTTPResponse_Exception::class);
        $this->render(['accounts' => (string)$this->idFromFixture(BankAccount::class, 'other')]);
    }

    /** Requires authentication for direct endpoint access. */
    public function testUnauthenticated(): void
    {
        Security::setCurrentUser(null);
        $this->expectException(HTTPResponse_Exception::class);
        $this->render();
    }

    /** Lists malformed selectors and unsupported account choices. */
    public static function invalidSelections(): array
    {
        return [
            'future-year' => [['year' => (string)((int)date('Y') + 2)]],
            'month-array' => [['month' => ['01']]],
            'month-zero' => [['month' => '00']],
            'month-unpadded' => [['month' => '1']],
            'accounts-array' => [['accounts' => ['1']]],
            'accounts-empty' => [['accounts' => '']],
            'accounts-malformed' => [['accounts' => '1,not-an-id']],
        ];
    }

    /** Fails explicitly for invalid filter inputs. */
    #[DataProvider('invalidSelections')]
    public function testInvalidSelection(array $query): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $this->expectException(HTTPResponse_Exception::class);
        $this->render($query);
    }

    /** Does not allow accounts without a linked contact. */
    public function testUnassignedAccount(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $this->expectException(HTTPResponse_Exception::class);
        $this->render(['accounts' => (string)$this->idFromFixture(BankAccount::class, 'unassigned')]);
    }

    /** Uses the chosen month and year instead of the current month. */
    public function testSelectedDate(): void
    {
        $entry = $this->booking('income', '10.01', 'Previous January');
        $year = (int)date('Y') - 1;
        $entry->Months = '01';
        $entry->StartMonth = $year . '-01';
        $entry->EndMonth = $year . '-01';
        $entry->write();
        $html = $this->render(['year' => (string)$year, 'month' => '01']);
        $this->assertStringContainsString('Previous January', $html);
        $this->assertStringContainsString('Januar ' . $year, $html);
        $this->assertStringContainsString('value="01" selected="selected"', $html);
        $this->assertStringNotContainsString('Previous January', $this->render(['year' => (string)$year, 'month' => '02']));
    }

    /** Every panel and monthly details accepts next year and calculates its bookings. */
    public function testNextYearAcrossViews(): void
    {
        $entry = $this->booking('income', '170.61', 'Next year income');
        $year = (int)date('Y') + 1;
        $entry->StartMonth = $year . '-01';
        $entry->EndMonth = $year . '-12';
        $entry->write();
        $query = ['year' => (string)$year, 'accounts' => (string)$entry->AccountID];
        foreach (['summary', 'chart', 'ranking', 'details'] as $action) {
            $controller = MonthlyBudgetController::create();
            $controller->setRequest(new HTTPRequest('GET', '/', $query));
            $html = $controller->$action()->forTemplate();
            $this->assertStringContainsString('value="' . $year . '" selected="selected"', $html, $action);
            if ($action === 'summary' || $action === 'details') {
                $this->assertStringContainsString('170,61', $html, $action);
            } else {
                preg_match('/data-chart-config="([^"]+)"/', $html, $match);
                $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
                $this->assertContains(170.61, $config['data']['datasets'][0]['data'], $action);
            }

            if ($action !== 'summary') {
                $expectedActive = $action === 'ranking' ? 2 : 1;
                $this->assertSame($expectedActive, substr_count($html, 'aria-pressed="true"'), $action);
            }
        }
        $this->assertStringNotContainsString('Next year income', $this->render());
    }

    /** Every expense category reduces balance while transfers are listed separately. */
    public function testExpenseCategoriesAndNeutralTransfers(): void
    {
        $income = $this->booking('income', '100.00', 'Income');
        foreach (['fixed', 'variable', 'periodic', 'exceptional'] as $type) {
            $this->booking($type, '10.01', $type);
        }
        $this->booking('transfer', '999.99', 'Reserve');
        $member = $this->objFromFixture(Member::class, 'owner');
        $service = new MonthlyBudgetService();
        $data = $service->detailsFor($member, (int)date('Y'), date('m'), [(int)$income->AccountID]);
        $this->assertSame(4004, $data->ExpensesCents);
        $this->assertSame(5996, $data->BalanceCents);
        $this->assertSame(99999, $data->TransfersCents);
        $this->assertCount(4, $data->ExpenseEntries);
        $this->assertCount(1, $data->TransferEntries);
        $chart = $service->chartDataFor($member, (int)date('Y'), [(int)$income->AccountID]);
        $this->assertSame(59.96, $chart['datasets'][2]['data'][(int)date('m') - 1]);
        $this->assertSame(999.99, $chart['datasets'][3]['data'][(int)date('m') - 1]);
        $html = $this->render();
        $this->assertStringContainsString('Umbuchung/Rücklage', $html);
        $this->assertStringContainsString('Reserve', $html);
        $this->assertSame(3, substr_count($html, '<tfoot>'));
        $this->assertStringContainsString('59,96', $html);
    }
}
