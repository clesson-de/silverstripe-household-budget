<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Controllers;

use Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetPanel;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;

/**
 * Checks panel content uses the authenticated member's CMS language.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class MonthlyBudgetControllerTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** Applies the German member locale to labels, months and template translations. */
    public function testGermanPanelContent(): void
    {
        $member = Member::create(['Locale' => 'de_DE']);
        $member->write();
        Security::setCurrentUser($member);
        i18n::set_locale('en_US');
        $html = MonthlyBudgetController::create()->summary()->forTemplate();
        $this->assertStringContainsString('Einnahmen', $html);
        $this->assertStringContainsString('Ausgaben', $html);
        $this->assertStringContainsString('Januar', $html);
        $this->assertStringContainsString('Jahresübersicht', $html);
    }

    /** Returns an AJAX-initializable chart canvas with localized datasets. */
    public function testChartContent(): void
    {
        $member = Member::create(['Locale' => 'de_DE']);
        $member->write();
        Security::setCurrentUser($member);
        i18n::set_locale('en_US');
        $html = MonthlyBudgetController::create()->chart()->forTemplate();
        $this->assertStringContainsString('chart-field-canvas', $html);
        $this->assertStringContainsString('data-chart-config=', $html);
        $this->assertStringContainsString('Einnahmen', $html);
        $this->assertStringContainsString('Ausgaben', $html);
        $this->assertStringContainsString('Saldo', $html);
        $this->assertStringContainsString('Januar', $html);
        $this->assertStringContainsString('&quot;stacked&quot;:true', $html);
    }

    /** Offers the current year plus five preceding years, overridable via config. */
    public function testYearRange(): void
    {
        $current = (int)date('Y');
        $this->assertSame(range($current + 1, $current - 5), MonthlyBudgetPanel::availableYears());
        MonthlyBudgetPanel::config()->set('years_before_current', 2);
        $this->assertSame(range($current + 1, $current - 2), MonthlyBudgetPanel::availableYears());
    }

    /** Renders a requested year and selects the corresponding dropdown option. */
    public function testSelectedYear(): void
    {
        $member = Member::create(['Locale' => 'de_DE']);
        $member->write();
        Security::setCurrentUser($member);
        $year = (int)date('Y') - 2;
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', ['year' => (string)$year]));
        $html = $controller->summary()->forTemplate();
        $this->assertStringContainsString('value="' . $year . '" selected="selected"', $html);
        $this->assertSame(7, substr_count($html, '<option '));
    }

    /** Rejects years outside the configured range instead of ignoring the input. */
    public function testInvalidYear(): void
    {
        $member = Member::create();
        $member->write();
        Security::setCurrentUser($member);
        $controller = MonthlyBudgetController::create();
        $controller->setRequest(new HTTPRequest('GET', '/', ['year' => (string)((int)date('Y') + 2)]));
        $this->expectException(HTTPResponse_Exception::class);
        $controller->summary();
    }
}
