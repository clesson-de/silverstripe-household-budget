<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Controllers;

use Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\HouseholdBudget\Panels\AnnualBudgetRankingPanel;
use Clesson\Silverstripe\HouseholdBudget\Services\MonthlyBudgetService;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers effective annual sums, private ranking filters and chart rendering.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class AnnualBudgetRankingTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /** Creates a booking owned by the selected fixture member. */
    private function booking(array $values, string $owner = 'owner', string $account = 'first'): BudgetEntry
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, $owner));
        $entry = BudgetEntry::create(array_merge([
            'Description' => 'Booking', 'Amount' => '100.25', 'Type' => 'fixed',
            'Months' => '01,02,03,04,05,06,07,08,09,10,11,12',
            'AccountID' => $this->idFromFixture(BankAccount::class, $account),
        ], $values));
        $entry->write();
        return $entry;
    }

    /** Annual order differs from monthly order and honors selected months and bounds. */
    public function testAnnualAmounts(): void
    {
        $this->booking(['Description' => 'Monthly']);
        $this->booking(['Description' => 'Once', 'Amount' => '900.00', 'Months' => '01']);
        $this->booking(['Description' => 'Bounded', 'StartMonth' => '2025-12', 'EndMonth' => '2026-02']);
        $this->booking(['Description' => 'Inactive', 'EndMonth' => '2025-12']);
        $this->booking(['Description' => 'Income', 'Type' => 'income', 'Amount' => '200.00', 'Months' => '01,10,12'], 'owner', 'second');
        $this->booking(['Description' => 'Foreign', 'Amount' => '9999.00'], 'other', 'other');
        $owner = $this->objFromFixture(Member::class, 'owner');
        $ids = [$this->idFromFixture(BankAccount::class, 'first'), $this->idFromFixture(BankAccount::class, 'second')];
        $service = new MonthlyBudgetService();
        $rows = $service->annualRankingFor($owner, 2026, $ids, '', 10);
        $this->assertSame(['Monthly', 'Once', 'Income', 'Bounded'], $rows->column('Description'));
        $this->assertSame([120300, 90000, 60000, 20050], $rows->column('AnnualCents'));
        $this->assertSame(['Monthly', 'Once'], $service->annualRankingFor($owner, 2026, $ids, '', 2)->column('Description'));
        $this->assertSame(['Income'], $service->annualRankingFor($owner, 2026, $ids, 'income', 10)->column('Description'));
        $this->assertSame(['Monthly', 'Once', 'Bounded'], $service->annualRankingFor($owner, 2026, [$ids[0]], 'fixed', 10)->column('Description'));
        $this->assertCount(0, $service->annualRankingFor($owner, 2026, [], '', 10));
    }

    /** Renders an existing horizontal ChartField with correct annual values and colors. */
    public function testChartAndControls(): void
    {
        $this->booking(['Description' => 'Income <test>', 'Type' => 'income']);
        $member = $this->objFromFixture(Member::class, 'owner');
        $member->Locale = 'de_DE';
        $member->write();
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', ['year' => '2026', 'type' => 'income']));
        $html = $controller->ranking()->forTemplate();
        preg_match('/data-chart-config="([^"]+)"/', $html, $match);
        $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('y', $config['options']['indexAxis']);
        $this->assertSame([1203], $config['data']['datasets'][0]['data']);
        $this->assertSame(['#28a745'], $config['data']['datasets'][0]['backgroundColor']);
        $this->assertSame(['Income <test> (1.203,00 €)'], $config['data']['labels']);
        $this->assertStringContainsString('Alle', $html);
        $this->assertSame(7, substr_count($html, 'household-budget-ranking__type'));
        $this->assertSame(3, substr_count($html, 'aria-pressed="true"'));
        $this->assertStringContainsString('household-budget-api/ranking', $html);
        $this->booking(['Description' => 'Expense', 'Amount' => '300.00']);
        AnnualBudgetRankingPanel::config()->set('maximum_positions', 1);
        $controller->setRequest(new HTTPRequest('GET', '/', ['type' => 'fixed']));
        $html = $controller->ranking()->forTemplate();
        preg_match('/data-chart-config="([^"]+)"/', $html, $match);
        $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Expense (3.600,00 €)'], $config['data']['labels']);
        $this->assertSame(['#dc3545'], $config['data']['datasets'][0]['backgroundColor']);
    }

    /** Returns a localized empty state instead of an empty chart. */
    public function testEmptyRanking(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'unlinked'));
        $html = MonthlyBudgetController::create()->ranking()->forTemplate();
        $this->assertStringNotContainsString('chart-field-canvas', $html);
        $this->assertStringContainsString('No bookings for this selection.', $html);
    }

    /** The configurable ranking limit defaults to ten and supports YAML overrides. */
    public function testConfiguredLimit(): void
    {
        $this->assertSame(10, AnnualBudgetRankingPanel::maximumPositions());
        AnnualBudgetRankingPanel::config()->set('maximum_positions', 3);
        $this->assertSame(3, AnnualBudgetRankingPanel::maximumPositions());
    }

    /** Ranks transfers separately with blue chart coloring. */
    public function testTransferRanking(): void
    {
        $this->booking(['Description' => 'Reserve', 'Type' => 'transfer', 'Amount' => '10.01']);
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', ['type' => 'transfer']));
        $html = $controller->ranking()->forTemplate();
        preg_match('/data-chart-config="([^"]+)"/', $html, $match);
        $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['#2093e5'], $config['data']['datasets'][0]['backgroundColor']);
        $this->assertSame([120.12], $config['data']['datasets'][0]['data']);
    }

    /** Rejects unsupported booking-type values. */
    public function testInvalidType(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', ['type' => 'invalid']));
        $this->expectException(HTTPResponse_Exception::class);
        $controller->ranking();
    }

    /** Rejects foreign account selections at the ranking endpoint. */
    public function testForeignAccount(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', [
            'accounts' => (string)$this->idFromFixture(BankAccount::class, 'other'),
        ]));
        $this->expectException(HTTPResponse_Exception::class);
        $controller->ranking();
    }

    /** Protects annual totals from unauthenticated requests. */
    public function testUnauthenticated(): void
    {
        Security::setCurrentUser(null);
        $this->expectException(HTTPResponse_Exception::class);
        MonthlyBudgetController::create()->ranking();
    }
}
