<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\Contacts\Models\Contact;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField;
use Clesson\Silverstripe\HouseholdBudget\Exceptions\InvalidBudgetReportAccountSelection;
use Clesson\Silverstripe\HouseholdBudget\Helpers\BudgetMoney;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportEntry;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use Clesson\Silverstripe\HouseholdBudget\Reports\HouseholdBudgetReport;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use DateTimeImmutable;
use InvalidArgumentException;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;

/**
 * Loads private report inputs once and delegates all calculations to the pure calculator.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class BudgetReportService
{
    private BudgetPeriodCalculator $calculator;

    /**
     * Uses the renderer-independent calculator for all report arithmetic.
     */
    public function __construct(?BudgetPeriodCalculator $calculator = null)
    {
        $this->calculator = $calculator ?? new BudgetPeriodCalculator();
    }

    /**
     * Returns only bank accounts linked to the authenticated member's contact.
     */
    public function accountsFor(Member $member): DataList
    {
        $contactID = (int)$member->Contact()->ID;
        $accounts = BankAccount::get()->filter(BudgetEntryField::CONTACT_ID, $contactID);
        return $contactID > 0 ? $accounts : $accounts->filter('ID', 0);
    }

    /**
     * Builds a report from strictly selected accounts owned through the member's contact.
     *
     * @param list<int> $accountIDs
     */
    public function create(
        Member $member,
        BudgetReportPeriod $period,
        array $accountIDs,
        ?DateTimeImmutable $createdAt = null
    ): HouseholdBudgetReport {
        $accounts = $this->selectedAccounts($member, $accountIDs);
        $accountLabels = $this->accountLabels($accounts);
        $snapshots = $this->entrySnapshots($member, $accounts);
        return $this->report($period, $createdAt, $member, $accountLabels, $snapshots);
    }

    /**
     * Recalculates a contained period from an existing report's immutable booking snapshots.
     */
    public function createContainedPeriod(
        HouseholdBudgetReport $source,
        BudgetReportPeriod $period
    ): HouseholdBudgetReport {
        $this->assertContainedPeriod($source, $period);
        return $this->reportFromSnapshot($source, $period);
    }

    /**
     * Rejects any requested month that is not covered by the source snapshot.
     */
    private function assertContainedPeriod(HouseholdBudgetReport $source, BudgetReportPeriod $period): void
    {
        if (array_diff($period->months, $source->period->months) !== []) {
            throw new InvalidArgumentException('The requested report period must be contained in its source snapshot.');
        }
    }

    /**
     * Calculates new period totals without querying the database or losing source metadata.
     */
    private function reportFromSnapshot(
        HouseholdBudgetReport $source,
        BudgetReportPeriod $period
    ): HouseholdBudgetReport {
        $entries = [];
        foreach ($source->positions as $position) {
            $entries[$position->entry->id] = $position->entry;
        }
        $result = $this->calculator->calculate(array_values($entries), $period);
        return new HouseholdBudgetReport(
            $period,
            $source->createdAt,
            $source->locale,
            $source->accountLabels,
            $result['months'],
            $result['years'],
            $result['yearMonthCounts'],
            $result['totals'],
            $result['positions'],
            $result['categories'],
            $result['regularPositions'],
            $result['exceptionalPositions'],
            $result['transfers']
        );
    }

    /**
     * Rejects empty, duplicate, malformed, or foreign account selections.
     *
     * @param list<int> $accountIDs
     */
    private function selectedAccounts(Member $member, array $accountIDs): ArrayList
    {
        $accounts = $this->accountsFor($member)->toArray();
        $allowed = array_map(fn (BankAccount $account): int => (int)$account->ID, $accounts);
        if ($accountIDs === [] || array_filter($accountIDs, fn (mixed $id): bool => !is_int($id) || $id < 1)
            || count(array_unique($accountIDs)) !== count($accountIDs)
            || array_diff($accountIDs, $allowed)) {
            throw new InvalidBudgetReportAccountSelection('Select one or more bank accounts linked to your profile.');
        }
        return new ArrayList(array_values(array_filter(
            $accounts,
            fn (BankAccount $account): bool => in_array((int)$account->ID, $accountIDs, true)
        )));
    }

    /**
     * Maps selected account IDs to their display labels without exposing IDs in the report.
     *
     * @return array<int, string>
     */
    private function accountLabels(ArrayList $accounts): array
    {
        $labels = [];
        foreach ($accounts as $account) {
            $labels[(int)$account->ID] = (string)$account->Summary;
        }
        return $labels;
    }

    /**
     * Reads only this member's entries in their explicitly selected own accounts.
     *
     * @param ArrayList<BankAccount> $accounts
     * @return list<BudgetReportEntry>
     */
    private function entrySnapshots(Member $member, ArrayList $accounts): array
    {
        $ids = array_map('intval', $accounts->column('ID'));
        $entries = BudgetEntry::get()
            ->filter(BudgetEntryField::OWNER_ID, $member->ID)
            ->filter(BudgetEntryField::ACCOUNT_ID, $ids);
        $rows = $entries->toArray();
        $counterparties = $this->counterpartyNames($rows);
        $labels = $this->accountLabels($accounts);
        return array_map(
            fn (BudgetEntry $entry): BudgetReportEntry => $this->snapshot($entry, $labels, $counterparties),
            $rows
        );
    }

    /**
     * Loads distinct counterparty names in one query for all selected entries.
     *
     * @param list<BudgetEntry> $entries
     * @return array<int, string>
     */
    private function counterpartyNames(array $entries): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (BudgetEntry $entry): int => (int)$entry->getField(BudgetEntryField::CONTACT_ID), $entries)
        )));
        $contacts = $ids === [] ? [] : Contact::get()->filter('ID', $ids)->toArray();
        $names = [];
        foreach ($contacts as $contact) {
            $names[(int)$contact->ID] = (string)$contact->getTitle();
        }
        return $names;
    }

    /**
     * Creates one immutable booking snapshot using only already loaded display data.
     *
     * @param array<int, string> $labels
     * @param array<int, string> $counterparties
     */
    private function snapshot(BudgetEntry $entry, array $labels, array $counterparties): BudgetReportEntry
    {
        return new BudgetReportEntry(
            (int)$entry->ID, (string)$entry->Description, (string)$entry->Type,
            BudgetMoney::toCents((string)$entry->getField(BudgetEntryField::AMOUNT)),
            array_values(array_filter(explode(',', (string)$entry->getField(BudgetEntryField::MONTHS)))),
            (string)$entry->getField(BudgetEntryField::START_MONTH),
            (string)$entry->getField(BudgetEntryField::END_MONTH),
            $labels[(int)$entry->getField(BudgetEntryField::ACCOUNT_ID)] ?? '',
            $counterparties[(int)$entry->getField(BudgetEntryField::CONTACT_ID)] ?? ''
        );
    }

    /**
     * Combines fixed-time metadata with the result of renderer-independent calculations.
     *
     * @param array<int, string> $accountLabels
     * @param list<BudgetReportEntry> $entries
     */
    private function report(
        BudgetReportPeriod $period,
        ?DateTimeImmutable $createdAt,
        Member $member,
        array $accountLabels,
        array $entries
    ): HouseholdBudgetReport {
        $result = $this->calculator->calculate($entries, $period);
        return new HouseholdBudgetReport(
            $period, $createdAt ?? new DateTimeImmutable(), $member->Locale ?: i18n::get_locale(),
            array_values($accountLabels), $result['months'], $result['years'], $result['yearMonthCounts'],
            $result['totals'], $result['positions'], $result['categories'], $result['regularPositions'],
            $result['exceptionalPositions'], $result['transfers']
        );
    }
}
