<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Tests\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Helpers\BudgetMoney;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportEntry;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportViewData;
use Clesson\Silverstripe\HouseholdBudget\Reports\HouseholdBudgetReport;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetPeriodCalculator;
use Clesson\Silverstripe\HouseholdBudget\Services\HouseholdBudgetPdfRenderer;
use DateTimeImmutable;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;

/**
 * Covers PDF generation, escaping, Unicode, and long-list pagination.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Tests
 */
class HouseholdBudgetPdfRendererTest extends SapphireTest
{
    /**
     * Generates a multi-page unicode report with every source row retained.
     */
    public function testLongUnicodeReportProducesPaginatedPdf(): void
    {
        $report = $this->reportWithEntries(45);
        $pdf = (new HouseholdBudgetPdfRenderer())->render($report);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(1, substr_count($pdf, '/Type /Page'));
        self::assertGreaterThan(20000, strlen($pdf));
    }

    /**
     * Escapes booking text before it enters the renderer's HTML template.
     */
    public function testPositionTextIsEscaped(): void
    {
        $report = $this->reportWithEntries(1, '<script>Überweisung & Miete</script>');
        $view = new BudgetReportViewData('de_DE');
        $html = $view->positionTableTemplate($report->positions);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Überweisung', $html);
    }

    /**
     * Keeps core planning metrics and localized caveats visible in the page-one template.
     */
    public function testFirstPageOverviewExplainsPlanningTotals(): void
    {
        i18n::set_locale('de_DE');
        $report = $this->reportWithEntries(1);
        $html = (new BudgetReportViewData('de_DE'))->overviewTemplate($report);
        self::assertStringContainsString('Haushaltsbericht', $html);
        self::assertStringContainsString('Überschuss / Defizit', $html);
        self::assertStringContainsString('kein Kontoauszug', $html);
        self::assertStringContainsString('aktuellen Buchungsstand', $html);
        self::assertStringContainsString('Januar 2025 - Dezember 2026', $html);
        self::assertStringContainsString('Zeitraum:', $html);
        self::assertStringContainsString('Erstellt am:', $html);
        self::assertStringNotContainsString('1. Januar', $html);
        self::assertStringContainsString('Regulärer monatlicher Haushaltsbedarf', $html);
    }

    /**
     * Reuses translated category labels instead of exposing internal booking codes.
     */
    public function testRegularCategoryHeadingsUseTranslatedLabels(): void
    {
        i18n::set_locale('de_DE');
        $view = new BudgetReportViewData('de_DE');
        foreach (BudgetEntryType::normalTypes() as $type) {
            self::assertSame(BudgetEntryType::label($type), $view->typeLabel($type));
            self::assertNotSame($type, $view->typeLabel($type));
        }
    }

    /**
     * Builds a deterministic report with long labels and multiple yearly pages.
     */
    private function reportWithEntries(int $count, string $description = 'Versicherung — Überweisung'): HouseholdBudgetReport
    {
        $entries = [];
        for ($index = 1; $index <= $count; $index++) {
            $entries[] = new BudgetReportEntry(
                $index, $description . ' ' . str_repeat('sehr langer Text ', 8), 'fixed',
                BudgetMoney::toCents('25.75'), ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'],
                '', '', 'Girokonto', 'Müller & Töchter'
            );
        }
        $period = new BudgetReportPeriod('2025-01', '2026-12');
        $data = (new BudgetPeriodCalculator())->calculate($entries, $period);
        return new HouseholdBudgetReport(
            $period, new DateTimeImmutable('2026-10-14T12:00:00+00:00'), 'de_DE',
            ['Girokonto'], $data['months'], $data['years'], $data['yearMonthCounts'], $data['totals'],
            $data['positions'], $data['categories'], $data['regularPositions'],
            $data['exceptionalPositions'], $data['transfers']
        );
    }
}
