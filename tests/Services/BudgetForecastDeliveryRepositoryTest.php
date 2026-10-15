<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastScope;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastStatus;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastDelivery;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastDeliveryRepository;
use DateTimeImmutable;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * Verifies database-conditional claims and token-safe delivery transitions.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetForecastDeliveryRepositoryTest extends SapphireTest
{
    /**
     * Prevents duplicate claims and rejects a stale token after a safe retry reclaims it.
     */
    public function testClaimRetryAndStaleTokenProtection(): void
    {
        $owner = Member::create(['Email' => 'owner@example.test']);
        $owner->write();
        $budget = HouseholdBudget::create([BudgetForecastField::OWNER_ID => $owner->ID]);
        $budget->write();
        $this->logInAsTechnicalAdmin();
        $repository = new BudgetForecastDeliveryRepository();
        $now = new DateTimeImmutable('2026-10-31T22:00:00+02:00');
        $first = $repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15);

        self::assertNotNull($first);
        self::assertNull($repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15));
        self::assertTrue($repository->markFailed($first, 'preparation'));
        $second = $repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15);
        self::assertNotNull($second);
        self::assertNotSame($first['token'], $second['token']);
        self::assertFalse($repository->beginSending($first, $now, 15));
        self::assertTrue($repository->beginSending($second, $now, 15));
        self::assertTrue($repository->markSent($second, $now));
        self::assertNull($repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15));
        self::assertSame(BudgetForecastStatus::SENT, BudgetForecastDelivery::get()->first()->Status);
    }

    /**
     * Rotates skipped tokens atomically, excludes competing workers and blocks every transport state.
     */
    public function testSkippedRetryRotatesTokenAndTransportStatesStayBlocked(): void
    {
        $owner = Member::create(['Email' => 'skipped-owner@example.test']);
        $owner->write();
        $budget = HouseholdBudget::create([BudgetForecastField::OWNER_ID => $owner->ID]);
        $budget->write();
        $this->logInAsTechnicalAdmin();
        $repository = new BudgetForecastDeliveryRepository();
        $now = new DateTimeImmutable('2026-10-31T22:00:00+01:00');
        $claim = $repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15);
        self::assertTrue($repository->markSkipped($claim, 'recipient'));
        $retry = $repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15);
        self::assertNotNull($retry);
        self::assertNotSame($claim['token'], $retry['token']);
        self::assertFalse($repository->markSkipped($claim, 'recipient'));
        self::assertFalse($repository->beginSending($claim, $now, 15));
        self::assertNull($repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15));
        self::assertTrue($repository->beginSending($retry, $now, 15));
        self::assertNull($repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15));
        self::assertNull($repository->uncertainDeliveryID($budget, '2026-11', BudgetForecastScope::OVERALL, 0));
        self::assertTrue($repository->markUncertain($retry, 'transport'));
        self::assertNull($repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $now, 15));
        self::assertSame($retry['id'], $repository->uncertainDeliveryID($budget, '2026-11', BudgetForecastScope::OVERALL, 0));
        self::assertFalse($repository->markSent($retry, $now));
        self::assertSame(2, (int)BudgetForecastDelivery::get()->byID($retry['id'])->AttemptCount);
    }

    /**
     * Creates an authenticated ADMIN technical identity for repository state mutations.
     */
    private function logInAsTechnicalAdmin(): void
    {
        $this->logInWithPermission('ADMIN');
    }
}
