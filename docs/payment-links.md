# Hosted invoice payments

The M1 payment-link workflow extends P2 without adding gateway fields or credentials to invoices or settled payments. `App\Billing\Providers\PaymentProvider` is the adapter boundary; `PaymentProviderRegistry` selects an adapter from `config/billing.php`. HandyPay is selected. Another provider, including EzeePayments, can implement the same interface and register its class without changing invoice arithmetic or lifecycle logic. The fake adapter lives only in tests and is never a production fallback.

## API

All routes require Sanctum, active clinic membership and the selected `X-Tenant-Id`. They use the standard API envelope and private/no-store responses. Foreign invoice/link IDs return 404. Admin/Billing can perform all link actions; Auditor can read. Explicit `payment_links.view|create|cancel|refresh` grants and the usual wildcards are also supported. Permissions are enforced inside the workflow for direct callers. Reseed `PermissionSeeder` during rollout.

| Endpoint | Request and behavior |
| --- | --- |
| `POST /api/invoices/{invoice}/payment-links` | `{"idempotency_key":"checkout-2026-001"}`. Creates a hosted checkout, returns 201 and `data.payment_link`; a finished identical retry returns 200 and the original attempt. |
| `GET /api/invoices/{invoice}/payment-links` | Paginated attempt history, optional `page`, `per_page` (1–100). Standard array and `meta.pagination`. |
| `GET /api/payment-links/{payment_link}` | Stored attempt, without making a provider call. |
| `POST /api/payment-links/{payment_link}/refresh` | Empty body. Fetches provider status, records a matching verified paid session once, and audits changes. Settled terminal statuses are no-ops; historical completed sessions without a payment can be reconciled. |
| `POST /api/payment-links/{payment_link}/cancel` | Empty body. Stores/audits cancellation only after the adapter confirms the checkout is no longer payable. Repeated cancellation is a no-op. HandyPay currently returns 409 as described below. |

The response includes `id`, `invoice_id`, `provider`, `external_reference`, `checkout_url`, `expires_at`, `status`, `amount`, `currency`, `idempotency_key`, and timestamps. The checkout URL is exposed only for an active checkout. A provider's expiry is stored when supplied; otherwise `expires_at` is null. A local clock does not prove a remote checkout is disabled: refresh must confirm expiry before replacement. Link status is separate from the invoice and ledger: `creating → active → pending/completed/expired/cancelled/failed`; an uncertain creation becomes `unknown`. Refresh may move an active checkout directly to a terminal status.

Only issued/partially-paid invoices with a positive balance accept new links. Under the invoice lock the server calculates balance from immutable invoice total minus committed settled payments and copies currency from the invoice. Clients cannot supply amounts, currency, provider, redirect URLs, expiry, status, gateway references, metadata or card fields. All undocumented write fields are rejected, including nested payloads. Checkout receives one generic line, server-controlled amount/currency, no fee passing, configured return URLs and an opaque operation UUID. Patient names, email, treatment descriptions and other clinical details are not sent. Card entry happens entirely on the provider's hosted page; this application has no card collection or tokenization endpoint and never stores raw card payloads or provider response bodies.

A creating/active/pending/unknown checkout blocks another attempt, invoice voiding, and new manual ledger payments. This prevents an outstanding checkout from charging a stale balance. Resolve or confirm remote expiry/cancellation first. An already-recorded ledger payment can still be retried. Completed checkouts without a corresponding settlement also block new links. Signed webhooks and authenticated provider lookups now settle the ledger through the same workflow. Neither the browser return URL nor a client-supplied status can mark an invoice paid. Refunds and disputes require a separate workflow.

## Tenant configuration

Run the migration, then provision each clinic from the trusted server/operator console:

```powershell
php artisan migrate
php artisan billing:configure-provider 1 handypay --success-url=https://clinic.example/payments/complete --cancel-url=https://clinic.example/payments/cancel
```

The command requests the API key through a hidden prompt, prints a new callback URL, and requests the endpoint's signing secret through another hidden prompt. Register that HTTPS URL with HandyPay using the same merchant and test/live key mode, then enter the returned signing secret. Never put secrets in command arguments, URLs, source control, tickets or browser requests. Use a merchant `hp_test_` key first. Live provisioning and live checkout creation are blocked until this clinic has a successful sandbox settlement. This is recorded in `sandbox_verified_at`; changing invoice status in a browser cannot satisfy the gate.

Configurations are scoped to a single clinic, have no public configuration endpoint and store credentials using Laravel's `encrypted:array` cast. Protect and back up `APP_KEY`; losing it makes stored credentials unreadable. Credentials are hidden from model serialization and never copied to API responses, audit data or errors. Provisioning creates a new enabled revision and disables the previous revision for new checkout creation. Old secrets and callback URLs remain available for delayed events against their original checkouts. Provisioning refuses to switch configuration while any current attempt is outstanding. Do not bypass this check by editing configuration rows directly.

## Authoritative reconciliation

