# Household Budget

`clesson-de/silverstripe-household-budget` is a **Silverstripe CMS application
for personal household planning**, not a frontend presentation module. It adds
a dedicated CMS section where each member maintains their own recurring income,
expenses and transfers across linked bank accounts.

The module answers questions such as: What costs are planned next month? How do
income and expenses develop throughout the year? What is the normal monthly
household need? Which irregular expenses explain an expensive month?

It does not provide public pages, bank imports, actual transactions, account
balances or payment execution. All views, PDFs and forecasts use **currently
saved planning data**. Historical periods also reflect the current records, not
historical versions.

[Deutsche Dokumentation](README.de.md)

## Features and CMS navigation

| View | Purpose |
| --- | --- |
| Dashboard | Monthly totals, stacked annual bars, annual line chart, monthly category comparison and annual booking ranking. |
| Monthly details | Income and expense positions with totals, separate transfers and signed balance for a selected month. |
| Report / PDF | Private PDF download for an inclusive month range and selected own accounts. |
| Administration → Budget entries | Create and edit bookings; filter categories; compare amounts; inspect recurring months and compact date-bound badges. |
| Administration → Settings | Personal forecast email opt-in, separate account/overall choices and read-only delivery status. |

Year selectors include the current and **next year**, plus five previous years
by default. Month selectors default to the current month. Searchable account
multiselects in monthly details and dashboard panels display selected accounts
as removable pills. All own accounts start selected; removing the last selection
selects all again.
Report / PDF uses the same searchable pill-style account multiselect, with all
own accounts selected initially. An empty selection prevents PDF export and
shows the existing account-selection error.
Existing saved dashboards can add new panels through the panel selector;
new layouts include the available panels by default.

Monthly details show each rule's configured recurrence months as three-letter
badges between bank account and amount, without a column heading. Badges use
white text on `#D8E4EB`; the current calendar month uses `#025389`, independently
of the month selected in the view.
Booking amounts are prefixed with `+` for income, `+/-` for transfers and `-`
for expenses; stored amounts and calculated totals remain unchanged.

The monthly comparison chart has separate bars for income, fixed expenses,
variable expenses, transfers and balance. Periodic and exceptional expenses
do not have their own bars there, but **are included in its balance**.
Annual ranking orders individual bookings by their effective yearly sum,
without merging identical descriptions.

## Dependencies

Direct runtime dependencies, as declared in `composer.json`:

| Package | Constraint | Used for |
| --- | --- | --- |
| `silverstripe/framework` | `^6` | ORM, forms, controllers, permissions, tasks and email. |
| `silverstripe/admin` | `^3` | Dedicated `SingleRecordAdmin` CMS section. |
| `clesson-de/silverstripe-contacts` | `^1` | Member-linked contacts and optional booking counterparties. |
| `clesson-de/silverstripe-payment-accounts` | `^1` | Own `BankAccount` records and account summaries/aliases. |
| `clesson-de/silverstripe-dashboard` | `^1` | Personal dashboard and panel layout. |
| `clesson-de/silverstripe-graphing` | `^1` | Chart.js-backed bar and line charts in the CMS. |
| `clesson-de/silverstripe-hints` | `^1` | Explanatory `HintField` panels in the CMS. |
| `tecnickcom/tcpdf` | `^6` | Local, in-memory PDF generation. |

`clesson-de/silverstripe-scheduler` is **optional**: it can run the forecast task,
but a regular server cron works too. There is no dependency on
`silverstripe-pdf-templates`, and no stored PDF template must be configured.
Use a PHP environment compatible with Silverstripe 6 and the installed
dependencies, including their extension requirements.

## Installation and initial setup

```bash
composer require clesson-de/silverstripe-household-budget
composer vendor-expose
```

Run `/dev/build?flush=all` after installation or schema updates. The module ships
admin assets; no separate frontend build is required for normal installation.

For each participating member:

1. Grant the required CMS access and `CMS_ACCESS_HOUSEHOLD_BUDGET`
   (“Access household budget”).
2. Link the member account to a contact through the contacts module.
3. Assign at least one bank account to that contact.
4. Open the household budget CMS section; its private workspace is created on
   first access.

Account ownership is resolved through `$member->Contact()->ID` and the bank
account's `ContactID`. A member without a linked contact must not receive
unassigned accounts. Bookings currently use
`Clesson\Silverstripe\PaymentAccount\Models\BankAccount`, not arbitrary payment
account subclasses such as credit cards.

On upgrade, dev/build also migrates legacy `expense` types to `fixed` and copies
legacy `StartDate`/`EndDate` values to month bounds without overwriting existing
values. Archived `_migrated_StartDate`/`_migrated_EndDate` columns retain the
original dates; later builds do not restore deliberately cleared bounds.

## Ownership, permissions and privacy

