<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Forms;

use Clesson\Silverstripe\HouseholdBudget\Services\BudgetReportService;
use SilverStripe\Control\Director;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use SilverStripe\Security\SecurityToken;

/**
 * Renders non-persisted report options inside the household workspace.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Forms
 */
final class HouseholdBudgetReportField extends LiteralField
{
    /**
     * Embeds report controls without adding a nested HTML form.
     */
    public function __construct(string $name, Member $member)
    {
        $service = new BudgetReportService();
        parent::__construct($name, (string)$this->templateData($member, $service)->renderWith(
            'Clesson\Silverstripe\HouseholdBudget\Forms\HouseholdBudgetReportField'
        ));
    }

    /**
     * Creates localized controls and selects every account linked to this member.
     */
    private function templateData(Member $member, BudgetReportService $service): ArrayData
    {
        $accounts = $this->accountOptions($service->accountsFor($member));
        return ArrayData::create([
            'Accounts' => $accounts,
            'HasAccounts' => $accounts->count() > 0,
            'CurrentYear' => date('Y'),
            'DownloadURL' => Director::absoluteURL('household-budget-report/download'),
            'SecurityToken' => SecurityToken::inst()->getValue(),
        ] + $this->labels());
    }

    /**
     * Returns translated field labels and user-facing status messages.
     */
    private function labels(): array
    {
        return [
            'Title' => _t(__CLASS__ . '.TITLE', 'Household budget report'),
            'Description' => _t(__CLASS__ . '.DESCRIPTION', 'Create a private PDF from your recorded household budget bookings.'),
            'StartMonthLabel' => _t(__CLASS__ . '.START_MONTH', 'Start month'),
            'EndMonthLabel' => _t(__CLASS__ . '.END_MONTH', 'End month'),
            'AccountsLabel' => _t(__CLASS__ . '.ACCOUNTS', 'Bank accounts'),
            'SubmitLabel' => _t(__CLASS__ . '.DOWNLOAD', 'Download household report'),
            'NoAccounts' => _t(__CLASS__ . '.NO_ACCOUNTS', 'Link a bank account to your contact before creating a report.'),
            'SelectAccountError' => _t(__CLASS__ . '.SELECT_ACCOUNT_ERROR', 'Select at least one bank account.'),
            'InvalidRangeError' => _t(__CLASS__ . '.INVALID_RANGE', 'Enter a valid inclusive month range.'),
            'DownloadError' => _t(__CLASS__ . '.DOWNLOAD_ERROR', 'The report could not be generated. Please try again.'),
            'Processing' => _t(__CLASS__ . '.PROCESSING', 'Creating PDF...'),
        ];
    }

    /**
     * Creates escaped options for the member's available accounts.
     */
    private function accountOptions(DataList $accounts): ArrayList
    {
        return new ArrayList(array_map(fn ($account): ArrayData => ArrayData::create([
            'ID' => (int)$account->ID,
            'Title' => (string)$account->Summary,
        ]), $accounts->toArray()));
    }
}
