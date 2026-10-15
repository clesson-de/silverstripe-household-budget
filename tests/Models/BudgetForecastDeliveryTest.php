<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Models;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastScope;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastStatus;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastDelivery;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * Covers delivery state validation, read-only permissions and unique variant semantics.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetForecastDeliveryTest extends SapphireTest
{
    /**
     * Accepts only valid overall/account key combinations and known statuses.
     */
    public function testScopeMonthAndStatusValidation(): void
    {
        $owner = Member::create();
        $owner->write();
        $budget = HouseholdBudget::create([BudgetForecastField::OWNER_ID => $owner->ID]);
        $budget->write();
        $delivery = $this->delivery($budget, '2026-12', BudgetForecastScope::OVERALL, 0);
        self::assertSame(BudgetForecastStatus::PREPARING, $delivery->Status);
        self::assertTrue($delivery->validate()->isValid());
        self::assertTrue($delivery->canView($owner));
        self::assertFalse($delivery->canEdit($owner));
        self::assertFalse($delivery->canDelete($owner));

        $invalid = $this->delivery($budget, '2026-13', BudgetForecastScope::OVERALL, 0);
        self::assertFalse($invalid->validate()->isValid());
        $invalidScope = $this->delivery($budget, '2026-12', BudgetForecastScope::ACCOUNT, 0);
        self::assertFalse($invalidScope->validate()->isValid());
    }

    /**
     * Keeps each owner/month/scope/account delivery key unique.
     */
    public function testDuplicateVariantIsRejected(): void
    {
        $owner = Member::create();
        $owner->write();
        $budget = HouseholdBudget::create([BudgetForecastField::OWNER_ID => $owner->ID]);
        $budget->write();
        $this->delivery($budget, '2026-12', BudgetForecastScope::OVERALL, 0)->write();
        self::assertFalse($this->delivery($budget, '2026-12', BudgetForecastScope::OVERALL, 0)->validate()->isValid());
    }

    /**
     * Creates one valid technical delivery candidate.
     */
    private function delivery(
        HouseholdBudget $budget,
        string $month,
        string $scope,
        int $accountID
    ): BudgetForecastDelivery {
        return BudgetForecastDelivery::create([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::TARGET_MONTH => $month,
            BudgetForecastField::SCOPE => $scope,
            BudgetForecastField::ACCOUNT_ID => $accountID,
            BudgetForecastField::STATUS => BudgetForecastStatus::PREPARING,
            BudgetForecastField::CLAIM_TOKEN => str_repeat('a', 64),
        ]);
    }
}