| Actor / record | Access |
| --- | --- |
| Member with household CMS access | Their own workspace, bookings, linked bank accounts and reports. |
| Booking owner | View, edit and delete their own bookings. |
| Workspace owner | Edit their own account forecast settings; the workspace itself cannot be deleted through the module. |
| Other members, including administrators | No owner override for another member's workspace, bookings or personal forecast settings. |
| Delivery record owner | Read-only access to their own technical delivery status; no CMS creation, editing or deletion. |
| Configured ADMIN technical member | Run the central task, which explicitly processes each opted-in recipient's data and updates technical delivery records. |
| Anonymous visitors | No household dashboard or report access. |

`ADMIN` is not a shared-household feature. The central task's privileged
processing is a deliberate exception for automated delivery, not a UI permission
to browse other households.

These are **application-level access rules**, not encryption against hosting
operators. Administrators with database, filesystem, application-code, backup
or mail-server access can access data through those systems. Developers must
preserve owner/account filters in custom code: raw ORM queries do not
automatically enforce `canView()`.

PDF downloads require authentication, household CMS permission, POST, valid
SecurityToken and own account IDs. They use private no-store responses and
are not saved as public assets. Email attachments are also generated in memory,
but copies will exist in the configured mail infrastructure and recipient's
mailbox. Protect transport credentials, mailboxes and backups accordingly.

## Booking and calculation rules

Each `BudgetEntry` has an owner, description, positive amount, type, bank
account, selected recurring months and optional `YYYY-MM` start/end bounds.
The counterparty contact is optional. Both boundary months are inclusive.
A booking occurs once per selected month within those bounds; there is no
day-based proration or automatic spreading of annual charges.

| Type | Meaning | Included in costs / deducted from balance |
| --- | --- | --- |
| `INCOME` | Income | No; increases balance. |
| `FIXED` | Recurring fixed expenses | Yes. |
| `VARIABLE` | Adjustable expenses | Yes. |
| `PERIODIC` | Irregular / annual expenses | Yes. |
| `EXCEPTIONAL` | Exceptional expenses | Yes. |
| `TRANSFER` | Transfers / reserves | No; shown separately. |

Balance = income minus all four expense types. Normal household need =
`FIXED + VARIABLE + PERIODIC`, divided by **all calendar months** in the selected
period, including empty months. Average total costs additionally include
`EXCEPTIONAL`. Transfers remain neutral everywhere and use blue `#2093e5`.

Business sums use integer cents. Display averages round to the nearest cent;
independently rounded category shares may not total exactly 100%.
A €120 December charge contributes €120 in December and €10 to the monthly
average over a full year. A positive plan balance is not evidence of available
cash; a negative one is not proof of an actual overdrawn account.

## PDF reports

The report form defaults to January–December of the current year and all own
linked accounts. At least one account is required; ranges can span years.
The default limit is 120 inclusive months, independently of dashboard year options.

The PDF starts with an overview of income, costs, balance and monthly needs,
then shows monthly development, expense category distribution, regular
commitments, exceptional expenses and transfers. Multi-year reports include
annual totals. Generation uses TCPDF directly, without external rendering or
chart services. Selecting a range and downloading does not require saving the
CMS form.

## Monthly forecast emails

### Personal settings and content

Emails are **disabled by default**. Under **Administration → Settings**, the
owner enables the master switch, individual account emails and/or an additional
overall email, then saves the CMS form. Switching the master off preserves
the individual selections.

The overall forecast includes all currently linked own accounts, even those
without individual emails enabled. All variants go **only to the current
member email address**; no custom recipients, CC or BCC are available.
Each message includes HTML and plain text plus the matching monthly PDF.
Email figures and attachment use the same booking snapshot.

The forecast covers the next calendar month and includes income, costs,
balance, the five largest expenses with optional counterparties, variable cost
review hints, periodic/exceptional burdens and separate transfers.
It compares full costs with the monthly average of all twelve months of the
**target month's calendar year**. By default, deviations of **at least ±10%**
are expensive/cheap; smaller deviations are usual. Zero annual costs do not
produce a fictitious percentage comparison.

Deficit advice quantifies the shortfall and possible variable-cost review,
without promising savings or changing bookings. If reducing all variable costs
would not cover the deficit, the remaining gap is stated. Empty planning data
produces an explicit “no bookings recorded” message, not a claim of zero real
costs. No actual balances or payment dates can be inferred.

### Operator configuration

Configure a valid sender, the application's mail transport and an existing
technical member with `ADMIN`. The module does not configure SMTP credentials
or create that member.

```yaml
---
Name: app-household-budget-mail
After:
  - household-budget-config
---
Clesson\Silverstripe\HouseholdBudget\Tasks\MonthlyBudgetForecastTask:
  cli_user_email: 'existing-admin-member@example.org'
SilverStripe\Control\Email\Email:
  admin_email: 'household@example.org'
```

Replace example addresses with deployment values. Flush configuration after
changes. Check the PHP/application timezone used by the task process;
“last calendar day” is evaluated in that timezone.

