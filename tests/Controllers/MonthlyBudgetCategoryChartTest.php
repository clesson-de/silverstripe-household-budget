<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Controllers;

use Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\HouseholdBudget\Services\MonthlyBudgetService;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers selected-month category totals and the reloadable private chart.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class MonthlyBudgetCategoryChartTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /** Includes active selected categories and subtracts all expense types for the balance. */
    public function testSelectedMonthTotals(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($owner);
        $year = (int)date('Y') + 1;
        $accountID = $this->idFromFixture(BankAccount::class, 'first');
        foreach (['income' => '3000.25', 'fixed' => '1000.00', 'variable' => '400.61',
            'transfer' => '200.00', 'periodic' => '1200.00', 'exceptional' => '600.00'] as $type => $amount) {
            $this->booking(['Type' => $type, 'Amount' => $amount]);
        }
        $this->booking(['Amount' => '999.00', 'Months' => '11']);
        $this->booking(['Amount' => '999.00', 'EndMonth' => ($year - 1) . '-12']);
        $this->booking(['Amount' => '999.00', 'StartMonth' => ($year + 1) . '-01']);
        $this->booking(['Amount' => '999.00', 'AccountID' => $this->idFromFixture(BankAccount::class, 'second')]);
        Security::setCurrentUser($this->objFromFixture(Member::class, 'other'));
        $this->booking(['Amount' => '9999.00', 'AccountID' => $this->idFromFixture(BankAccount::class, 'other')]);
        Security::setCurrentUser($owner);
        $service = new MonthlyBudgetService();
        $data = $service->categoryChartDataFor($owner, $year, '12', [$accountID]);
        $this->assertEquals([3000.25, 1000, 400.61, 200, -200.36], $data['datasets'][0]['data']);
        $this->assertSame(['#28a745', '#dc3545', '#dc3545', '#2093e5', '#ffc107'], $data['datasets'][0]['backgroundColor']);
        $this->assertEquals([0, 0, 0, 0, 0], $service->categoryChartDataFor($owner, $year, '01', [$accountID])['datasets'][0]['data']);
        $this->assertEquals([0, 0, 0, 0, 0], $service->categoryChartDataFor($owner, $year, '12', [])['datasets'][0]['data']);
    }

    /** Defaults to the current month and offers the following year without changing annual charts. */
    public function testMonthControlsAndChart(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        $owner->Locale = 'de_DE';
        $owner->write();
        Security::setCurrentUser($owner);
        $controller = MonthlyBudgetController::create();
        $html = $controller->categorychart()->forTemplate();
        $this->assertStringContainsString('value="' . date('m') . '" selected="selected"', $html);
        $this->assertStringContainsString('value="' . date('Y') . '" selected="selected"', $html);
        $controller->setRequest(new HTTPRequest('GET', '/', ['year' => (string)((int)date('Y') + 1), 'month' => '12']));
        $html = $controller->categorychart()->forTemplate();
        $this->assertStringContainsString('value="12" selected="selected"', $html);
        $this->assertStringContainsString('value="' . ((int)date('Y') + 1) . '" selected="selected"', $html);
        $this->assertStringContainsString('household-budget-api/categorychart', $html);
        preg_match('/data-chart-config="([^"]+)"/', $html, $matches);
        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('bar', $config['type']);
        $this->assertFalse($config['options']['scales']['y']['stacked']);
        $this->assertSame(
            ['Einnahme', 'Regelmäßige Fixkosten', 'Beeinflussbare Ausgaben', 'Umbuchung/Rücklage', 'Saldo'],
            $config['data']['labels']
        );
    }

    /** Rejects foreign account selections consistently with the other private panels. */
    public function testForeignAccountRejected(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', [
            'accounts' => (string)$this->idFromFixture(BankAccount::class, 'other'),
        ]));
        $this->expectException(HTTPResponse_Exception::class);
        $controller->categorychart();
    }

    /** Rejects unsupported months instead of silently falling back to the current month. */
    public function testInvalidMonthRejected(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', ['month' => '13']));
        $this->expectException(HTTPResponse_Exception::class);
        $controller->categorychart();
    }

    /** Creates a December booking under the currently authenticated fixture member. */
    private function booking(array $values): void
    {
        BudgetEntry::create(array_merge([
            'Description' => 'Booking', 'Type' => 'fixed', 'Amount' => '10.00', 'Months' => '12',
            'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
        ], $values))->write();
    }
}
