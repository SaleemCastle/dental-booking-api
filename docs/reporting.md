# Aggregate reporting

All reports require Sanctum authentication and active clinic membership. `X-Tenant-Id` selects the clinic using the normal tenant contract. Reads are private/no-store and return the standard API envelope. Reports reuse existing domain read policies, including admin access and the normal permission wildcards; there is no independent `reports.*` grant that bypasses source permissions.

| GET endpoint | Required access | `data` |
| --- | --- | --- |
| `/api/reports/appointments` | Appointment read (`appointments.view` or admin) | Overall count, status counts, daily counts |
| `/api/reports/providers` | Appointment read | Paginated provider activity and optional utilization |
| `/api/reports/treatments` | Appointment **and** catalog treatment read | Paginated counts by treatment ID |
| `/api/reports/billing` | Invoice **and** payment read | Totals grouped by currency |

Billing and Auditor roles inherit their usual billing read access. Clinical or reception roles have no implicit billing report access. Custom roles need both `invoices.view` and `payments.view`. A catalog-only reader cannot read treatment activity. Missing source permission returns 403 before filter validation.

## Filters and dates

Every endpoint requires `date_from` and `date_to` as exact `YYYY-MM-DD` calendar dates, ordered and spanning at most **366 inclusive dates**. Bounds use the application timezone (UTC). The query includes midnight on `date_from` and excludes midnight after `date_to`, including records in the final day's last second. No timestamps, offsets, or relative dates are accepted. `meta.filters` echoes normalized dates/provider selection and `meta.timezone` identifies the timezone.

Optional `dentist_id` is the provider's canonical integer ID, validated in the selected clinic. Invalid, foreign-clinic and explicitly blank IDs return 422. Omitting it includes every provider. Billing uses the invoice's linked appointment for provider attribution; invoices without an appointment contribute only to the unfiltered report. Payment attribution follows that same invoice appointment. There is no allocation across treatment-plan providers, invoice lines, or patients. Reassigning/deleting an appointment can change this attribution; these are current-data reports rather than immutable accounting snapshots.

Example:

```http
GET /api/reports/appointments?date_from=2026-10-01&date_to=2026-10-31&dentist_id=12
Authorization: Bearer <token>
X-Tenant-Id: 1
```

## Appointment metrics

Appointments are selected by **scheduled start**, not creation, check-in or completion time. Status is the current persisted status at request time. `appointment_count` counts every selected appointment once, including cancellations and no-shows. `by_status` always includes all six statuses with integer counts: `scheduled`, `checked_in`, `in_progress`, `completed`, `cancelled`, `no_show`. `by_date` is chronological, contains the same counts for each nonempty date, and omits dates with no appointments. Empty results have total zero, zero status counts and an empty daily list. Later status edits change historical reports; physically deleted appointments are absent.

## Provider activity and utilization

The provider report includes every current clinic provider, including those with zero activity, ordered by `dentist_id`. It returns IDs without provider names/contact details. Activity uses the same start-date selection and status definitions as the appointment report:

- `appointment_count` and `by_status`: counts of appointments that start within the period.
- `booked_minutes`: sum of planned `duration_minutes` for scheduled, checked-in, in-progress and completed appointments that start within the period; excludes cancelled/no-show time.
- `completed_minutes`: planned duration of completed appointments that start within the period. This is not observed chair time; workflow timestamps do not reliably measure procedure duration.
- `available_minutes`: union of configured working intervals minus the union of overlapping breaks across the requested dates. A date exception replaces both recurring hours and recurring breaks for that date; empty exception hours mean time off. Duplicate/overlapping hours or breaks never inflate/subtract capacity twice. `24:00` is the following midnight.
- `occupied_minutes`: union of scheduled, checked-in, in-progress and completed appointment intervals intersected with effective available intervals and the report window. Overlapping historical bookings count once. Bookings starting the previous day are included when they cross into this period (the booking API caps durations at 1440 minutes). Outside-hours time, cancellations and no-shows do not contribute.
- `utilization_percent`: `occupied_minutes / available_minutes * 100`, rounded to two decimals, between 0 and 100. Calculation uses unrounded seconds. This represents occupation of **currently configured** capacity, not employee productivity or a historical availability snapshot.

The last three metrics additionally require `provider_availabilities.view` (or admin). Otherwise they are `null` and schedule records are not queried. An unconfigured provider also returns three null values, since capacity is unknown. A configured closed period has zero available/occupied minutes and null utilization because division by zero is undefined. Positive capacity without bookings has utilization zero. Planned minutes can exceed occupied minutes because of overlap, changed schedules or boundary clipping. For historical performance analysis, a future version should retain availability versions and observed procedure durations rather than treating these approximations as actual hours worked.

