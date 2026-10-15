<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportMonth;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPosition;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportViewData;
use Clesson\Silverstripe\HouseholdBudget\Reports\HouseholdBudgetReport;
use TCPDF;

/**
 * Creates private PDF bytes using TCPDF's local Unicode fonts and chart primitives.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class HouseholdBudgetPdfRenderer
{
    private const TRANSFER_COLOUR = [32, 147, 229];
    private const INCOME_COLOUR = [40, 167, 69];
    private const EXPENSE_COLOUR = [115, 126, 137];
    private const NEGATIVE_COLOUR = [180, 35, 24];

    /**
     * Renders a complete report into an in-memory PDF string.
     */
    public function render(HouseholdBudgetReport $report): string
    {
        $pdf = $this->document();
        $view = new BudgetReportViewData($report->locale);
        $pdf->AddPage();
        $pdf->writeHTML($view->overviewTemplate($report), true, false, true, false, '');
        $this->monthlySections($pdf, $view, $report);
        $this->yearSummary($pdf, $view, $report);
        $this->categorySection($pdf, $view, $report);
        $this->positionSections($pdf, $view, $report);
        return $pdf->Output('', 'S');
    }

    /**
     * Configures automatic A4 pagination, embedded Unicode text, and page numbering.
     */
    private function document(): HouseholdBudgetPdfDocument
    {
        $pdf = new HouseholdBudgetPdfDocument();
        $pdf->SetCreator('Silverstripe Household Budget');
        $pdf->SetTitle(_t(
            'Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportViewData.REPORT_TITLE',
            'Household budget report'
        ));
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterMargin(8);
        return $pdf;
    }

    /**
     * Writes year-bounded monthly charts and the matching detailed table.
     */
    private function monthlySections(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        HouseholdBudgetReport $report
    ): void {
        foreach ($this->monthsByYear($report->months) as $year => $months) {
            $this->sectionTitle($pdf, $view->text('MONTHLY_EVOLUTION', 'Monthly development') . ' ' . $year);
            if ($report->period->monthCount > 1) {
                $this->drawMonthlyChart($pdf, $view, $months);
            }
            $this->ensureSpace($pdf, 22);
            $pdf->writeHTML($view->monthlyTableTemplate($months), true, false, true, false, '');
        }
    }

    /**
     * Groups DTO month rows by year, preserving their existing order.
     *
     * @param list<BudgetReportMonth> $months
     * @return array<string, list<BudgetReportMonth>>
     */
    private function monthsByYear(array $months): array
    {
        $groups = [];
        foreach ($months as $month) {
            $groups[substr($month->month, 0, 4)][] = $month;
        }
        return $groups;
    }

    /**
     * Draws four independent, zero-based monthly series using native TCPDF shapes.
     *
     * @param list<BudgetReportMonth> $months
     */
    private function drawMonthlyChart(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        array $months
    ): void {
        $this->ensureSpace($pdf, 64);
        $startX = 17.0;
        $startY = $pdf->GetY() + 3;
        $groupWidth = 176.0 / count($months);
        $scale = 19.0 / $this->largestMonthlyValue($months);
        $this->drawChartBaseline($pdf, $startX, $startY + 20);
        foreach ($months as $index => $month) {
            $this->drawMonthBars($pdf, $month, $startX + ($index * $groupWidth), $startY, $groupWidth, $scale);
        }
        $this->drawMonthLabels($pdf, $view, $months, $startX, $startY, $groupWidth);
        $pdf->SetY($startY + 58);
    }

    /**
     * Returns the maximum displayed absolute month value for layout scaling only.
     *
     * @param list<BudgetReportMonth> $months
     */
    private function largestMonthlyValue(array $months): float
    {
        $largest = 0;
        foreach ($months as $month) {
            $largest = max(
                $largest,
                $month->incomeCents,
                $month->expenseCents,
                $month->transferCents,
                abs($month->balanceCents)
            );
        }
        return max(1, $largest) / 100;
    }

    /**
     * Draws the chart's zero-value baseline.
     */
    private function drawChartBaseline(HouseholdBudgetPdfDocument $pdf, float $x, float $y): void
    {
        $pdf->SetDrawColor(110, 120, 130);
        $pdf->Line($x, $y, $x + 176, $y);
    }

    /**
     * Draws one month's income, cost, transfer, and signed balance bars.
     */
    private function drawMonthBars(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportMonth $month,
        float $x,
        float $top,
        float $width,
        float $scale
    ): void {
        $bars = [
            [$month->incomeCents, self::INCOME_COLOUR, false],
            [$month->expenseCents, self::EXPENSE_COLOUR, false],
            [$month->transferCents, self::TRANSFER_COLOUR, false],
            [$month->balanceCents, $month->balanceCents < 0 ? self::NEGATIVE_COLOUR : self::INCOME_COLOUR, true],
        ];
        foreach ($bars as $index => [$cents, $colour, $signed]) {
            $this->drawBar($pdf, $x + 1 + ($index * 3.1), $top + 20, $width, (int)$cents, $scale, $colour, $signed);
        }
    }

    /**
     * Draws a single value bar above or below the chart baseline.
     */
    private function drawBar(
        HouseholdBudgetPdfDocument $pdf,
        float $x,
        float $baseline,
        float $groupWidth,
        int $cents,
        float $scale,
        array $colour,
        bool $signed
    ): void {
        if ($cents === 0) {
            return;
        }
        $height = min(19.0, abs($cents / 100) * $scale);
        $pdf->SetFillColor($colour[0], $colour[1], $colour[2]);
        $pdf->Rect($x, $signed && $cents < 0 ? $baseline : $baseline - $height, min(2.4, $groupWidth / 5), $height, 'F');
    }

    /**
     * Labels chart groups with numeric months to stay legible for all locales.
     *
     * @param list<BudgetReportMonth> $months
     */
    private function drawMonthLabels(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        array $months,
        float $x,
        float $top,
        float $groupWidth
    ): void {
        $pdf->SetFont('dejavusans', '', 7);
        foreach ($months as $index => $month) {
            $pdf->SetXY($x + ($index * $groupWidth), $top + 41);
            $pdf->Cell($groupWidth, 5, substr($month->month, 5, 2), 0, 0, 'C');
        }
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetY($top + 46);
        $this->chartLegend($pdf, $view);
    }

    /**
     * Prints the chart's local, non-overlapping series legend.
     */
    private function chartLegend(HouseholdBudgetPdfDocument $pdf, BudgetReportViewData $view): void
    {
        $labels = [
            ['INCOME', 'Income', self::INCOME_COLOUR],
            ['COSTS', 'Costs', self::EXPENSE_COLOUR],
            ['TRANSFERS', 'Transfers', self::TRANSFER_COLOUR],
            ['BALANCE', 'Balance', [70, 120, 70]],
        ];
        $x = 18.0;
        foreach ($labels as [$key, $fallback, $colour]) {
            $pdf->SetFillColor($colour[0], $colour[1], $colour[2]);
            $pdf->Rect($x, $pdf->GetY() + 1, 3, 3, 'F');
            $pdf->SetXY($x + 4, $pdf->GetY());
            $pdf->Cell(38, 5, $view->text($key, $fallback), 0, 0, 'L');
            $x += 43;
        }
        $pdf->Ln(7);
    }

    /**
     * Adds an annual summary table when the report spans multiple years.
     */
    private function yearSummary(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        HouseholdBudgetReport $report
    ): void {
        if (count($report->years) < 2) {
            return;
        }
        $this->sectionTitle($pdf, $view->text('YEAR_SUMMARY', 'Year totals'));
        $pdf->writeHTML($view->yearTableTemplate($report), true, false, true, false, '');
    }

    /**
     * Writes category totals, percentages, and native horizontal bars.
     */
    private function categorySection(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        HouseholdBudgetReport $report
    ): void {
        $this->sectionTitle($pdf, $view->text('COST_DISTRIBUTION', 'Cost distribution'));
        $this->drawCategoryBars($pdf, $report);
        $this->ensureSpace($pdf, 22);
        $pdf->writeHTML($view->categoryTableTemplate($report->categories), true, false, true, false, '');
        $this->writeNote($pdf, $view->text('ROUNDED_SHARES', 'Rounded percentages may not total exactly 100%.'));
    }

    /**
     * Scales category bars independently from business totals.
     */
    private function drawCategoryBars(HouseholdBudgetPdfDocument $pdf, HouseholdBudgetReport $report): void
    {
        $this->ensureSpace($pdf, 42);
        $max = max(1, ...array_map(fn ($category): int => $category->amountCents, $report->categories));
        foreach ($report->categories as $index => $category) {
            $y = $pdf->GetY() + 2 + ($index * 7);
            $pdf->SetFillColor(...($category->type === BudgetEntryType::EXCEPTIONAL
                ? self::NEGATIVE_COLOUR : self::EXPENSE_COLOUR));
            $pdf->Rect(15, $y, 175 * ($category->amountCents / $max), 4, 'F');
        }
        $pdf->SetY($pdf->GetY() + 32);
    }

    /**
     * Adds each normal cost category and the separate exceptional/transfer lists.
     */
    private function positionSections(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        HouseholdBudgetReport $report
    ): void {
        $this->regularPositionSections($pdf, $view, $report);
        $this->writePositionGroup(
            $pdf, $view, $view->text('EXCEPTIONAL', 'Exceptional costs'), $report->exceptionalPositions
        );
        $this->writePositionGroup($pdf, $view, $view->text('TRANSFERS', 'Transfers / reserves'), $report->transfers, true);
    }

    /**
     * Writes fixed, variable, and periodic cost lists in separate sections.
     */
    private function regularPositionSections(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        HouseholdBudgetReport $report
    ): void {
        foreach (BudgetEntryType::normalTypes() as $type) {
            $this->regularPositionSection($pdf, $view, $report, $type);
        }
    }

    /**
     * Adds the periodic explanation only to its corresponding booking group.
     */
    private function regularPositionSection(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        HouseholdBudgetReport $report,
        string $type
    ): void {
        $note = $type === BudgetEntryType::PERIODIC
            ? $view->text('PERIODIC_NOTE', 'Periodic items are counted in the selected month; they are not necessarily monthly.')
            : '';
        $title = $view->typeLabel($type);
        $positions = $report->regularPositions[$type] ?? [];
        $this->writePositionGroup($pdf, $view, $title, $positions, false, $note);
    }

    /**
     * Writes an independently paginated list of positions or its empty state.
     *
     * @param list<BudgetReportPosition> $positions
     */
    private function writePositionGroup(
        HouseholdBudgetPdfDocument $pdf,
        BudgetReportViewData $view,
        string $title,
        array $positions,
        bool $transfer = false,
        string $note = ''
    ): void {
        $this->sectionTitle($pdf, $title, $transfer ? self::TRANSFER_COLOUR : null);
        if ($note !== '') {
            $this->writeNote($pdf, $note);
        }
        if ($positions === []) {
            $this->writeNote($pdf, $view->text('NO_POSITIONS', 'No bookings in this category for the selected period.'));
            return;
        }
        $this->ensureSpace($pdf, 22);
        $pdf->writeHTML($view->positionTableTemplate($positions), true, false, true, false, '');
    }

    /**
     * Writes a translated section heading after reserving room for following content.
     */
    private function sectionTitle(HouseholdBudgetPdfDocument $pdf, string $title, ?array $colour = null): void
    {
        $this->ensureSpace($pdf, 18);
        $pdf->SetTextColor(...($colour ?? [25, 59, 90]));
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->writeHTML('<h2>' . $this->escape($title) . '</h2>', true, false, true, false, '');
        $pdf->SetTextColor(36, 52, 71);
        $pdf->SetFont('dejavusans', '', 9);
    }

    /**
     * Prints a short note without forcing a separate page.
     */
    private function writeNote(HouseholdBudgetPdfDocument $pdf, string $text): void
    {
        $pdf->writeHTML('<p color="#5c6670">' . $this->escape($text) . '</p>', true, false, true, false, '');
    }

    /**
     * Starts a new page when the next indivisible layout unit would be clipped.
     */
    private function ensureSpace(HouseholdBudgetPdfDocument $pdf, float $height): void
    {
        if ($pdf->GetY() + $height > 270) {
            $pdf->AddPage();
        }
    }

    /**
     * Escapes generated report text before adding it to TCPDF HTML.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Adds unobtrusive page numbers while retaining TCPDF's automatic page breaks.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class HouseholdBudgetPdfDocument extends TCPDF
{
    /**
     * Prints a page counter at the bottom of each generated page.
     */
    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 110, 120);
        $label = _t(
            'Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportViewData.PAGE',
            'Page {current} / {total}',
            ['current' => $this->getAliasNumPage(), 'total' => $this->getAliasNbPages()]
        );
        $this->Cell(0, 8, $label, 0, 0, 'R');
    }
}
