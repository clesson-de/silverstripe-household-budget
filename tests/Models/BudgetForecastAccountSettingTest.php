<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Models;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastAccountSetting;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Covers owner-scoped account settings and persisted uniqueness validation.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetForecastAccountSettingTest extends SapphireTest
{
    protected static $fixture_file = 'BudgetEntryTest.yml';

    /**
     * Allows only the workspace owner to manage settings for linked accounts.
     */
    public function testOwnerAndAccountIsolation(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        $other = $this->objFromFixture(Member::class, 'other');
        $budget = $this->budget($owner);
        $setting = BudgetForecastAccountSetting::create([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $this->idFromFixture(BankAccount::class, 'first'),
        ]);

        self::assertFalse((bool)$setting->Enabled);
        self::assertTrue($setting->validate()->isValid());
        self::assertTrue($setting->canView($owner));
        self::assertTrue($setting->canEdit($owner));
        self::assertFalse($setting->canView($other));
        self::assertFalse($setting->canCreate($other, []));

        $foreign = BudgetForecastAccountSetting::create([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $this->idFromFixture(BankAccount::class, 'other'),
        ]);
        self::assertFalse($foreign->validate()->isValid());
    }

    /**
     * Rejects duplicate household/account combinations before the database unique index.
     */
    public function testDuplicatePairIsRejected(): void
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        $budget = $this->budget($owner);
        $accountID = $this->idFromFixture(BankAccount::class, 'first');
        BudgetForecastAccountSetting::create([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $accountID,
        ])->write();
        $duplicate = BudgetForecastAccountSetting::create([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $accountID,
        ]);
        self::assertFalse($duplicate->validate()->isValid());
    }

    /**
     * Creates the personal parent record independently of the active system identity.
     */
    private function budget(Member $owner): HouseholdBudget
    {
        Security::setCurrentUser($owner);
        $budget = HouseholdBudget::create([BudgetForecastField::OWNER_ID => $owner->ID]);
        $budget->write();
        return $budget;
    }
}