## Treatment/procedure metrics

Rows group appointments by non-null `treatment_id`, ordered by ID. `appointment_count` includes every status; `completed_count` includes only appointments currently marked completed. One completed appointment counts as one occurrence of its assigned catalog treatment. A treatment can still appear after catalog deactivation. Appointments without a treatment are excluded. IDs are returned without catalog prices or patient data; authorized clients may use the treatment catalog for labels.

This backend does not record a separate performed-procedure ledger. Plan proposals, invoice quantities, and repeated payments do not establish that a procedure was performed, so they do not contribute to completed counts. Multiple procedures within one appointment require a future explicit procedure-event model; do not infer them from invoices or count plan items again.

## Financial metrics

All amounts are decimal **strings**, calculated using integer minor units and never added across currencies. `data.by_currency` is sorted by currency; currencies with only collections still appear. An empty report has an empty list, with no assumed default currency.

| Field | Definition |
| --- | --- |
| `invoice_count` | Current issued/partially-paid/paid invoices whose `issued_at` is within the window |
| `invoiced_total` | Sum of original `amount_due` for those invoices |
| `paid_total` | Sum of their current settled `amount_paid`, including payments outside the selected period |
| `outstanding_balance` | `invoiced_total - paid_total`, the **current** remaining balance for the issue-date cohort |
| `payment_count` | Settled payment records whose `paid_at` is within the window |
| `collections_total` | Sum of those payments, including payments against invoices issued outside the window |

Draft and void invoices are excluded from the issue-date cohort. Payment recording is append-only; retries do not add collections. `collections_total` and `paid_total` answer different questions and are not interchangeable. The outstanding balance is neither an all-time receivables total nor a balance reconstructed as of `date_to`. Current records, including later payments and voids, affect reruns. There is no currency conversion, revenue recognition, tax, refund or accounting-period-close calculation. Reports read current tables without a point-in-time database snapshot across queries; concurrent writes can change values between queries or pages. Use a dedicated accounting snapshot workflow when reconciliation requires a fixed cut-off.

## Pagination, exports and privacy

Provider and treatment reports accept `page` (minimum 1) and `per_page` (default 25, maximum 100); both return a data array with the standard `meta.pagination`. Group pagination happens in the database before provider capacity is calculated. Appointment daily results are bounded to 366 dates; billing is grouped by currency. Those two aggregate endpoints return the whole bounded result and do not use pagination parameters. All reports reject invalid pagination values if supplied.

Only aggregates, dates and provider/treatment IDs leave these endpoints. Queries do not join/load patient records or return names, identifiers, notes, tooth context, invoice numbers, receipt numbers or references. Counts of one are still potentially sensitive and require source read permissions. Date predicates use direct timestamp bounds and clinic/date indexes, rather than wrapping indexed columns in date functions in the WHERE clause. No reporting migrations change existing business data. Deploy indexes with `php artisan migrate`; rehearse index creation on large production tables to assess its write-lock impact.

Detailed reports and download routes are **not exposed** in this milestone. For existing detail views, use the separately authorized paginated appointment/invoice/payment APIs with their documented filters; aggregate access does not introduce extra patient fields. Clients can export provider/treatment aggregate pages or split appointment requests into bounded date windows, preserving filters, currency and metric definitions. Do not promise an exact snapshot when combining pages from a changing dataset.

A future detailed export should use stable `(timestamp, id)` cursor/keyset pagination, bounded pages (at most 100), a fixed snapshot/cut-off, and the same clinic/source policies. Large exports should be queued with clinic context and requester identity, reauthorize execution/download, include an explicit field allowlist, escape spreadsheet formula cells for CSV, audit the export, and store short-lived files privately. Never add an unbounded `per_page=all` or reuse an aggregate response to embed patient rows. These are design requirements, not implemented export capabilities.

## Verification

`ReportingApiTest` exercises authentication, domain permissions/wildcards, restricted availability, clinic isolation, date boundaries/validation, pagination, zero capacity, breaks/time off, overlapping/cross-midnight bookings, completed-treatment counts, decimal totals, multiple currencies, issue-date versus collection-date selection, provider attribution and payload privacy. Run on a dedicated test database:

```shell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/Api/ReportingApiTest.php
```

The suite uses `RefreshDatabase`; do not run it against the development database. OpenAPI and generated Postman requests cover all four routes.
