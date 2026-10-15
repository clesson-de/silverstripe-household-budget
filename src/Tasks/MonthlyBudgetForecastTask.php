<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tasks;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetForecastErrorCode;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastDispatchService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Runs the centrally authorized monthly forecast dispatch.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tasks
 */
final class MonthlyBudgetForecastTask extends BuildTask
{
    protected static string $commandName = 'monthly-household-budget-forecast';
    protected string $title = 'Send monthly household forecasts';
    protected static string $description = 'Processes opted-in household forecast emails on the last calendar day only.';

    /** @config */
    private static ?string $cli_user_email = null;

    /**
     * Requires an ADMIN technical member in CLI and an ADMIN caller in the browser.
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        try {
            return $this->runAuthorized($output);
        } catch (Throwable) {
            $this->logTaskFailure();
            $output->writeln('<error>Monthly forecast dispatch failed. See the safe application log entry.</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Resolves technical identity, applies ADMIN guards and invokes the dispatch service.
     */
    private function runAuthorized(PolyOutput $output): int
    {
        $technicalMember = $this->technicalMember($output);
        if (!$technicalMember || !Permission::check('ADMIN', 'any', $technicalMember)) {
            $output->writeln('<error>A configured ADMIN technical member is required.</error>');
            return Command::FAILURE;
        }
        if (php_sapi_name() === 'cli') {
            Security::setCurrentUser($technicalMember);
        } elseif (!Permission::check('ADMIN')) {
            $output->writeln('<error>Only administrators may run monthly forecast dispatch.</error>');
            return Command::FAILURE;
        }
        /** @var BudgetForecastDispatchService $service */
        $service = Injector::inst()->create(BudgetForecastDispatchService::class);
        $result = $service->run();
        $this->writeSummary($output, $result);
        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolves the configured technical identity without guessing an address.
     */
    private function technicalMember(PolyOutput $output): ?Member
    {
        $email = static::config()->get('cli_user_email');
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>Configure cli_user_email with an existing ADMIN member.</error>');
            return null;
        }
        $member = Member::get()->filter('Email', $email)->first();
        return $member instanceof Member && $member->exists() ? $member : null;
    }

    /**
     * Executes the service and reports only aggregate counts to the task output.
     */
    private function logTaskFailure(): void
    {
        Injector::inst()->get(LoggerInterface::class)->error(
            'Monthly household forecast task failed before completing its summary.',
            ['error_code' => BudgetForecastErrorCode::DISPATCH]
        );
    }

    /**
     * Prints a concise non-personal aggregate result.
     *
     * @param array{due: bool, processed: int, skipped: int, failed: int, target_month: ?string} $result
     */
    private function writeSummary(PolyOutput $output, array $result): void
    {
        if (!$result['due']) {
            $output->writeln('Monthly forecasts are not due today.');
            return;
        }
        $output->writeln(sprintf(
            'Forecasts for %s: %d accepted, %d skipped, %d failed.',
            $result['target_month'],
            $result['processed'],
            $result['skipped'],
            $result['failed']
        ));
    }
}