```bash
vendor/bin/sake dev/tasks/monthly-household-budget-forecast
```

For example, invoke the task hourly through server cron:

```cron
0 * * * * cd /path/to/project && vendor/bin/sake dev/tasks/monthly-household-budget-forecast
```

Alternatively select `MonthlyBudgetForecastTask` in the existing scheduler,
schedule it hourly and **disable holiday skipping**. No scheduler job is
created or enabled automatically.

Actual sending takes place **only on the last calendar day**. Other dates
produce no forecast mail. After midnight there is no catch-up, even following
outages. The task requires ADMIN authorization; the recipient must still have
household access, a valid email, active settings and a valid own account scope.
Eligibility and the date window are checked again before transport handoff.

### Delivery states and failure handling

Unique delivery records and atomic, token-checked claims prevent concurrent
processing of the same household/month/account scope. Successful variants
are not resent merely because a task reruns or bookings change.

`Accepted by transport` means mail-transport acceptance, not guaranteed delivery
or reading. Known pre-transport failures can retry on the same final day.
Uncertain handoffs are blocked from automatic retry; inspect the technical
status and mail transport manually. SMTP and a separate database cannot
guarantee end-to-end exactly-once delivery. There is no force-send or next-day
catch-up option.

One variant's failure does not stop processing others, but the task returns
failure for errors/uncertainty. Logs contain safe codes and technical identifiers,
not PDF bytes, email bodies or financial values. Delivery records are retained
for duplicate prevention; do not delete/reset them as routine cleanup.

## Developer configuration

Override options in application YAML, not the module:

```yaml
---
Name: app-household-budget-options
After:
  - household-budget-config
---
Clesson\Silverstripe\HouseholdBudget\Panels\MonthlyBudgetPanel:
  years_before_current: 5
Clesson\Silverstripe\HouseholdBudget\Panels\AnnualBudgetRankingPanel:
  maximum_positions: 10
Clesson\Silverstripe\HouseholdBudget\Reports\BudgetReportPeriod:
  maximum_months: 120
Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastService:
  threshold_percent: 10
Clesson\Silverstripe\HouseholdBudget\Services\BudgetForecastDispatchService:
  claim_lease_minutes: 15
```

| Option | Constraints / effect |
| --- | --- |
| `years_before_current` | Non-negative integer below the current year; `0` still offers current and next year. Shared by panels and monthly details. |
| `maximum_positions` | Positive integer; number of annual ranking positions. |
| `maximum_months` | Positive integer; maximum inclusive range accepted by the PDF download form. |
| `threshold_percent` | Integer 0–100; forecast cost classification against the exact annual average. At 0, any nonzero deviation is classified. |
| `claim_lease_minutes` | Integer 1–1440; preparation lease and interrupted-send uncertainty detection. Expired sending claims are never automatically resent. |
| `cli_user_email` | Required technical ADMIN member for the central forecast task. |
| `Email.admin_email` | Valid sender required for forecast preparation, in addition to working mail transport. |

### Architecture and extension points

Keep calculations out of controllers, charts, mail templates and PDF layout:

- `BudgetEntrySchedule` and `BudgetMoney`: shared recurrence and exact money rules.
- `MonthlyBudgetService`: owner-scoped dashboard/detail/chart data.
- `BudgetPeriodCalculator`: pure period, category, position and average calculations.
- `BudgetReportService`: own-account validation, ORM snapshots and contained-period reports.
- `Reports/*`: immutable renderer-independent DTOs.
- `HouseholdBudgetPdfRenderer`: local PDF layout from prepared report data.
- `BudgetForecastService` / `BudgetForecastInsightCalculator`: annual snapshot and deterministic monthly advice.
- `BudgetForecastEmailService`: localized mail/PDF preparation and transport handoff.
- `BudgetForecastDispatchService` / `BudgetForecastDeliveryRepository`: eligibility, calendar window, atomic claims and state transitions.
- `MonthlyBudgetForecastTask`: technical authorization and execution wiring only.

`HouseholdBudget::getCMSFields()` exposes `updateHouseholdBudgetFields`;
`GridFieldConfig_BudgetEntriesInHouseholdBudget` exposes `updateConfig`.
Services with dependencies are wired through Silverstripe Injector in
`_config/config.yml`. Preserve permission checks and claim semantics when
customizing them; never trust browser-provided owner/account identifiers.
Use the member locale for user-facing output and maintain German/English
translations.

The application registers private resources under `household-budget-api` and
`household-budget-report`; these are not a public frontend API. Adding public
pages or external integrations requires a separate access-control design.

For development, use the project's existing PHPUnit bootstrap and run the module
suite explicitly:

```bash
vendor/bin/phpunit silverstripe-household-budget/tests
```

Use an isolated test database and in-memory/test mail transport, not real
recipients. Do not reset delivery records or send live forecasts to test setup.

## License

BSD-3-Clause; see [LICENSE](LICENSE).
