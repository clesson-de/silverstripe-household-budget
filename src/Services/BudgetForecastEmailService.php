<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\HouseholdBudget\Forecasts\BudgetForecast;
use Clesson\Silverstripe\HouseholdBudget\Forecasts\BudgetForecastEmailViewData;
use SilverStripe\Control\Email\Email;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\ArrayData;
use SilverStripe\Security\Member;
use InvalidArgumentException;

/**
 * Prepares an owner-only email and matching private PDF bytes before transport begins.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
final class BudgetForecastEmailService
{
    /**
     * Builds all localized bodies and the matching private report attachment without sending.
     */
    public function prepare(BudgetForecast $forecast, Member $member): Email
    {
        $previousLocale = i18n::get_locale();
        try {
            return $this->prepareLocalized($forecast, $member);
        } finally {
            i18n::set_locale($previousLocale);
        }
    }

    /**
     * Hands a fully prepared email to the configured Silverstripe transport.
     */
    public function send(Email $email): void
    {
        $email->send();
    }

    /**
     * Creates translated email content and attaches the report for this exact month and scope.
     */
    private function prepareLocalized(BudgetForecast $forecast, Member $member): Email
    {
        $recipient = filter_var((string)$member->Email, FILTER_VALIDATE_EMAIL);
        $sender = filter_var((string)Email::config()->get('admin_email'), FILTER_VALIDATE_EMAIL);
        if ($recipient === false || $sender === false) {
            throw new InvalidArgumentException('A valid member recipient and configured admin_email are required.');
        }
        $locale = $member->Locale ?: i18n::get_locale();
        i18n::set_locale($locale);
        $data = new BudgetForecastEmailViewData($forecast, $member);
        $templateData = $data->templateData();
        $html = (string)ArrayData::create($templateData)
            ->renderWith('Clesson\Silverstripe\HouseholdBudget\Emails\MonthlyBudgetForecastHtml');
        $text = ArrayData::create($templateData)
            ->renderWith('Clesson\Silverstripe\HouseholdBudget\Emails\MonthlyBudgetForecastText')->Plain();
        $email = Email::create();
        $email->setFrom($sender);
        $email->setTo($recipient);
        $email->setSubject((string)$templateData['Subject']);
        $email->html($html);
        $email->text($text);
        $email->addAttachmentFromData(
            (new HouseholdBudgetPdfRenderer())->render($forecast->report),
            'household-forecast-' . $forecast->report->period->startMonth . '.pdf',
            'application/pdf'
        );
        return $email;
    }
}
