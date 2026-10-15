<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Services;

use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportEntry;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetReportService;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetPeriodCalculator;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use DateTimeImmutable;
use InvalidArgumentException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers private account selection and immutable report creation.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetReportServiceTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /**
     * Includes only the signed-in member's selected linked accounts and entries.
     */
    public function testOwnerAndAccountIsolation(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($owner);
        $firstID = $this->idFromFixture(BankAccount::class, 'first');
        $secondID = $this->idFromFixture(BankAccount::class, 'second');
        $otherID = $this->idFromFixture(BankAccount::class, 'other');
        $this->writeEntry('First account', $firstID, '100.25');
        $this->writeEntry('Second account', $secondID, '200.50');
        Security::setCurrentUser($this->objFromFixture(Member::class, 'other'));
        $this->writeEntry('Other owner', $otherID, '900.00');
        Security::setCurrentUser($owner);
        $createdAt = new DateTimeImmutable('2026-10-14T12:00:00+00:00');
        $report = (new BudgetReportService())->create(
            $owner, new BudgetReportPeriod('2026-01', '2026-01'), [$firstID, $secondID], $createdAt
        );
        self::assertSame($createdAt, $report->createdAt);
        self::assertSame(30075, $report->totals->incomeCents);
        self::assertCount(2, $report->accountLabels);
        self::assertCount(6, $report->months[0]->typeTotals);
        self::assertCount(2, $report->positions);
    }

    /**
     * Rejects accounts linked to another contact or not selected by the member.
     */
    public function testForeignAccountSelectionIsRejected(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        $service = new BudgetReportService();
        $period = new BudgetReportPeriod('2026-01', '2026-01');
        $this->expectException(InvalidArgumentException::class);
        $service->create($owner, $period, [$this->idFromFixture(BankAccount::class, 'other')]);
    }

    /**
     * Rejects an empty account selection and exposes no accounts for unlinked members.
     */
    public function testEmptySelectionAndUnlinkedMember(): void
    {
        $member = $this->objFromFixture(Member::class, 'unlinked');
        $service = new BudgetReportService();
        self::assertCount(0, $service->accountsFor($member));
        $this->expectException(InvalidArgumentException::class);
        $service->create($member, new BudgetReportPeriod('2026-01', '2026-01'), []);
    }

    /**
     * Derives a month from a yearly report while preserving snapshot metadata and entries.
     */
    public function testContainedMonthUsesTheExistingSnapshot(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        Security::setCurrentUser($owner);
        $accountID = $this->idFromFixture(BankAccount::class, 'first');
        $this->writeEntry('Annual booking', $accountID, '125.00');
        $createdAt = new DateTimeImmutable('2026-10-14T12:00:00+00:00');
        $service = new BudgetReportService(new BudgetPeriodCalculator());
        $annual = $service->create(
            $owner,
            new BudgetReportPeriod('2026-01', '2026-12'),
            [$accountID],
            $createdAt
        );
        $month = $service->createContainedPeriod($annual, new BudgetReportPeriod('2026-01', '2026-01'));

        self::assertSame($createdAt, $month->createdAt);
        self::assertSame($annual->locale, $month->locale);
        self::assertSame($annual->accountLabels, $month->accountLabels);
        self::assertSame(12500, $month->totals->incomeCents);
        self::assertCount(1, $month->positions);
        self::assertSame($annual->positions[0]->entry, $month->positions[0]->entry);
    }

    /**
     * Saves one fixture booking while keeping its owner tied to the active member.
     */
    private function writeEntry(string $description, int $accountID, string $amount): void
    {
        BudgetEntry::create([
            'Description' => $description, 'Amount' => $amount, 'Type' => 'income',
            'Months' => '01', 'AccountID' => $accountID,
        ])->write();
    }
}
