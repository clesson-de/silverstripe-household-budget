<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastScope;
use Clesson\Silverstripe\HouseholdBudget\Forecasts\BudgetForecast;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportEntry;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use Clesson\Silverstripe\HouseholdBudget\Reports\HouseholdBudgetReport;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastEmailService;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastInsightCalculator;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetPeriodCalculator;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Member;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use DateTimeImmutable;

/**
 * Covers private email addressing, localized bodies, PDF bytes and safe transport injection.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class BudgetForecastEmailServiceTest extends SapphireTest
{
    /**
     * Decodes escaped template text without discarding literal angle-bracket content.
     */
    public function testPlaintextPreservesNamesAndDescriptionsWhileHtmlRemainsEscaped(): void
    {
        Config::modify()->set(Email::class, 'admin_email', 'sender@example.test');
        $member = Member::create([
            'Email' => 'owner@example.test',
            'FirstName' => 'Alex & "Sam" <friends>',
            'Locale' => 'en_US',
        ]);
        $email = (new BudgetForecastEmailService())->prepare($this->forecast(), $member);
        self::assertStringContainsString('Alex & "Sam" <friends>', $email->getTextBody());
        self::assertStringContainsString('<script>Rent & "utilities"</script>', $email->getTextBody());
        self::assertStringNotContainsString('&amp;', $email->getTextBody());
        self::assertStringContainsString('&lt;friends&gt;', $email->getHtmlBody());
        self::assertStringContainsString('&lt;script&gt;Rent &amp;', $email->getHtmlBody());
        self::assertStringNotContainsString('<script>', $email->getHtmlBody());
    }

    /**
     * Resolves overall scope in the recipient locale and restores either technical locale.
     */
    public function testOverallScopeUsesRecipientLocaleInBothDirections(): void
    {
        Config::modify()->set(Email::class, 'admin_email', 'sender@example.test');
        $previousLocale = i18n::get_locale();
        try {
            foreach ([
                ['en_US', 'de_DE', 'Alle eigenen Konten', 'Haushaltsprognose'],
                ['de_DE', 'en_US', 'All own accounts', 'Household forecast'],
            ] as [$technicalLocale, $recipientLocale, $scope, $subject]) {
                i18n::set_locale($technicalLocale);
                $member = Member::create(['Email' => 'owner@example.test', 'Locale' => $recipientLocale]);
                $email = (new BudgetForecastEmailService())->prepare($this->forecast(BudgetForecastScope::OVERALL), $member);
                self::assertStringContainsString($scope, $email->getSubject());
                self::assertStringContainsString($subject, $email->getSubject());
                self::assertStringContainsString($scope, $email->getTextBody());
                self::assertStringContainsString($scope, $email->getHtmlBody());
                self::assertSame($technicalLocale, i18n::get_locale());
            }
        } finally {
            i18n::set_locale($previousLocale);
        }
    }

    /**
     * Prepares and sends only through a capturing test mailer, restoring the prior locale.
     */
    public function testEmailContainsMatchingPdfAndOnlyMemberRecipient(): void
    {
        Config::modify()->set(Email::class, 'admin_email', 'sender@example.test');
        $member = Member::create([
            'Email' => 'owner@example.test',
            'FirstName' => 'Alex',
            'Locale' => 'en_US',
        ]);
        $member->write();
        $forecast = $this->forecast();
        $mailer = new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->messages[] = $message;
            }
        };
        $injector = Injector::inst();
        $previousMailer = $injector->get(MailerInterface::class);
        $injector->registerService($mailer, MailerInterface::class);
        i18n::set_locale('de_DE');
        try {
            $service = new BudgetForecastEmailService();
            $prepared = $service->prepare($forecast, $member);
            self::assertSame('de_DE', i18n::get_locale());
            self::assertCount(1, $prepared->getTo());
            self::assertSame('owner@example.test', $prepared->getTo()[0]->getAddress());
            self::assertSame([], $prepared->getCc());
            self::assertSame([], $prepared->getBcc());
            self::assertCount(1, $prepared->getAttachments());
            self::assertSame('household-forecast-2026-11.pdf', $prepared->getAttachments()[0]->getFilename());
            self::assertSame('%PDF-', substr((string)$prepared->getAttachments()[0]->getBody(), 0, 5));
            self::assertStringContainsString('Largest upcoming planned expenses', (string)$prepared->getHtmlBody());
            self::assertStringContainsString('Largest upcoming planned expenses', (string)$prepared->getTextBody());
            self::assertStringNotContainsString('<script>', (string)$prepared->getHtmlBody());

            $service->send($prepared);
            self::assertCount(1, $mailer->messages);
            self::assertSame('owner@example.test', $mailer->messages[0]->getTo()[0]->getAddress());
        } finally {
            i18n::set_locale('en_US');
            $injector->registerService($previousMailer, MailerInterface::class);
        }
    }

    /**
     * Builds an annual snapshot and its matching month report using production calculations.
     */
    private function forecast(string $scope = BudgetForecastScope::ACCOUNT): BudgetForecast
    {
        $period = new BudgetReportPeriod('2026-01', '2026-12');
        $entry = new BudgetReportEntry(
            1,
            '<script>Rent & "utilities"</script>',
            BudgetEntryType::FIXED,
            100000,
            array_map(fn (int $month): string => str_pad((string)$month, 2, '0', STR_PAD_LEFT), range(1, 12)),
            '',
            '',
            'Checking account',
            ''
        );
        $calculator = new BudgetPeriodCalculator();
        $annual = $this->report($calculator, [$entry], $period);
        $monthly = $this->report($calculator, [$entry], new BudgetReportPeriod('2026-11', '2026-11'));
        return (new BudgetForecastInsightCalculator())->calculate(
            $annual,
            $monthly,
            $scope,
            'Checking account'
        );
    }

    /**
     * Creates a report DTO from the existing production period calculator.
     *
     * @param list<BudgetReportEntry> $entries
     */
    private function report(
        BudgetPeriodCalculator $calculator,
        array $entries,
        BudgetReportPeriod $period
    ): HouseholdBudgetReport {
        $data = $calculator->calculate($entries, $period);
        return new HouseholdBudgetReport(
            $period,
            new DateTimeImmutable('2026-10-31T12:00:00+02:00'),
            'en_US',
            ['Checking account'],
            $data['months'],
            $data['years'],
            $data['yearMonthCounts'],
            $data['totals'],
            $data['positions'],
            $data['categories'],
            $data['regularPositions'],
            $data['exceptionalPositions'],
            $data['transfers']
        );
    }
}
