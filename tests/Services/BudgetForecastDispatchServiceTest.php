<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Services;

use Closure;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastErrorCode;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastField;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastScope;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastStatus;
use Clesson\Silverstripe\HouseholdBudget\Constants\HouseholdBudgetPermission;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastAccountSetting;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetForecastDelivery;
use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastDeliveryRepository;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastDispatchService;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastEmailService;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastInsightCalculator;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastService;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetPeriodCalculator;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetReportService;
use Clesson\Silverstripe\HouseholdBudget\Tasks\MonthlyBudgetForecastTask;
use Clesson\Silverstripe\PaymentAccount\Models\BankAccount;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Covers final-day-only dispatch, independent scopes, idempotency and transport isolation.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetForecastDispatchServiceTest extends SapphireTest
{
    protected static $fixture_file = '../Models/BudgetEntryTest.yml';

    /**
     * Runs the actual task in either technical locale without leaking it into the overall email.
     */
    public function testTaskLocalizesOverallMailForRecipientAndRestoresTechnicalLocale(): void
    {
        $owner = $this->prepareOwner();
        $this->enabledBudget($owner);
        $previousLocale = i18n::get_locale();
        try {
            $this->withCapturingMailer(function (object $mailer) use ($owner): void {
                $admin = Security::getCurrentUser();
                Config::modify()->set(MonthlyBudgetForecastTask::class, 'cli_user_email', (string)$admin->Email);
                foreach ([
                    ['en_US', 'de_DE', '2026-10-31', 'Alle eigenen Konten', 'Haushaltsprognose'],
                    ['de_DE', 'en_US', '2026-11-30', 'All own accounts', 'Household forecast'],
                ] as $index => [$technicalLocale, $recipientLocale, $date, $scope, $subject]) {
                    $owner->Locale = $recipientLocale;
                    $owner->write();
                    $admin->Locale = $technicalLocale;
                    $admin->write();
                    i18n::set_locale($technicalLocale);
                    [$result] = $this->executeTaskWithClock(static fn () => new DateTimeImmutable($date . 'T22:00:00+01:00'));
                    self::assertSame(Command::SUCCESS, $result);
                    self::assertCount($index + 1, $mailer->messages);
                    self::assertStringContainsString($scope, $mailer->messages[$index]->getSubject());
                    self::assertStringContainsString($subject, $mailer->messages[$index]->getSubject());
                    self::assertStringContainsString($scope, $mailer->messages[$index]->getTextBody());
                    self::assertSame($technicalLocale, i18n::get_locale());
                }
            });
        } finally {
            i18n::set_locale($previousLocale);
        }
    }

    /**
     * Rechecks uncached authorization after a concurrent permission revocation during preparation.
     */
    public function testPermissionRevokedAfterPreparationBlocksTransport(): void
    {
        $owner = $this->prepareOwner();
        $this->enabledBudget($owner);
        $clock = $this->mutatingClock(3, static function () use ($owner): void {
            self::assertTrue(Permission::check(HouseholdBudgetPermission::ACCESS, 'any', $owner));
            DB::prepared_query('DELETE FROM "Permission" WHERE "Code" = ?', [HouseholdBudgetPermission::ACCESS]);
            // The mutation deliberately leaves the framework's in-process permission cache intact.
        });
        $this->withCapturingMailer(function (object $mailer) use ($clock): void {
            $result = $this->dispatchWithClock($clock)->run();
            self::assertSame(1, $result['failed']);
            self::assertSame(0, $result['processed']);
            self::assertCount(0, $mailer->messages);
            self::assertSame(BudgetForecastErrorCode::ACCESS, BudgetForecastDelivery::get()->first()->LastErrorCode);
        });
    }

    /**
     * Includes an account linked after variant discovery but before report preparation.
     */
    public function testOverallRefreshesAccountSetBeforePreparation(): void
    {
        $owner = $this->prepareOwner();
        $this->enabledBudget($owner);
        $second = $this->prepareTemporarilyUnlinkedAccount($owner);
        $clock = $this->mutatingClock(2, fn () => $this->linkAccount($second, (int)$owner->Contact()->ID));
        $this->withCapturingMailer(function (object $mailer) use ($clock): void {
            self::assertSame(1, $this->dispatchWithClock($clock)->run()['processed']);
            self::assertCount(1, $mailer->messages);
            self::assertStringContainsString('Newly linked utility', $mailer->messages[0]->getTextBody());
            self::assertStringContainsString('€200.00', $mailer->messages[0]->getTextBody());
        });
    }

    /**
     * Invalidates a partial overall snapshot at handoff and safely retries the complete scope.
     */
    public function testOverallAccountAddedAfterPreparationRequiresFreshRetry(): void
    {
        $owner = $this->prepareOwner();
        $this->enabledBudget($owner);
        $second = $this->prepareTemporarilyUnlinkedAccount($owner);
        $clock = $this->mutatingClock(3, fn () => $this->linkAccount($second, (int)$owner->Contact()->ID));
        $this->withCapturingMailer(function (object $mailer) use ($clock): void {
            $result = $this->dispatchWithClock($clock)->run();
            self::assertSame(0, $result['processed']);
            self::assertSame(1, $result['failed']);
            self::assertCount(0, $mailer->messages);
            self::assertSame(BudgetForecastErrorCode::ACCOUNT_SCOPE, BudgetForecastDelivery::get()->first()->LastErrorCode);
            self::assertSame(1, $this->dispatchAt($this->monthEnd())->run()['processed']);
            self::assertStringContainsString('Newly linked utility', $mailer->messages[0]->getTextBody());
            self::assertSame(0, $this->dispatchAt($this->monthEnd())->run()['processed']);
            self::assertCount(1, $mailer->messages);
        });
    }

    /**
     * Retries a known pre-transport recipient skip after correcting the address, only once.
     */
    public function testCorrectedRecipientCanRetrySkippedDelivery(): void
    {
        $owner = $this->prepareOwner();
        $this->enabledBudget($owner);
        $owner->Email = '';
        $owner->write();
        $this->withCapturingMailer(function (object $mailer) use ($owner): void {
            $dispatch = $this->dispatchAt($this->monthEnd());
            self::assertSame(1, $dispatch->run()['failed']);
            self::assertSame(BudgetForecastStatus::SKIPPED, BudgetForecastDelivery::get()->first()->Status);
            $owner->Email = 'corrected@example.test';
            $owner->write();
            self::assertSame(1, $dispatch->run()['processed']);
            self::assertSame('corrected@example.test', $mailer->messages[0]->getTo()[0]->getAddress());
            self::assertSame(0, $dispatch->run()['processed']);
            self::assertCount(1, $mailer->messages);
            self::assertSame(2, (int)BudgetForecastDelivery::get()->first()->AttemptCount);
        });
    }

    /**
     * Allows the same final-day variant again when its temporarily disabled opt-in is restored.
     */
    public function testReenabledOptionCanRetrySkippedDelivery(): void
    {
        $owner = $this->prepareOwner();
        $budget = $this->enabledBudget($owner);
        $clock = $this->mutatingClock(3, static function () use ($budget): void {
            DB::prepared_query(
                'UPDATE "HouseholdBudget_HouseholdBudget" SET "CombinedForecastEmailEnabled" = ? WHERE "ID" = ?',
                [0, $budget->ID]
            );
        });
        $this->withCapturingMailer(function (object $mailer) use ($clock, $budget): void {
            self::assertSame(1, $this->dispatchWithClock($clock)->run()['skipped']);
            self::assertCount(0, $mailer->messages);
            $currentBudget = HouseholdBudget::get()->byID($budget->ID);
            $currentBudget->CombinedForecastEmailEnabled = true;
            $currentBudget->write();
            self::assertSame(1, $this->dispatchAt($this->monthEnd())->run()['processed']);
            self::assertSame(0, $this->dispatchAt($this->monthEnd())->run()['processed']);
            self::assertCount(1, $mailer->messages);
        });
    }

    /**
     * Exposes both newly discovered and existing uncertainty as logged task failures, never sends.
     */
    public function testExpiredSendingAndExistingUncertaintyFailTaskWithSafeLogs(): void
    {
        $owner = $this->prepareOwner();
        $budget = $this->enabledBudget($owner);
        $admin = $this->technicalAdmin();
        Security::setCurrentUser($admin);
        $repository = new BudgetForecastDeliveryRepository();
        $earlier = $this->monthEnd()->modify('-30 minutes');
        $claim = $repository->claim($budget, '2026-11', BudgetForecastScope::OVERALL, 0, $earlier, 15);
        self::assertTrue($repository->beginSending($claim, $earlier, 15));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('error')->with(
            'Monthly household forecast delivery failed or was skipped.',
            [
                'household_budget_id' => (int)$budget->ID,
                'delivery_id' => $claim['id'],
                'target_month' => '2026-11',
                'scope' => BudgetForecastScope::OVERALL,
                'error_code' => BudgetForecastErrorCode::TRANSPORT,
            ]
        );
        Config::modify()->set(MonthlyBudgetForecastTask::class, 'cli_user_email', (string)$admin->Email);
        $this->withCapturingMailer(function (object $mailer) use ($logger): void {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                [$result, $output] = $this->executeTaskWithClock(fn () => $this->monthEnd(), $logger);
                self::assertSame(Command::FAILURE, $result);
                self::assertStringContainsString('0 accepted, 0 skipped, 1 failed', $output);
                self::assertSame(BudgetForecastStatus::UNCERTAIN, BudgetForecastDelivery::get()->first()->Status);
            }
            self::assertCount(0, $mailer->messages);
        });
    }

    /**
     * Injects a deterministic production service graph into the real task entry point.
     *
     * @return array{int, string}
     */
    private function executeTaskWithClock(Closure $clock, ?LoggerInterface $logger = null): array
    {
        $reports = new BudgetReportService();
        Injector::inst()->load([BudgetForecastDispatchService::class => [
            'constructor' => [
                new BudgetForecastDeliveryRepository(),
                new BudgetForecastService($reports, new BudgetForecastInsightCalculator()),
                new BudgetForecastEmailService(), $reports,
                $logger ?? Injector::inst()->get(LoggerInterface::class), $clock,
            ],
        ]]);
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, BufferedOutput::VERBOSITY_NORMAL, false, $buffer);
        $execute = new ReflectionMethod(MonthlyBudgetForecastTask::class, 'execute');
        $result = $execute->invoke(new MonthlyBudgetForecastTask(), new ArrayInput([]), $output);
        return [$result, $buffer->fetch()];
    }

    /**
     * Creates an opted-in overall variant for the fixture owner.
     */
    private function enabledBudget(Member $owner): HouseholdBudget
    {
        $budget = HouseholdBudget::create([
            BudgetForecastField::OWNER_ID => $owner->ID,
            BudgetForecastField::FORECAST_EMAILS_ENABLED => true,
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED => true,
        ]);
        $budget->write();
        return $budget;
    }

    /**
     * Stores a booking before temporarily removing its account from the owner's linked set.
     */
    private function prepareTemporarilyUnlinkedAccount(Member $owner): int
    {
        Security::setCurrentUser($owner);
        $id = $this->idFromFixture(BankAccount::class, 'second');
        $this->writeEntry('Newly linked utility', BudgetEntryType::FIXED, '200.00', $id, '11');
        $this->linkAccount($id, 0);
        return $id;
    }

    /**
     * Simulates an external account-link update without touching application caches.
     */
    private function linkAccount(int $id, int $contactID): void
    {
        $table = BankAccount::getSchema()->tableForField(BankAccount::class, 'ContactID');
        DB::prepared_query('UPDATE "' . $table . '" SET "ContactID" = ? WHERE "ID" = ?', [$contactID, $id]);
    }

    /**
     * Mutates external state at variant claiming or at the post-preparation clock boundary.
     */
    private function mutatingClock(int $atCall, Closure $mutation): Closure
    {
        $calls = 0;
        return function () use (&$calls, $atCall, $mutation): DateTimeImmutable {
            if (++$calls === $atCall) {
                $mutation();
            }
            return $this->monthEnd();
        };
    }

    /**
     * Supplies one deterministic time inside the dispatch window.
     */
    private function monthEnd(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-31T22:00:00+01:00');
    }

    /**
     * Runs assertions with an administrator and an exclusively in-memory mail transport.
     */
    private function withCapturingMailer(Closure $assertions): void
    {
        Config::modify()->set(Email::class, 'admin_email', 'sender@example.test');
        Security::setCurrentUser($this->technicalAdmin());
        $injector = Injector::inst();
        $previousMailer = $injector->get(MailerInterface::class);
        $mailer = $this->installCapturingMailer();
        $injector->registerService($mailer, MailerInterface::class);
        try {
            $assertions($mailer);
        } finally {
            $injector->registerService($previousMailer, MailerInterface::class);
        }
    }

    /**
     * Sends distinct account and overall variants once, then suppresses duplicate calls.
     */
    public function testIndependentScopesAndRepeatedTaskInvocation(): void
    {
        Config::modify()->set(Email::class, 'admin_email', 'sender@example.test');
        $owner = $this->prepareOwner();
        $firstID = $this->idFromFixture(BankAccount::class, 'first');
        $secondID = $this->idFromFixture(BankAccount::class, 'second');
        $budget = HouseholdBudget::create([
            BudgetForecastField::OWNER_ID => $owner->ID,
            BudgetForecastField::FORECAST_EMAILS_ENABLED => true,
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED => true,
        ]);
        $budget->write();
        BudgetForecastAccountSetting::create([
            BudgetForecastField::HOUSEHOLD_BUDGET_ID => $budget->ID,
            BudgetForecastField::ACCOUNT_ID => $firstID,
            BudgetForecastField::ENABLED => true,
        ])->write();
        Security::setCurrentUser($owner);
        $this->writeEntry('Income', BudgetEntryType::INCOME, '3000.00', $firstID, '01,02,03,04,05,06,07,08,09,10,11,12');
        $this->writeEntry('Rent', BudgetEntryType::FIXED, '1000.00', $firstID, '11');
        $this->writeEntry('Utility', BudgetEntryType::FIXED, '200.00', $secondID, '11');
        $admin = $this->technicalAdmin();
        Security::setCurrentUser($admin);
        $mailer = $this->installCapturingMailer();
        $previousMailer = Injector::inst()->get(MailerInterface::class);
        Injector::inst()->registerService($mailer, MailerInterface::class);
        try {
            $dispatch = $this->dispatchAt(new DateTimeImmutable('2026-10-31T23:30:00+02:00'));
            $firstRun = $dispatch->run();
            self::assertSame(2, $firstRun['processed'], var_export($firstRun, true));
            self::assertSame(0, $firstRun['failed']);
            self::assertCount(2, $mailer->messages);
            self::assertCount(2, BudgetForecastDelivery::get()->filter(BudgetForecastField::STATUS, BudgetForecastStatus::SENT));
            self::assertSame(
                1,
                BudgetForecastDelivery::get()->filter([
                    BudgetForecastField::SCOPE => BudgetForecastScope::ACCOUNT,
                    BudgetForecastField::ACCOUNT_ID => $firstID,
                ])->count()
            );
            self::assertSame(
                1,
                BudgetForecastDelivery::get()->filter([
                    BudgetForecastField::SCOPE => BudgetForecastScope::OVERALL,
                    BudgetForecastField::ACCOUNT_ID => 0,
                ])->count()
            );
            $mailBodies = array_map(fn (RawMessage $message): string => (string)$message->getTextBody(), $mailer->messages);
            self::assertCount(1, array_filter($mailBodies, fn (string $body): bool => str_contains($body, '€1,200.00')));
            self::assertCount(1, array_filter($mailBodies, fn (string $body): bool => str_contains($body, 'Utility')));
            self::assertSame(0, $dispatch->run()['processed']);
            self::assertCount(2, $mailer->messages);
        } finally {
            Injector::inst()->registerService($previousMailer, MailerInterface::class);
        }
    }

    /**
     * Never performs a catch-up send after midnight on the first day of a new month.
     */
    public function testFollowingDayDoesNotCatchUp(): void
    {
        $owner = $this->prepareOwner();
        $budget = HouseholdBudget::create([
            BudgetForecastField::OWNER_ID => $owner->ID,
            BudgetForecastField::FORECAST_EMAILS_ENABLED => true,
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED => true,
        ]);
        $budget->write();
        $this->technicalAdmin();
        $result = $this->dispatchAt(new DateTimeImmutable('2026-11-01T00:01:00+01:00'))->run();
        self::assertFalse($result['due']);
        self::assertSame(0, BudgetForecastDelivery::get()->count());
    }

    /**
     * Handles 28/29/30/31-day month ends and computes the January rollover correctly.
     */
    public function testCalendarBoundariesAndDecemberRollover(): void
    {
        foreach ([
            ['2026-02-28T23:00:00+01:00', true, '2026-03'],
            ['2024-02-29T23:00:00+01:00', true, '2024-03'],
            ['2026-04-30T23:00:00+02:00', true, '2026-05'],
            ['2026-01-30T23:00:00+01:00', false, null],
            ['2026-12-31T23:00:00+01:00', true, '2027-01'],
        ] as [$date, $due, $month]) {
            $result = $this->dispatchAt(new DateTimeImmutable($date))->run();
            self::assertSame($due, $result['due']);
            self::assertSame($month, $result['target_month']);
        }
    }

    /**
     * Drops a prepared email if the local calendar date changes before transport handoff.
     */
    public function testCrossingMidnightAfterPreparationNeverSends(): void
    {
        Config::modify()->set(Email::class, 'admin_email', 'sender@example.test');
        $owner = $this->prepareOwner();
        $budget = HouseholdBudget::create([
            BudgetForecastField::OWNER_ID => $owner->ID,
            BudgetForecastField::FORECAST_EMAILS_ENABLED => true,
            BudgetForecastField::COMBINED_FORECAST_EMAIL_ENABLED => true,
        ]);
        $budget->write();
        Security::setCurrentUser($this->technicalAdmin());
        $mailer = $this->installCapturingMailer();
        $injector = Injector::inst();
        $previousMailer = $injector->get(MailerInterface::class);
        $injector->registerService($mailer, MailerInterface::class);
        $dates = [
            new DateTimeImmutable('2026-10-31T23:59:00+01:00'),
            new DateTimeImmutable('2026-10-31T23:59:00+01:00'),
            new DateTimeImmutable('2026-11-01T00:00:00+01:00'),
        ];
        $index = 0;
        $clock = static function () use (&$index, $dates): DateTimeImmutable {
            return $dates[min($index++, count($dates) - 1)];
        };
        try {
            $result = $this->dispatchWithClock($clock)->run();
            self::assertSame(0, $result['processed']);
            self::assertSame(1, $result['failed']);
            self::assertSame(BudgetForecastStatus::SKIPPED, BudgetForecastDelivery::get()->first()->Status);
            self::assertCount(0, $mailer->messages);
        } finally {
            $injector->registerService($previousMailer, MailerInterface::class);
        }
    }

    /**
     * Creates an owner with explicit household access and a linked member email.
     */
    private function prepareOwner(): Member
    {
        $owner = $this->objFromFixture(Member::class, 'owner');
        $owner->Email = 'owner@example.test';
        $owner->Locale = 'en_US';
        $owner->write();
        $group = Group::create(['Title' => 'Household forecast recipients']);
        $group->write();
        Permission::grant((int)$group->ID, HouseholdBudgetPermission::ACCESS);
        $owner->Groups()->add($group);
        Permission::reset();
        return $owner;
    }

    /**
     * Creates a distinct system member with ADMIN permission.
     */
    private function technicalAdmin(): Member
    {
        return $this->createMemberWithPermission('ADMIN');
    }

    /**
     * Writes a planned booking while the owner is the active persistence identity.
     */
    private function writeEntry(string $description, string $type, string $amount, int $accountID, string $months): void
    {
        BudgetEntry::create([
            'Description' => $description,
            'Amount' => $amount,
            'Type' => $type,
            'Months' => $months,
            'AccountID' => $accountID,
        ])->write();
    }

    /**
     * Creates the full production service graph with a deterministic local clock.
     */
    private function dispatchAt(DateTimeImmutable $now): BudgetForecastDispatchService
    {
        return $this->dispatchWithClock(static fn (): DateTimeImmutable => $now);
    }

    /**
     * Creates the production service graph with an injected deterministic clock.
     */
    private function dispatchWithClock(Closure $clock): BudgetForecastDispatchService
    {
        $reports = new BudgetReportService(new BudgetPeriodCalculator());
        return new BudgetForecastDispatchService(
            new BudgetForecastDeliveryRepository(),
            new BudgetForecastService($reports, new BudgetForecastInsightCalculator()),
            new BudgetForecastEmailService(),
            $reports,
            Injector::inst()->get(LoggerInterface::class),
            $clock
        );
    }

    /**
     * Creates an in-memory transport that records messages without delivering any email.
     */
    private function installCapturingMailer(): object
    {
        return new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->messages[] = $message;
            }
        };
    }
}
