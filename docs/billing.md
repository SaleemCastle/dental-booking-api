# Billing API contract

Billing is provider neutral. It records settled payments; it does not initiate charges, tokenize cards, handle webhooks or implement refunds. Future gateway adapters must verify settlement outside this domain, map their event to the documented payment fields and call `BillingWorkflow::recordPayment` with an authorized clinic member. Do not add gateway clients, credentials or card payloads to invoices or payments.

All endpoints require Sanctum authentication and active clinic membership, use the existing `X-Tenant-Id` selection and return the standard response envelope. Foreign invoice/payment route IDs return 404. Foreign relationship IDs return 422. Successful reads and writes are private/no-store.

| Endpoint | Behavior |
| --- | --- |
| `POST /api/invoices` | Create a draft with 1–100 immutable line snapshots. Returns 201, `data.invoice`. |
| `GET /api/invoices` | Newest first, optional `patient_id`, `status`, `page`, `per_page` (default 15, maximum 100). `data` is an array with `meta.pagination`. |
| `GET /api/invoices/{invoice}` | Invoice with items, `data.invoice`. |
| `PUT` or `PATCH /api/invoices/{invoice}` | Edit a draft's `invoice_number`/`due_date`, issue a draft or void an unpaid draft/issued invoice. Returns `data.invoice`. |
| `POST /api/payments` | Record a settled payment. Returns 201, `data.payment`; an identical retry returns 200 with the original payment. |
| `GET /api/payments` | Newest first, optional `invoice_id`, `page`, `per_page`; `data` array and `meta.pagination`. |
| `GET /api/payments/{payment}` | Payment record, `data.payment`. |

There are no invoice delete or payment update/delete operations (405). Financial history and line snapshots cannot be deleted; database guards also reject raw deletes and payment/line updates. Patient deletion cannot cascade away billing history. Patient archival retains billing history and allows settlement of existing invoices, while creation of new invoices for archived patients is rejected.

Create example:

```json
{
  "patient_id": 1,
  "appointment_id": null,
  "invoice_number": "INV-2026-001",
  "currency": "USD",
  "due_date": "2026-12-01",
  "items": [
    {"treatment_plan_item_id": 12, "quantity": 1},
    {"treatment_id": 3, "quantity": 2},
    {"description": "Custom service", "unit_price": "10.25", "quantity": 1}
  ]
}
```

Each line chooses one source: a treatment-plan item, an active catalog treatment, or a custom description and unit price. Source prices/names are captured by the server: plan items use their current `estimated_price` and stored `treatment_name`; catalog items use current `price`/`name`. Plan items must belong to the invoice patient and neither plan nor item may be cancelled. Catalog changes and later plan estimate changes cannot alter an invoice. Sourced lines cannot override their price/description. Quantity defaults to 1 (range 1–10,000). The optional appointment must belong to the invoice patient. Invoice numbers are unique within the clinic.

All amounts use this foundation's two-decimal denomination: nonnegative decimal input, no exponent notation, maximum 99,999,999.99 per invoice. Responses serialize money as decimal strings. Arithmetic uses integer minor units, including payment sums and overpayment checks. `currency` is an explicit uppercase three-letter label; no currency conversion, currency-exponent handling, tax or discount engine is included. Catalog/plan amounts are assumed to use the invoice's currency. Clients must not submit totals, balances, timestamps or initial status. `amount_due` is the immutable original total, `amount_paid` is the settled payment sum and `balance` is total minus payments; void invoices expose zero collectible balance while retaining the original total.

Issue with `{"status":"issued"}`. The lifecycle is `draft → issued → partially_paid → paid`, with direct `issued → paid` for full payment. Issuing a zero-total invoice marks it paid immediately. Only draft invoice number/due date can be edited. Line snapshots are immutable from creation, including in drafts; void and create a replacement to correct prices or items. Void an unpaid draft/issued invoice with `{"status":"void","void_reason":"Duplicate invoice"}` (required nonblank reason, maximum 500 characters). Paid/partially-paid invoices cannot be voided; that requires a separate future refund/reversal workflow. Terminal invoices cannot reopen. Invalid transitions return 422 / `VALIDATION_ERROR`.

Payment example:

