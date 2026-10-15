<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Controllers;

use Clesson\Silverstripe\HouseholdBudget\Constants\HouseholdBudgetPermission;
use Clesson\Silverstripe\HouseholdBudget\Controllers\HouseholdBudgetReportController;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Covers report authorization, request validation, and private file headers.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class HouseholdBudgetReportControllerTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /**
     * Rejects anonymous requests, missing permission, and invalid CSRF tokens.
     */
    public function testAuthorizationAndTokenChecks(): void
    {
        $member = $this->objFromFixture(Member::class, 'owner');
        $controller = new HouseholdBudgetReportController();
        self::assertSame(403, $controller->download($this->request([]))->getStatusCode());
        Security::setCurrentUser($member);
        self::assertSame(403, $controller->download($this->request([]))->getStatusCode());
        $this->grantReportPermission($member);
        self::assertSame(405, $controller->download(new HTTPRequest('GET', '/'))->getStatusCode());
        self::assertSame(403, $controller->download($this->request(['SecurityID' => 'invalid']))->getStatusCode());
    }

    /**
     * Rejects invalid ranges and selected accounts with useful JSON responses.
     */
    public function testInvalidRequestValues(): void
    {
        $member = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($member);
        $this->grantReportPermission($member);
        self::assertTrue(Permission::check(HouseholdBudgetPermission::ACCESS, 'any', $member));
        $invalidPeriod = $this->request($this->validPost(['startMonth' => '2026-13']));
        $response = (new HouseholdBudgetReportController())->download($invalidPeriod);
        self::assertSame(400, $response->getStatusCode(), $response->getBody());
        $invalidAccount = $this->request($this->validPost([
            'accountIDs' => [(string)$this->idFromFixture(BankAccount::class, 'other')],
        ]));
        $response = (new HouseholdBudgetReportController())->download($invalidAccount);
        self::assertSame(400, $response->getStatusCode(), $response->getBody());
    }

    /**
     * Streams a valid private PDF with a filename derived only from the range.
     */
    public function testPrivatePdfDownloadHeaders(): void
    {
        $member = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($member);
        $this->grantReportPermission($member);
        self::assertTrue(Permission::check(HouseholdBudgetPermission::ACCESS, 'any', $member));
        BudgetEntry::create([
            'Description' => 'Salary', 'Amount' => '3000.00', 'Type' => 'income',
            'Months' => '01', 'AccountID' => $this->idFromFixture(BankAccount::class, 'first'),
        ])->write();
        $response = (new HouseholdBudgetReportController())->download($this->request($this->validPost([
            'OwnerID' => (string)$this->idFromFixture(Member::class, 'other'),
        ])));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeader('Content-Type'));
        self::assertStringContainsString('attachment; filename="haushaltsbericht-2026-01-bis-2026-01.pdf"', $response->getHeader('Content-Disposition'));
        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
        self::assertStringStartsWith('%PDF-', $response->getBody());
    }

    /**
     * Adds only the dedicated household-budget CMS permission to the member.
     */
    private function grantReportPermission(Member $member): void
    {
        $group = Group::create(['Title' => 'Budget report access', 'Code' => 'budget-report-access']);
        $group->write();
        Permission::grant((int)$group->ID, HouseholdBudgetPermission::ACCESS);
        Permission::grant((int)$group->ID, 'CMS_ACCESS_LeftAndMain');
        $member->Groups()->add($group);
        Permission::reset();
    }

    /**
     * Returns a POST request with a current or caller-provided token.
     */
    private function request(array $post): HTTPRequest
    {
        $request = new HTTPRequest('POST', '/household-budget-report/download', [], $post);
        return $request;
    }

    /**
     * Creates a valid baseline report request using this test session's token.
     */
    private function validPost(array $overrides = []): array
    {
        return array_merge([
            'SecurityID' => SecurityToken::inst()->getValue(),
            'startMonth' => '2026-01',
            'endMonth' => '2026-01',
            'accountIDs' => [(string)$this->idFromFixture(BankAccount::class, 'first')],
        ], $overrides);
    }
}