`POST /api/webhooks/handypay/{webhook_endpoint}` is a server callback authenticated by `X-HandyPay-Signature: sha256={hex}`. It does not use Sanctum, browser sessions, CSRF, or `X-Tenant-Id`. The endpoint selects a credential revision; the HMAC-SHA256 signature authenticates the exact raw request bytes with constant-time comparison before JSON parsing. The request is limited to 1 MiB. Raw event bodies, clinical data and card payloads are never persisted. Event receipts retain only identifiers, a payload digest, transaction reference and processing outcome.

Subscribe to `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `payment_intent.succeeded`, and `payment_intent.payment_failed`. HandyPay puts the resource directly in `data`. Session events match the stored session reference; PaymentIntent events match the opaque `metadata.operation_key`. Supplied operation keys and test/live flags must agree with the original attempt. A callback that beats the creation response can bind a session using its signed operation key, and creation cannot overwrite that outcome.

Successful session events require `payment_status=paid`, integer `amount_total`, and currency. Successful PaymentIntent events require `status=succeeded`, integer `amount_received`, and currency. Amount and currency must exactly match the reserved checkout. The server locks the invoice and link, validates the remaining balance, and atomically inserts a settled payment, updates `amount_paid` and invoice status, audits changes, and commits the event receipt. The original session reference remains on the link; the transaction reference is retained on both the ledger payment and event receipt when supplied. Unique constraints on checkout settlement, configuration/transaction reference and configuration/event ID provide duplicate protection across refreshes, different success events and retries.

A checkout collects the remaining invoice balance. Prior partial/manual payments remain intact and the hosted payment settles the remainder. Provider amounts smaller or larger than the reserved checkout are flagged for reconciliation rather than silently changing the checkout contract. Only actual settled ledger entries affect invoice balance and `partially_paid`/`paid` status.

Unpaid completed sessions become pending. Async failure and expiry create no ledger payment. A failed card attempt leaves the checkout pending because the customer may retry; a PaymentIntent confirmed canceled maps to cancelled. Terminal failure events cannot reverse a settled payment. Late successful events are still checked against the ledger; conflicting amounts, another outstanding checkout, or an already-exhausted balance require operator reconciliation without overpayment.

Receipts have outcomes `processed`, `ignored`, `unmatched`, or `reconciliation_required`. Valid signed events are acknowledged with 200 after durable processing, including unmatched or conflicting events, to avoid repeatedly deactivating the endpoint. Operators must monitor unresolved outcomes and reconcile them in the merchant portal; resend the identical provider event after resolving its cause. Unresolved identical redeliveries are reprocessed. A reused event ID with different raw content returns 409. Invalid signatures return 401, malformed signed events 400, and transient processing failures 503. Transient failures roll back the receipt and all ledger changes so redelivery can retry safely. Duplicate processed events create no additional payment or audit. There is no provider HTTP request in webhook processing.

## Idempotency and failures

Keys are case-sensitive, scoped to an invoice, 1–100 ASCII letters/digits/dot/underscore/colon/hyphen, with a database unique constraint. The invoice lock serializes concurrent reservations. The attempt and a generated operation UUID commit **before** contacting the provider. Only the reserving request dispatches creation; provider calls are not placed inside automatically retried transactions. Success and metadata-only audits commit together. An audit/database failure after a remote success leaves the reservation in place, preventing duplicate remote creation.

An in-progress same-key retry returns 409 / `PAYMENT_LINK_IN_PROGRESS`; an ambiguous timeout/response leaves `unknown` and retries return 409 / `PAYMENT_LINK_RECONCILIATION_REQUIRED`. Neither the same key nor a new key dispatches another checkout while this remains unresolved. Definitively rejected attempts remain `failed`; retrying the same key returns that attempt, and an intentional new attempt requires a new key. Attempts are never silently recycled. Inspect attempt history after an error.

| Condition | HTTP / standard envelope code |
| --- | --- |
| Missing/disabled tenant config or unregistered adapter | 503 / `PAYMENT_PROVIDER_NOT_CONFIGURED` |
| Provider timeout | 504 / `PAYMENT_PROVIDER_TIMEOUT` |
| Provider rate limit | 503 / `PAYMENT_PROVIDER_RATE_LIMITED` |
| Unsupported operation | 409 / `PAYMENT_PROVIDER_OPERATION_UNSUPPORTED` |
| In-progress / reconciliation required | 409 / `PAYMENT_LINK_IN_PROGRESS` or `PAYMENT_LINK_RECONCILIATION_REQUIRED` |
| Gateway authentication, rejection or malformed response | 502 / `PAYMENT_PROVIDER_ERROR` |
| Provider unavailable | 503 / `PAYMENT_PROVIDER_ERROR` |

Provider exceptions contain only safe categories; upstream bodies, transport exceptions, credentials and URLs are not attached or logged by this integration. Audit events use resource `payment_links`, actions `created`, `active`, `pending`, `completed`, `expired`, `cancelled`, `failed`, `unknown`, and existing actor/request context. Amounts, URLs, credentials and external references are not copied into audits. Reads, successful retries, unchanged refreshes and rejected cancellation create no audit.

## HandyPay contract and rollout limits

Implementation follows the supplied [API reference](https://tryhandypay.com/docs/api-reference) and [OpenAPI contract](https://api.handypay.me/api/openapi.json), version `2025-01-01`. It uses `POST /api/v1/payment-sessions` with a custom amount and `GET /api/v1/payment-sessions/{id}` with a per-clinic Bearer token. HTTP redirects and automatic retries are disabled. Connections time out after 5 seconds and requests after 15 seconds. The initial adapter enables USD/JMD, the two-decimal currencies used for this rollout, and enforces the contract's 99,999,999 minor-unit limit. Other P2 invoice currencies remain valid for manual ledger payments but require explicit adapter currency support before hosted checkout.

The published hosted-session contract does **not** document cancellation, request idempotency, or expiry timestamps. The adapter does not invent these capabilities: HandyPay cancellation returns `PAYMENT_PROVIDER_OPERATION_UNSUPPORTED`, leaves the link active and emits no cancellation audit; `expires_at` stays null unless the provider supplies it. Cancellation and non-null expiry persistence are covered by the fake-provider tests. Confirm merchant-supported hosted-session cancellation with HandyPay before relying on it in production.

After an ambiguous creation or a server crash, a signed session callback with the original `metadata.operation_key` can recover the attempt. Otherwise an operator must reconcile that opaque key in the merchant portal with HandyPay support. The reservation must remain blocked until the external outcome is established; do not retry with a new key or delete the reservation blindly. If a callback is missing but the stored session reference is available, use the authorized refresh endpoint for authoritative lookup and settlement.

Before going live, run the sandbox checklist below. Automated tests exercise the actual adapter and full application workflow with HTTP stubs and signed sandbox fixtures; they do not contact or certify a real merchant.

### Merchant sandbox checklist

1. Migrate and provision the clinic with `hp_test_` credentials and a test-mode webhook endpoint. Keep live credentials out of the deployment until this succeeds.
2. Issue a sandbox invoice, optionally record a partial cash payment, then create a payment link. Confirm the hosted amount equals the remaining balance and no clinical details are sent.
3. Complete a test payment on HandyPay's hosted page. Confirm a signed callback creates one payment on the correct clinic/invoice, preserves the transaction reference, updates balance/status, and stamps `sandbox_verified_at`.
4. Redeliver the same event and a delayed success notification. Confirm one provider ledger entry and unchanged audit count. Test a forged signature and an altered body; both must fail before any write.
5. Exercise declined-card, unpaid delayed checkout, expired and canceled outcomes where supported. Confirm no false payment. Visiting the success URL alone must leave the invoice unpaid. If webhooks are unavailable, verify server-side refresh against a completed session.
6. Check for unresolved event outcomes and clear them through merchant reconciliation. Provision a new live revision only after sandbox verification. Keep old callback endpoints active for delayed events until all their attempts are resolved.

The migration adds tables without rewriting P2 history. It uses restrictive foreign keys, tenant relationship guards and unique attempt keys. Rollback refuses to discard populated link history. MySQL requires the same trigger privileges as P2 billing.

Run automated coverage with:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter 'PaymentLinkApiTest|HandyPayProviderTest|HandyPayReconciliationTest|ConfigurePaymentProviderTest|BillingApiTest'
```

