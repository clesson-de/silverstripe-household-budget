<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Controllers;

use Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers the independent annual lines and private account/year selection.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class MonthlyBudgetLineChartTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /** Renders twelve unstacked values per series with negative balance and neutral transfers. */
    public function testAnnualLines(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $accountID = $this->idFromFixture(BankAccount::class, 'first');
        foreach (['income' => '100.25', 'fixed' => '150.00', 'transfer' => '20.00'] as $type => $amount) {
            BudgetEntry::create([
                'Description' => $type, 'Type' => $type, 'Amount' => $amount,
                'Months' => '01,12', 'AccountID' => $accountID,
            ])->write();
        }
        $member = Security::getCurrentUser();
        $member->Locale = 'de_DE';
        $member->write();
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', [
            'year' => (string)((int)date('Y') + 1), 'accounts' => (string)$accountID,
        ]));
        $html = $controller->linechart()->forTemplate();
        preg_match('/data-chart-config="([^"]+)"/', $html, $matches);
        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('line', $config['type']);
        $this->assertFalse($config['options']['scales']['y']['stacked']);
        $this->assertCount(12, $config['data']['labels']);
        $this->assertSame('Januar', $config['data']['labels'][0]);
        $this->assertCount(4, $config['data']['datasets']);
        foreach ($config['data']['datasets'] as $index => $dataset) {
            $this->assertCount(12, $dataset['data']);
            $this->assertEquals([100.25, 150, -49.75, 20][$index], $dataset['data'][0]);
            $this->assertEquals(0, $dataset['data'][1]);
            $this->assertEquals($dataset['data'][0], $dataset['data'][11]);
            $this->assertArrayNotHasKey('stack', $dataset);
            $this->assertFalse($dataset['fill']);
            $this->assertSame($dataset['backgroundColor'], $dataset['borderColor']);
        }
        $this->assertSame('#2093e5', $config['data']['datasets'][3]['borderColor']);
        $this->assertStringContainsString('household-budget-api/linechart', $html);
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
    }

    /** Rejects another member's account rather than exposing or silently including it. */
    public function testForeignAccountRejected(): void
    {
        Security::setCurrentUser($this->objFromFixture(Member::class, 'owner'));
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', [
            'accounts' => (string)$this->idFromFixture(BankAccount::class, 'other'),
        ]));
        $this->expectException(HTTPResponse_Exception::class);
        $controller->linechart();
    }

    /** Requires authentication for the additional dashboard resource. */
    public function testAnonymousAccessRejected(): void
    {
        Security::setCurrentUser(null);
        $this->expectException(HTTPResponse_Exception::class);
        MonthlyBudgetController::create()->linechart();
    }
}
