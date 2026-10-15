<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Controllers;

use Clesson\Silverstripe\HouseholdBudget\Constants\HouseholdBudgetPermission;
use Clesson\Silverstripe\HouseholdBudget\Exceptions\InvalidBudgetReportAccountSelection;
use Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod;
use Clesson\Silverstripe\HouseholdBudget\Services\BudgetReportService;
use Clesson\Silverstripe\HouseholdBudget\Services\HouseholdBudgetPdfRenderer;
use InvalidArgumentException;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Authorizes and streams private PDF reports without storing generated files.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Controllers
 */
final class HouseholdBudgetReportController extends Controller
{
    private static array $allowed_actions = ['download'];
    private static array $url_handlers = ['download' => 'download'];

    /**
     * Requires POST, an authenticated member, module permission, and a valid CSRF token.
     */
    public function download(HTTPRequest $request): HTTPResponse
    {
        $member = Security::getCurrentUser();
        if ($member) {
            i18n::set_locale($member->Locale ?: i18n::get_locale());
        }
        if (!$member || !Permission::check(HouseholdBudgetPermission::ACCESS, 'any', $member)) {
            return $this->errorResponse(403, 'ACCESS_DENIED', 'You are not allowed to create this report.');
        }
        if (!$request->isPOST()) {
            return $this->errorResponse(405, 'POST_REQUIRED', 'Use POST to create a report.');
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->errorResponse(403, 'INVALID_TOKEN', 'The security token is invalid.');
        }
        return $this->createDownload($request, $member);
    }

    /**
     * Validates the requested range and returns its rendered in-memory PDF.
     */
    private function createDownload(HTTPRequest $request, Member $member): HTTPResponse
    {
        $period = $this->periodFromRequest($request);
        if ($period instanceof HTTPResponse) {
            return $period;
        }
        try {
            $accountIDs = $this->accountIDs($request);
            $report = (new BudgetReportService())->create($member, $period, $accountIDs);
        } catch (InvalidBudgetReportAccountSelection) {
            return $this->errorResponse(400, 'INVALID_ACCOUNTS', 'Select valid bank accounts linked to your profile.');
        }
        return $this->pdfResponse((new HouseholdBudgetPdfRenderer())->render($report), $period);
    }

    /**
     * Returns a validated range or a localized request error.
     */
    private function periodFromRequest(HTTPRequest $request): BudgetReportPeriod|HTTPResponse
    {
        $maximumMonths = BudgetReportPeriod::maximumMonths();
        try {
            return new BudgetReportPeriod(
                $this->postString($request, 'startMonth'),
                $this->postString($request, 'endMonth'),
                $maximumMonths
            );
        } catch (InvalidArgumentException) {
            return $this->errorResponse(400, 'INVALID_PERIOD', 'Select a valid report month range.');
        }
    }

    /**
     * Reads a scalar POST value without accepting request arrays or coerced values.
     */
    private function postString(HTTPRequest $request, string $name): string
    {
        $value = $request->postVar($name);
        if (!is_string($value)) {
            throw new InvalidArgumentException('A report month value is required.');
        }
        return $value;
    }

    /**
     * Parses a non-empty list of positive numeric selected account IDs.
     *
     * @return list<int>
     */
    private function accountIDs(HTTPRequest $request): array
    {
        $values = $request->postVar('accountIDs');
        if (!is_array($values) || $values === []) {
            throw new InvalidBudgetReportAccountSelection('Select at least one bank account.');
        }
        return array_map(fn (mixed $value): int => $this->accountID($value), $values);
    }

    /**
     * Accepts only canonical positive decimal account identifiers.
     */
    private function accountID(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new InvalidBudgetReportAccountSelection('The selected bank account identifier is invalid.');
        }
        return (int)$value;
    }

    /**
     * Streams PDF bytes with a range-only filename and private no-store headers.
     */
    private function pdfResponse(string $pdf, BudgetReportPeriod $period): HTTPResponse
    {
        $filename = 'haushaltsbericht-' . $period->startMonth . '-bis-' . $period->endMonth . '.pdf';
        return HTTPResponse::create($pdf, 200)
            ->addHeader('Content-Type', 'application/pdf')
            ->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->addHeader('Content-Length', (string)strlen($pdf))
            ->addHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->addHeader('Pragma', 'no-cache')
            ->addHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Returns a localized JSON error suitable for the CMS fetch client.
     */
    private function errorResponse(int $status, string $key, string $fallback): HTTPResponse
    {
        return HTTPResponse::create(json_encode([
            'error' => _t(__CLASS__ . '.' . $key, $fallback),
        ], JSON_THROW_ON_ERROR), $status)->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }
}