`tests/Feature/HandyPaySandboxTest.php` also supplies opt-in tests against a real test merchant. It refuses live keys and skips network access by default. Through a private process environment, set `HANDYPAY_SANDBOX_RUN=1`, `HANDYPAY_SANDBOX_API_KEY` (an `hp_test_` key), and HTTPS `HANDYPAY_SANDBOX_SUCCESS_URL`/`HANDYPAY_SANDBOX_CANCEL_URL`, then run that test file. The first test creates one unpaid USD 10 test session and independently retrieves it; it does not charge a card or create live resources.

For the paid round trip, capture a genuine successful sandbox session delivery outside the repository. Set `HANDYPAY_SANDBOX_WEBHOOK_FILE` to its private raw-body file, `HANDYPAY_SANDBOX_WEBHOOK_SIGNATURE` to the original header, `HANDYPAY_SANDBOX_WEBHOOK_SECRET` to the endpoint secret, and independently specify `HANDYPAY_SANDBOX_EXPECTED_AMOUNT_MINOR` and `HANDYPAY_SANDBOX_EXPECTED_CURRENCY` (`USD`/`JMD`). The second test verifies that real signature, independently checks the paid session with the real merchant API, and drives two deliveries through the application HTTP endpoint into the test database, asserting one settled payment and a zero invoice balance. Neither test prints keys, signatures or raw bodies. Keep captured files private and out of source control. These tests have not been run against a real merchant in this change.