```json
{
  "invoice_id": 1,
  "amount": "25.00",
  "currency": "USD",
  "payment_method": "bank_transfer",
  "idempotency_key": "receipt-2026-001",
  "reference": "transfer-reference",
  "receipt_number": "RCPT-001",
  "paid_at": "2026-10-26T14:00:00Z"
}
```

Only issued/partially-paid invoices accept positive payments up to the balance, in the same currency. `payment_method` is `cash`, `bank_transfer`, `card`, `check` or `other`; `card` records only the method of an already-settled payment. Optional `paid_at` defaults to server time and cannot be in the future. Optional nullable `reference`/`receipt_number` are strings of at most 255 characters and must contain only receipt/transaction identifiers. Arbitrary metadata and unknown payment fields are rejected; never send PAN, CVV, track data, PIN, expiry or cardholder credentials. Stored payment `status` is server-controlled `paid` and represents settlement, independently of the invoice lifecycle.

The required `idempotency_key` is case-sensitive, scoped to the invoice and must be at most 100 characters from letters, digits, dot, underscore, colon and hyphen. Reuse returns the original payment only when amount, currency, method and receipt/reference fields match; supplied `paid_at` must also match. Reusing a key with a different payload returns 422. A successful retry creates no additional payment or audit, including after full settlement. Each payment locks its invoice inside a retried transaction, checks the committed ledger under that lock and updates balance/status atomically. Every payment writer must use this workflow. SQLite tests verify behavior and database integrity; MySQL's row locks serialize production writers.

Admin/Billing roles can read/write. Auditor roles can read. Explicit `invoices.view|create|update` and `payments.view|create`, including the application's normal wildcards, grant corresponding actions. Payment recording does not require permission to edit invoice details. Ordinary clinical/reception roles have no implicit billing access. Authorization is also enforced in workflow methods for direct callers. Retired delete/payment-update permission grants cannot restore removed routes. Dashboard financial values require invoice read access: otherwise its financial fields are null. `billingTotals` reports each currency separately, excluding drafts/voids; the existing scalar totals remain available for a single currency and are null for multiple currencies.

Invoice creation/edits/issue/void record metadata-only `created`, `updated`, `issued`, `voided` audits on `invoices`. Payments record `recorded` on `payments` and `partially_paid`/`paid` on `invoices`. Actor, resource ID and request context are retained; descriptions, amounts, void reasons and receipt/reference contents are not copied into audit payloads. Domain changes and audits commit or roll back together. Reads, rejected writes, no-op draft updates and payment retries do not create audit entries.

Deploy with `php artisan migrate` and reseed the permission catalog as part of the normal clinic rollout. MySQL requires TRIGGER privileges and may partially commit DDL; rehearse against a restored copy and back up first. Servers with binary logging may additionally require their administrator's approved trigger deployment configuration, as a schema-scoped migration user can be rejected by server policy. Before upgrading existing invoices, set `BILLING_LEGACY_CURRENCY` to their verified common currency. Mixed legacy currencies require explicit reconciliation before migration. The migration stops before DDL for missing currency, refunded records, non-settled/nonpositive legacy payments, overpayments or cached paid totals that disagree with the ledger. It never invents payments. Valid legacy invoices keep IDs, original totals, payment records and original enum in the hidden `legacy_status` column, gain one immutable `Legacy invoice total` line and map to issued/partially_paid/paid. Empty rollback is supported; populated rollback is refused. Do not roll back dependency migrations while billing exists.

Demo data created by `DatabaseSeeder` uses `BILLING_SEED_CURRENCY` (defaults to USD; set it to JMD for Jamaican-dollar demo data). Seeded payments use the same currency as their invoice. This setting is separate from `BILLING_LEGACY_CURRENCY`, which labels existing records only when the billing migration first runs. Clear cached configuration with `php artisan config:clear` after changing these settings. Neither setting converts amounts or updates previously seeded invoices. Rerunning `php artisan db:seed` adds demo data; it does not repair existing records. Clients must use each invoice/payment's returned `currency` when formatting money and must supply currency explicitly when creating invoices and recording payments.

Validation: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit` when CLI SQLite extensions are disabled. Billing-specific coverage is in `BillingApiTest` and `BillingMigrationTest`; the repository CI also runs on MySQL 8.
