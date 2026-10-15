<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Tasks;

use Clesson\Silverstripe\HouseholdBudget\Tasks\MonthlyBudgetForecastTask;
use ReflectionMethod;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Covers configured CLI identity requirements without invoking any real mail transport.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class MonthlyBudgetForecastTaskTest extends SapphireTest
{
    /**
     * Fails closed when cli_user_email is absent or does not resolve to an ADMIN member.
     */
    public function testMissingTechnicalMemberConfigurationFails(): void
    {
        Config::modify()->set(MonthlyBudgetForecastTask::class, 'cli_user_email', null);
        [$result, $output] = $this->executeTask();
        self::assertSame(Command::FAILURE, $result);
        self::assertStringContainsString('Configure cli_user_email', $output);
    }

    /**
     * Runs the task with a generated ADMIN identity and no enabled forecast recipients.
     */
    public function testConfiguredTechnicalAdminRunsSafelyWithoutRecipients(): void
    {
        $adminID = $this->logInWithPermission('ADMIN');
        $admin = Member::get()->byID($adminID);
        Config::modify()->set(MonthlyBudgetForecastTask::class, 'cli_user_email', (string)$admin->Email);
        [$result, $output] = $this->executeTask();
        self::assertSame(Command::SUCCESS, $result);
        self::assertTrue(
            str_contains($output, 'not due today') || str_contains($output, '0 accepted, 0 skipped, 0 failed')
        );
    }

    /**
     * Invokes the protected Silverstripe 6 task entry point with captured output.
     *
     * @return array{int, string}
     */
    private function executeTask(): array
    {
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, BufferedOutput::VERBOSITY_NORMAL, false, $buffer);
        $method = new ReflectionMethod(MonthlyBudgetForecastTask::class, 'execute');
        $result = $method->invoke(new MonthlyBudgetForecastTask(), new ArrayInput([]), $output);
        return [$result, $buffer->fetch()];
    }
}
