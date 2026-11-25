# P5-M0 Inventory, Procurement and Financial Boundaries Audit

**Status:** Proposed audit findings; no inventory, procurement, costing, or stock policy is approved by this document.

**Baseline:** `main` at merge commit `34a0791ae53bc2f49382eecaed056014de1deb01` (PR #74).

## Scope and result

Reviewed the Laravel API, database models/services/routes/migrations, financial architecture and billing contracts, tenant and audit conventions, tests, and the Next.js frontend repository. The current codebase has no inventory or procurement domain implementation. The frontend code search also returned no inventory, stock, supplier, or purchase-order feature. There are no verified inventory implementation defects to fix in this audit; this change adds documentation only.

Existing `Treatment` records are service catalog entries used to price invoice lines. They are not physical stock items and must not be repurposed as inventory products. Existing invoice lines preserve a description, quantity and price snapshot; they do not record stock consumption.

## Existing capabilities and constraints

| Area | Existing behavior | Evidence |
| --- | --- | --- |
| Service catalog and billing | `treatments` are clinic-scoped services. `BillingWorkflow` snapshots service or treatment-plan prices into invoice items and issues an immutable gross invoice total. | `app/Models/Treatment.php`, `app/Services/BillingWorkflow.php`, `app/Models/InvoiceItem.php`, `docs/billing.md` |
| Cash ledger | A `Payment` requires an invoice and positive amount. Manual and hosted-checkout writers serialize on invoice rows, enforce currency and balance ceilings, append payment records, update `amount_paid` and status atomically, and audit. | `app/Models/Payment.php`, `app/Services/BillingWorkflow.php`, `app/Services/PaymentSettlement.php`, `database/migrations/2026_10_27_000001_build_billing_foundation.php`, `docs/billing.md` |
| Financial meaning | `amount_due` is gross issued invoice value; `amount_paid` is the sum of settled invoice-bound payments; invoice balance is gross less paid (zero collectible balance for void invoices). No stock cost, supplier liability, tax engine, discount engine, or purchasing/AP ledger exists. | `app/Models/Invoice.php`, `docs/billing.md`, `docs/architecture/ADR-001-insurance-billing-and-financial-settlement.md` |
| Insurance boundary | Estimates and insurer observations (claims, remittances, receipts, obligations) do not post cash, modify invoices, or consume stock. Settlement v2 remains inactive. | `docs/insurance-v1-acceptance-qa.md`, `docs/insurance-receipts.md`, `docs/insurance-obligations.md`, `docs/architecture/P4-M5-B3-B-settlement-v2-foundation.md` |
| Tenant isolation | Clinic-owned models use `BelongsToTenant`; tenant context is selected through active membership. Existing migrations use tenant-qualified relationships/guards for supported domains. Inventory has no corresponding tenant schema or database constraints yet. | `app/Models/Concerns/BelongsToTenant.php`, `docs/multi-tenancy.md`, `database/migrations/2026_10_23_000001_enforce_tenant_data_integrity.php` |
| Permissions and audit | Permissions use exact `resource.action` grants. `AuditLogService` validates machine-readable actions and `AuditLog` rejects mutation/deletion. No inventory/procurement permissions or domain audit actions are defined. | `database/seeders/PermissionSeeder.php`, `app/Services/AuditLogService.php`, `app/Models/AuditLog.php` |
| API and tests | Routes expose treatments, invoices, payments, checkout, and insurance workflows. There are no inventory/procurement routes, models, factories, migrations, or feature tests. | `routes/api.php`, `app/Models`, `app/Services`, `database/migrations`, `tests` |
| Frontend | No inventory/stock/procurement/supplier/purchase-order code matches were found in the frontend repository's default-branch code search. | `SaleemCastle/dental-booking` default branch code search (`inventory stock procurement supplier purchase order`): no results |

The current financial architecture is deliberately narrow. Inventory implementation must not reinterpret `Payment`, `Invoice.amount_due`, `Invoice.amount_paid`, invoice status, hosted checkout, insurer observations, or existing payment reconciliation as purchasing or stock-cost authority.

## Security and operational requirements for future inventory work

These are implementation constraints derived from existing platform conventions, not authorization to create roles or policies here:

- Every clinic-owned item, location, lot/batch, movement, purchase order, supplier relationship, receiving record, and audit reference must be tenant scoped. Parent-child references must be tenant-qualified at the database boundary where supported, in addition to workflow authorization.
- Request authorization must use exact inventory/procurement permissions. A broad or wildcard permission must not grant stock adjustment, receiving approval, purchase approval, or valuation access implicitly.
- Audit history must be append-only and record actor, clinic, action, resource, timestamp, and safe provenance. Do not copy credentials, patient details, or supplier/payment secrets into audit metadata.
- Mutating inventory commands will need transactions, explicit lock ordering, command idempotency, and database-enforced nonnegative/consistency rules before concurrent writers are enabled.
- Patient care usage, invoice issuance, appointment completion, supplier receiving, and inventory adjustments must remain separate commands until their causal relationship and compensation behavior are approved. No workflow currently establishes automatic stock depletion.
- Do not expose supplier cost, stock levels, or purchasing data through treatment, invoice, dashboard, or report resources without explicit exact permissions and a reviewed disclosure contract.

## Decisions required before implementation

| Decision | Why implementation depends on it | Required owner(s) |
| --- | --- | --- |
| Inventory item identity and units | Define SKU/service separation, base units, permitted conversions, fractional quantities, and whether equivalent units can be combined. | Product and operations |
| Locations and stock states | Decide clinic stockroom/bin hierarchy and treatment of available, reserved, quarantined, expired, damaged, and in-transit quantities. | Operations and product |
| Lot, expiry, serial and recall traceability | Determine which categories require batch/expiry/serial capture and how recalls block use or sale. | Clinical/operations and compliance |
| Stock decrement trigger | Decide whether and when clinical usage consumes stock, how cancellations/corrections restore it, and whether invoice events have any relationship. Do not infer decrement on invoice issuance or payment. | Product and clinical/operations |
| Procurement authority | Define supplier onboarding, purchase-order approval limits, separation of requester/approver/receiver, partial receipts, over-receipts, backorders, and returns. | Finance and operations |
| Costing and valuation | Select FIFO, weighted average, specific identification, or non-valued quantity tracking; determine treatment of landed cost, supplier credits, write-offs, and valuation periods. No method is currently encoded. | Finance/accounting |
| Tax and currency | Approve supported currencies, exchange-rate source/date, rounding, supplier tax handling and jurisdictional requirements. This audit does not invent rates or legal treatment. | Finance/accounting and legal |
| Accounts payable boundary | Decide whether P5 records supplier invoices/liabilities or only operational purchase/receipt facts, and which approved accounting system owns payable balances. | Finance/accounting and architecture |
| Permissions and audit retention | Name exact roles/actions, approver separation, sensitive cost visibility, audit details and retention. | Security, product and privacy/compliance |
| Migration and existing catalog | Define whether any existing treatments map to physical products. No automatic conversion or stock opening quantity can be inferred from current data. | Product and operations |

Until these decisions are approved, do not post purchasing costs, recognize supplier liabilities, value inventory, automatically consume or replenish stock, or map treatment services to inventory items.

## Recommended implementation sequence

1. **P5-M0 decision closure:** approve item/unit, location, traceability, consumption, procurement authority, valuation, currency/tax, accounts-payable, permission/audit, and migration policies above. Keep all statuses pending until supplied by the authorized owners.
2. **P5-M1 catalog and quantity foundation:** add tenant-scoped stock item, unit, location, lot/batch and append-only movement records only for approved semantics; add exact permissions, constraints, audit, API contracts and negative tenant tests. Keep invoice/payment behavior unchanged.
3. **P5-M2 supplier and purchase orders:** add supplier and purchase-order workflows with explicit approval separation; do not create supplier payable/payment entries absent AP approval.
4. **P5-M3 receiving and valuation:** record partial receipts, lot/expiry and approved cost/valuation facts; ensure receiving is not treated as supplier cash payment and adjustments use compensating history.
5. **Separate clinical usage integration:** implement stock consumption only after approved clinical triggers and reversal rules; test concurrency, idempotency, cancellations and inventory/payment non-mutation independently.

## Verification and residual risk

- Audit performed against the latest merged backend main commit and the frontend default-branch search.
- No application behavior, migration, permission seed, API, or test was changed; no test run was needed for this documentation-only audit.
- Repository search confirms no existing inventory/procurement routes, schema, models or tests, but does not replace hosted frontend CI or operational policy approval.
- Database transaction, tenant foreign-key, append-only, concurrency, and monetary/quantity constraints for future inventory writers remain entirely unimplemented and must be verified on PostgreSQL before release.
- P5-M0 is complete as an audit artifact. Implementation remains blocked on the specific product, operations, finance/accounting, legal, security/privacy and architecture decisions listed above.
