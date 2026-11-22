# ADR-001: Insurance Billing and Financial Settlement

Status: **Recommended; awaiting architecture approval. Not implemented.**
Date: 2026-11-13. Scope: P4-M0 decision package; delivery in P4-M5.
Companions: ADR-002 (Insurance Evidence and Document Retention) and ADR-003 (Insurance Benefits and Claims Lifecycle). Their local drafts, like the P4-M0 audit and coverage source, are not tracked in the B3-F `origin/main` baseline and are not restored by this change.
Baseline: P4-M0 audit, sections C-H, and its audit coverage source. These decisions refine that audit, not the PRD or implementation status; those source files are not tracked in the B3-F `origin/main` baseline.

Provenance note (P4-M5-B3-F): restored from an untracked, ignored local draft after no tracked ADR-001 was found in repository history or branch trees. The source draft's SHA-256 at restoration was `079ACDBA17CD29F42612FA8E9F651FEE42120383D1555BAAF121841AD5D85CC1`; this file adds provenance and approval-register notes. Original authorship and approval provenance remain unverified. The status above is preserved; this restoration is not approval.

## 1. Context and current implementation constraints

- [Invoice](../../app/Models/Invoice.php) and [InvoiceResource](../../app/Http/Resources/InvoiceResource.php): immutable gross `amount_due`, `amount_paid`, `balance = amount_due - amount_paid` (zero for void), statuses `draft/issued/partially_paid/paid/void`.
- [BillingWorkflow](../../app/Services/BillingWorkflow.php), [RecordPaymentRequest](../../app/Http/Requests/RecordPaymentRequest.php) and [billing integrity migration](../../database/migrations/2026_10_27_000001_build_billing_foundation.php): positive invoice-level Payments only; sum of Payments equals invoice `amount_paid`; no overpayment, payment rewrites/deletion, refunds, write-offs or reopening paid invoices. Price snapshots remain in `invoice_items`.
- [Payment](../../app/Models/Payment.php), [PaymentResource](../../app/Http/Resources/PaymentResource.php) and [PaymentController](../../app/Http/Controllers/Api/PaymentController.php) require invoice association today. They do not distinguish payer, receipt, line allocation or unapplied funds.
- [PaymentLinkWorkflow](../../app/Services/PaymentLinkWorkflow.php), [PaymentSettlement](../../app/Services/PaymentSettlement.php) and [PaymentWebhookWorkflow](../../app/Services/PaymentWebhookWorkflow.php) serialize invoice then checkout-link writes; checkout amount currently uses gross unpaid balance. In-flight/uncertain provider outcomes must be reconciled before changing collection capacity.
- [ReportingService](../../app/Services/ReportingService.php) sums positive Payments for collections and invoice fields for issue-cohort balances; it is not an accounting revenue-recognition engine. [Money](../../app/Billing/Money.php) uses integer minor units and explicit two-decimal limits.
- [API response envelope](../../app/Support/ApiResponse.php), [routes](../../routes/api.php) and Sanctum are existing contracts. Frontend `../dental-booking/src/services/billing.ts` sums old invoice balances; it cannot safely infer new patient responsibility from those fields.

Constraint: retaining the exact existing `SUM(positive Payments)` semantics, terminal paid state and overpayment guards cannot also provide arbitrary refunds and payer substitution on that same legacy representation. Preserving JSON shape alone does not solve this contradiction. This ADR chooses an explicit compatibility boundary instead of silently redefining old fields.

## 2. Considered alternatives and tradeoffs

| Alternative | Tradeoff and disposition |
|---|---|
| Record estimated/confirmed benefits or write-offs as Payments | Makes cash and collections false; rejected |
| Add negative Payments; overwrite invoice totals or settled Payments | Breaks immutable history, old request constraints and gross charge snapshots; rejected |
| Silently make old `amount_paid`, `balance` and `paid` mean net settlement | Existing clients/reports can misread noncash credits as cash or sum original receipts incorrectly; rejected |
| Separate insurance invoice/payment ledger | Duplicates billing, permits double settlement and reconciliation drift; rejected |
| Keep all invoices permanently legacy; only accept insurer money up to remaining gross balance | Safe interim restriction, but cannot satisfy refunds/unapplied/multi-invoice remittances; remains the fallback until the compatibility gate passes |
| Extend existing billing ledger with immutable receipts, allocations and corrections; expose an explicit settlement version | Recommended. More migration/client work, but one source of cash truth and explicit compatibility behavior |

## 3. Recommended decision and rationale

### 3.1 One billing ledger, distinct economic facts

Retain existing invoices/items and Payments. Extend **the same billing Payment ledger** to represent a positive actual cash receipt independent of allocation. A new receipt can cover several invoices or remain unapplied; existing invoice-bound Payments remain intact. A remittance header/advice is provenance and grouping, not another cash posting. Never record an advice and its Payment as two receipts. No separate insurance balance store becomes authoritative.

For settlement-version receipts, allow a nullable original invoice association in the internal model; invoice settlement is derived through allocations, not that column. Existing records keep their association. New receipt rows have an explicit contract version, payer identity and immutable source key. The old Payment resource must never emit a null `invoice_id` or an unsupported new row shape. This is a proposed migration, not a change made here.

| Fact | Definition / authority |
|---|---|
| Gross charges G | Sum of original issued invoice-line charges. Insurance decisions never change G. Correct a charging error through a linked billing adjustment, retaining original charge history |
| Responsibility | Effective-dated decision assigning each outstanding charge to insurer, patient or unresolved/disputed owner; estimates are advisory overlays, not financial postings |
| Receivable | Remaining collectible claim against an assigned payer after approved credits and cash allocations. Unknown or disputed payer amounts remain explicit; do not automatically assign them to the patient |
| Actual receipt R | Verified received cash, with payer, method, currency, received_at and evidence. Pending cheque/transfer/advice is not R until receipt verification policy is met |
| Cash allocation A | Net amount of an actual receipt applied to an invoice line, optionally attributable to a claim line. Applying already received money creates no new receipt |
| Refund/return F | Actual outbound cash linked to original receipt(s), amount, recipient, evidence and authorization. Pending instructions reserve funds but are not completed refunds |
| Write-off/credit W | Net approved noncash reduction of charges, with type/reason, line, approval and evidence. Not a Payment, receipt, refund or cash collection |
| Unapplied credit U | Receipt funds still held, unallocated and not returned. A liability to the entitled payer/beneficiary, not invoice revenue or automatically transferable patient credit |
| Reversal | Append-only compensation of an identified allocation, adjustment or decision. Reversing an allocation restores available cash; it does not send cash. Correcting a duplicate ledger receipt reverses an erroneous entry without pretending a bank refund occurred |

Recognized revenue is separate from all these measures. Issuing a charge, confirming benefits, adjudicating a claim, collecting cash and writing off a balance have different meanings. This domain supplies source events to an approved accounting policy/export; **it does not assert when or how much revenue is recognized**. Report `recognized_revenue = not_calculated` until that policy is approved; never alias it to `collections_total`, gross charges or receivables. Unapplied credits are not recognized revenue by this application.

### 3.2 Authoritative projections and conservation

For each issued line and currency, use integer minor units:

```text
G = immutable gross charge
W = approved credit/write-off events minus their linked reversals
A = allocations minus allocation reversals (cash actually applied now)
B = G - W - A                                  # collectible balance
B = insurer_receivable + patient_receivable + unresolved_receivable

For each actual receipt:
R = net valid received cash after separately identified posting-error corrections
F = completed refunds/returns against that receipt
R = A_across_all_lines + U + F
net_cash_collections = sum(R) - sum(F)          # report by receipt/refund date
```

All terms are nonnegative except a typed compensating event's effect. Require `W + A <= G` per line; a proposed credit against an already fully funded line first releases the corresponding cash allocation to U in the same transaction. Require `A + F <= R` per receipt and disallow allocations of reserved-for-refund U. Only the entitled payer's funds can be allocated or refunded under an approved instruction; insurer money cannot pay another patient's debt merely because it shares a batch.

A receipt posting-error correction is permitted only for a proven erroneous ledger entry (for example, an accidentally recorded duplicate of one bank transaction). It cannot reverse money that was actually received and retained; that requires an actual refund/return or continued U. Pending refund instructions may reserve named currently allocated funds for a planned release, as well as unapplied funds and replacement insurer funds. Such reservations block conflicting use but do not change A/F/U or collections until the outbound cash is verified; completion releases the named allocation and records F in one reconciled transaction.

Responsibility partitions **outstanding B**, not total gross plus cash. A patient who pays an insurer's expected share extinguishes that outstanding charge; the pending insurer claim can remain a contingent reimbursement/dispute, but cannot create another collectible invoice balance or another allocation above G. Keep gross adjudicated entitlement and expected future receipt separate from current receivables. Transfer payer responsibility with immutable decisions, preserving prior as-of views.

Authoritative `invoice_settlement` contains G/W/A/B, payer receivables, pending/disputed flags, cash-refund liabilities, version, as-of event watermark and settlement basis. Cached totals are rebuildable projections updated with the events in one transaction; financial writes recheck under locks and never rely on stale asynchronous caches. Report tenant cash independently of invoice issue cohorts. Do not double-count allocation applications as period collections. Use explicit currency, no FX, and preserve existing supported two-decimal bounds; aggregate precision must exceed a single invoice bound.

### 3.3 Compatibility and invoice states

Keep legacy invoices, resources, routes, request rules, envelope, pagination, error behavior and Sanctum unchanged while they remain in legacy mode. Existing idempotent retries still return the original Payment and original 422 validation behavior on changed legacy payloads. Do not add fields to the old resource as a covert semantic migration.

Recommend **explicit opt-in settlement version 2**, using additive `/api/billing/v2/...` resources within the existing API/auth conventions. New cash/allocation records extend the same tables/domain. Migrating an invoice requires complete reviewed starting allocations, no unresolved hosted checkout, a cutover watermark and supported clients. Its original invoice/item/Payment fields and rows remain historical legacy facts; no old gross `amount_paid` field is updated to a fabricated adjusted value. Settlement commands/read models become authoritative thereafter.

For an opted-in invoice, legacy invoice/payment reads, writes, payment-link creation and affected financial aggregates must fail closed with the existing error envelope and proposed HTTP 409 `SETTLEMENT_VERSION_REQUIRED`, linking the supported resource without PHI. Do not return a stale legacy balance as a current patient debt, omit version-2 rows from an apparently complete total, or expose a nullable-invoice receipt through the old resource. Unaffected legacy records retain normal behavior. Collections/filter requests spanning modes require the version-aware report; historic legacy receipt lookup may be offered only through an explicitly named historical resource, not an undocumented exception. Old-key replay lookup remains possible inside the new compatibility adapter and must not post again.

This preserves legacy contracts for existing supported workflows and avoids silent semantic changes; it does **not** promise that an unupgraded client can use newly corrected invoices. Product must approve the explicit 409/capability boundary and inventory external consumers. If uninterrupted successful legacy reads on corrected invoices are mandatory, advanced corrections remain disabled until a separately approved adapter/version contract exists. This is an activation blocker, not permission to relax financial invariants.

Version-2 invoice business state is a projection, not a mutation of original cash facts:

| Event / condition | Settlement state and effect |
|---|---|
| Issued, B=G>0 | `open` |
| 0<B<G after cash or approved noncash credit | `partially_settled`; include `settlement_basis=cash/adjusted/mixed` |
| B=0, no unapplied/refund liability tied to invoice | `settled`; only `cash_paid=true` when A=G and W=0 |
| B=0 with funds owed back | `credit_due`; retained cash is a liability, not an outstanding invoice charge |
| Refund releases A and returns funds, no replacement cash or credit | B increases; `settled` reopens to `partially_settled` or `open`. Never reopen the historical legacy `paid` field silently |
| Write-off reversal | W decreases, B increases, same reopening rule unless simultaneously covered by valid cash |
| Patient refund plus insurer substitution | Release patient A, complete refund and apply insurer U atomically on verified completion; B stays0 if replacement fully covers it |
| Technical rejection/denial/appeal | Changes claim/responsibility or dispute flag only; never implies cash or automatic write-off |
| Valid void before any posted cash/adjustment/claim commitments | `void`; preserve G, collectible B=0 by explicit void projection. No paid invoice void shortcut |

`disputed` is an independent flag/amount, not an excuse to drop B or rewrite state. Per-invoice state and allocation events have monotonic versions; settled invoices can reopen only through authorized linked events.

### 3.4 Six exact financial scenarios

Illustrative JMD, not insurer rules. Original lines A=6,000.00 and B=4,000.00; G=10,000.00. Each row satisfies the equations. Estimates/benefit confirmation alone have R=A=F=W=0. Revenue remains not calculated in every scenario.

| Scenario / checkpoint | Receipt and line allocations | W / B / payer receivables | Cash conservation and state |
|---|---|---|---|
| 1. Full coverage confirmed, before cash | Expected insurer10,000.00; A0.00 | W0.00; B10,000.00, insurer10,000.00 if assigned by responsibility decision; otherwise unresolved | R0.00, F0.00, U0.00; `open` |
| 1. Insurer bank receipt | Insurer R10,000.00 allocated A6,000.00/B4,000.00 | W0.00; B0.00 | 10,000.00=10,000.00+0.00+0.00; net collections10,000.00; `settled`, cash |
| 2. Partial, insurer paid first | R8,000.00 allocated A4,800.00/B3,200.00 | W0.00; B2,000.00 patient A1,200.00/B800.00 | 8,000.00=8,000.00+0.00+0.00; net8,000.00; `partially_settled` |
| 2. Patient completes payment | Patient R2,000.00 allocated A1,200.00/B800.00; total A10,000.00 | W0.00; B0.00 | R10,000.00=A10,000.00+U0.00+F0.00; net10,000.00; `settled` |
| 3. Technical rejection | No receipt/allocation; keep correction lineage | W0.00; B10,000.00. Prior insurer8,000.00/patient2,000.00 assignment remains, flagged disputed as appropriate | R=F=U=A=0.00; `open`; no automatic patient transfer |
| 3. Final denial, approved patient transfer | Still no cash; decision transfers insurer 8,000.00 to patient | W 0.00; B 10,000.00 patient A 6,000.00/B 4,000.00 | Net collections 0.00; `open`. If patient transfer is not permitted, retain disputed/unresolved responsibility instead |
| 4. Underpayment after patient share | Patient R2,000.00 allocated1,200.00/800.00; insurer R7,000.00 allocated4,200.00/2,800.00; total A9,000.00 | W0.00; B1,000.00 insurer disputed A600.00/B400.00 | R9,000.00=A9,000.00+0.00+0.00; net9,000.00; `partially_settled` |
| 4. Approved contractual write-off | No new cash; credits A600.00/B400.00 | W1,000.00; B0.00 | R9,000.00, A9,000.00, U=F=0.00; net9,000.00; `settled`, mixed. Reversing W reopens B1,000.00 |
| 5. Patient pays before insurer | Patient R10,000.00 allocated A6,000.00/B4,000.00 | W0.00; B0.00; outstanding invoice receivables0.00; insurer claim8,000.00 is contingent reimbursement | Net10,000.00; `settled` |
| 5. Insurer receipt arrives | Insurer R8,000.00 retained U8,000.00; do not allocate above G | W0.00; B0.00 | R18,000.00=A10,000.00+U8,000.00+F0.00; net18,000.00; `credit_due`, liability8,000.00 |
| 5. Authorized patient reimbursement completed | Release patient A4,800.00/B3,200.00; actual patient refund8,000.00; apply insurer U A4,800.00/B3,200.00. Remaining patient A1,200.00/B800.00 | W0.00; A10,000.00; B0.00 | R18,000.00=A10,000.00+U0.00+F8,000.00; net10,000.00; `settled`. No second receipt on application |
| 5. Alternative, return insurer funds | Keep original patient A10,000.00; complete insurer return8,000.00 | W0.00; B0.00 | Same R18,000.00=A10,000.00+0.00+F8,000.00; net10,000.00; `settled`. Mutually exclusive with patient-reimbursement route |
| 6. Duplicate R-17 remittance | First insurer R 8,000.00 allocated 4,800.00/3,200.00. Exact replay returns original receipt; changed key with same bank identity cannot insert another receipt | W 0.00; B 2,000.00 patient | R 8,000.00=A 8,000.00+0.00+0.00; net 8,000.00 unchanged after every replay. Changed amount/hash gives 409, no posting |

If refund instructions are sent externally, record a durable pending operation and reserve its funds. Do not hold a database transaction across a bank call. On verified completion, commit the actual refund, allocation release and insurer substitution together. An uncertain result remains pending/reconciliation-required with funds reserved; no retry sends a second refund blindly. Product must authorize which payer is entitled to the held 8,000.00 before either scenario-5 route.

Additional batch example: one actual Payment receipt12,000.00, allocations8,000.00, U4,000.00, F0.00. Collections12,000.00, not20,000.00. This **supersedes** the audit's tentative split of8,000.00 Payments plus a separate cash-bearing credit: U is now a projection of that one Payment receipt, not another receipt record.

## 4. Data model and API implications

Proposed extensions, all tenant-owned with restrictive same-tenant references:

- Existing `payments`: immutable positive receipt amount/currency, contract version, payer attribution, source bank/provider identity, verification/received timestamp; invoice association retained for legacy, optional for version 2. No negative Payment or amount rewrite.
- `payment_allocations` and linked reversal events: receipt, invoice item, optional claim/remittance line, amount, actor and effective/recorded time. Same patient/invoice/claim checks. Unique source-operation keys; reversal totals cannot exceed original amount. Partial reversals are explicit bounded events with unique command IDs; they do not require a second arbitrary allocation.
- `billing_adjustments`, `billing_refunds`, typed receipt-correction events and approvals: reason, original event, amount, evidence manifest, effective/recorded times, approval actor and command. Unique compensating identity and bounds; no cascade deletion or change to posted entries.
- `invoice_responsibility_events`, `invoice_settlements` and receipt-balance projections: immutable decisions plus rebuildable versioned summaries. `insurance_remittances`/advice lines only reference Payment(s); one external bank transaction posts once, including partial advice matching. `unapplied credits` derive from receipt minus allocations/refunds, with restriction/entitlement metadata.
- Durable billing command receipts: unique `(tenant_id, operation, idempotency_key)`, canonical payload hash/version, actor, result IDs and original success status. Independently unique bank cash identity `(tenant_id, cash_account_id, bank_namespace, bank_transaction_id)` prevents the same received funds being posted again under another payer or command key. Provider identity retains configuration/transaction uniqueness. Payer/advice reference uniqueness is scoped by tenant/insurer/reference namespace and identifies advice, not another cash receipt. A consolidated bank deposit matched to multiple advice records still posts only one receipt, with reviewed payer suballocations whose sum cannot exceed it. Missing reliable bank/cash identity requires staff-reviewed source identity and duplicate review before posting; hash/file similarity alone is not reliable transaction identity.

Posted receipt/allocation/adjustment/refund/responsibility events and successful command results reject UPDATE, DELETE and TRUNCATE at the database boundary as well as through ORM policies. Mutable summaries are explicitly distinguished from immutable source history. Aggregate bounds require guarded database writers/constraint triggers that take the same parent locks, not only application validation; raw-write and concurrent-commit tests must prove this. Do not attempt an aggregate SUM in an ordinary CHECK without safe serialization.

Version2 resources include invoice settlement/allocations, receipts and applications, refund instructions/completions, adjustments/reversals and responsibility history. Commands require expected version, reason/evidence/approval where relevant; amounts are decimal strings and server controls ownership/status/timestamps. Command results bind the authorized actor as well as canonical payload/hash version; a changed actor/payload on an existing identity yields a safe conflict, never another posting or disclosure of the original payload. Propose `GET /api/billing/v2/invoices/{id}/settlement`, `POST /api/billing/v2/receipts`, `/receipts/{id}/allocations`, `/receipts/{id}/refunds`, `/invoices/{id}/adjustments`, and explicit reversal commands. Preserve the existing envelope; 401/403/404/422 retain meaning; new 409 conflicts have stable codes. Unknown nested fields, forged tenant/payer identity and client-computed balances are rejected. New endpoint design is not implementation authorization.

## 5. Security, tenancy, privacy and audit

Use [TenantContext](../../app/Tenancy/TenantContext.php), [tenant model concerns](../../app/Models/Concerns/BelongsToTenant.php), [Gate registration](../../app/Providers/AuthServiceProvider.php) and [AuditLogService](../../app/Services/AuditLogService.php). Enforce workflow-level object authorization in jobs/direct callers as well as routes. Financial operations require source read permissions and distinct cash-post, responsibility-transfer, adjustment-approve and refund-approve permissions. Proposer cannot approve their own privileged correction; no broad wildcard bypass of special policies. Tenant membership is rechecked on retries; idempotency is not authorization.

Record event/approval/reversal IDs, request ID, actor and reasons in protected business history; generic audit contains safe identifiers only. Payer bank evidence and PHI follow ADR-002. Same-tenant FK plus same-invoice/patient/claim constraints defend bulk writes; every cache, export, job and projection is tenant scoped. Do not expose foreign IDs through different validation errors. Preserve attribution after staff departure.

### Transaction boundaries, lock order and PostgreSQL concurrency

For existing and future **cash-affecting writers**, choose all affected IDs before mutation; discovering additional parents requires rollback and retry with a complete set. Use one documented order:

1. Tenant/operation idempotency intent lock (transaction-scoped advisory lock or durable key row); obtain a sorted set if several source identities are involved. Lock new receipt identity even when no invoice is selected.
2. All affected invoice parent rows in ascending ID. Financial commands must not later acquire patient/plan locks; invoice creation retains its separate patient -> plan -> invoice order and does not acquire existing receipt locks.
3. Existing checkout links in ascending ID, then provider configuration rows in ascending ID, then provider delivery-event receipts by stable event key. This preserves the current invoice -> link -> configuration webhook order. A create operation may insert a new link after configuration only when its invoice/command lock already serializes its unique identity; it must not discover/lock another existing link or invoice at that point. An unmatched provider event can lock configuration/event only if it never acquires an invoice later; if matching changes, roll back and restart with the full parent set. Active/uncertain checkout blocks balance-changing commands until reconciled; provider completion posts actual received funds to U if collection capacity changed, never discards cash or over-allocates.
4. Claim parents, claim-reservation parents and benefit-bucket parents in that type order, each ascending ID; then existing cash receipt rows, invoice lines, allocation rows, adjustment rows and refund rows, each type and ID sorted. All receipt-application/refund paths follow invoice-before-receipt. Receipt-only recording/return may start at receipt when it never acquires an invoice later; application is a separate transaction.
5. Append business events, audit and durable command result; update projections under the same locks; commit together. Source evidence must already be retained/validated; no storage/network side effect in a retried financial transaction. Evidence manifests are linked under the common lock order in ADR-002.

Locking a receipt serializes allocations/refunds across different invoices; locking invoice parents serializes different payer receipts against the same charges. Unique constraints handle simultaneous absent-key insertion; application code cannot rely on an unlocked SUM or on `FOR UPDATE` locking a nonexistent row. PostgreSQL READ COMMITTED plus these parent locks and database invariant checks is the minimum; any path using SERIALIZABLE still needs bounded retry of SQLSTATE `40001`/`40P01` with the **same** command identity. Evaluate the committed result after a lost response; same key/hash returns original, different payload 409. Do not acquire receipt then discover/lock invoices in reverse order. Any provider configuration/receipt identity path that cannot obey the hierarchy must be refactored before activation.

Require real PostgreSQL tests with independent connections, synchronization barriers and actual commits. Prove one winner/no over-allocation for patient vs insurer, two remittances, refund vs application, duplicate bank identity under different keys, checkout vs write-off, and multi-invoice batches. Test deadlock/serialization retry and crash before/after commit; SQLite success is insufficient. Audit rollback must undo projections and posting result. Current MySQL/SQLite support is not silently removed: supported-database policy must be approved, and adapters/guards separately verified before enabling elsewhere.

## 6. Migration and backward-compatibility risks

Do not edit the committed billing migration or rewrite original financial rows. Future additive migrations introduce new event tables, candidate keys and explicit ledger version, then replace guards in a reviewed migration that branches legacy versus new behavior. Preserve legacy constraints for legacy invoices; never disable guards globally. Backfill facts from existing Payments, but do not invent historical payer or line detail: `unknown` provenance and reviewed allocation/backfill events are required before opt-in. Aggregate legacy invoice lines without service facts remain unclaimable until reviewed.

Deploy new reads and capability handling before new writes; compare rebuilt/shadow projections with legacy totals, resolve pending checkouts and reconcile all legacy receipts before per-tenant opt-in. Migrate all financial readers: manual payment, provider settlement, links, dashboard, reports, exports, notifications/receipts and frontend balance sums. Gate version 2 writes until no unsupported consumer can display historical gross values as current debt. Rollback before opt-in can leave unused additive schema; after version 2 financial history exists, retain read/reconciliation capability and repair forward. Old binaries must not regain writers on new-mode invoices. No production migration is part of this ADR task.

## 7. Acceptance criteria and required automated tests

- Reproduce every scenario in 3.4 using exact minor units; assert G/W/A/B, payer partition, receipt conservation, current business state and collections separately; recognized revenue remains uncomputed.
- Regression fixtures for [BillingApiTest](../../tests/Feature/Api/BillingApiTest.php), [PaymentLinkApiTest](../../tests/Feature/Api/PaymentLinkApiTest.php), [HandyPayReconciliationTest](../../tests/Feature/Api/HandyPayReconciliationTest.php), [ReportingApiTest](../../tests/Feature/Api/ReportingApiTest.php) and [ApiResponseContractTest](../../tests/Feature/Api/ApiResponseContractTest.php). Legacy-mode request/response semantics and retry codes remain unchanged; new-mode old routes fail safely and do not leak unsupported rows or incomplete aggregates.
- Refund-only reopens current settlement; substitution leaves B0; pending refund never decreases net cash; credit on paid line releases cash to U; reversal cannot exceed original or erase evidence. Test partial refunds and unique compensation commands.
- Every new receipt is counted once regardless of number of invoice applications or advice documents; duplicate source identity with another command key cannot post again; per-currency aggregates may exceed one-invoice limits without overflow.
- Real PostgreSQL races described above, tenant/IDOR/direct-service/raw-SQL guards, immutable history, revoked user retry, injected audit failure and atomic rollback. Other supported engines require their own verification.
- Fresh and populated upgrade rehearsal, projection rebuild/as-of replay, provider uncertainty, old-binary write barrier and forward-repair rehearsal. These are future implementation criteria, **not tests executed for this documentation change**.

## 8. Explicit unresolved product/legal decisions

| ID | Approval needed before activation |
|---|---|
| FIN-01 | Accept explicit settlement-version opt-in and 409 legacy boundary, consumer migration and reporting terminology. If rejected, no advanced corrections until a compatible alternative is approved |
| FIN-02 | Accounting recognition/export policy, tax treatment, write-off categories, bad-debt versus contractual adjustment treatment; accountant approval. No recognition rule is inferred here |
| FIN-03 | Refund/return beneficiary and insurer contract restrictions, bank verification evidence, approval thresholds and separation of duties |
| FIN-04 | Supported production database engines; PostgreSQL race-test baseline and cutover/rollback operational owner |
| FIN-05 | Payer responsibility transfer consent, dispute collection policy and as-of accounting close/backdating rules; effective and recorded timestamps must always survive |
| FIN-06 | Legally approved retention of financial evidence and backup copies, in conjunction with EVD decisions in ADR-002 |

Approval of the ADR records an architecture decision only. No feature, schema, API, UI or deployment starts until architecture approval and the implementation ticket's own gates are satisfied.

## 9. Finance and architecture approval register

All entries are **Pending**. No financial policy in this ADR is approved. A recommendation is not an approval. The approval date must remain blank until the named authority records its decision and evidence. Settlement v2 financial posting remains blocked until every applicable finance, product/legal, security, and architecture/release decision below is approved and the implementation/release gates are met.

| Decision | Recommended policy | Alternatives considered | Required approver | Approval status | Date of approval |
|---|---|---|---|---|---|
| Cleared-cash evidence and meaning of verified | Independently sourced bank/deposit evidence or completed provider settlement to a practice-controlled account, with transaction identity, amount, currency, and value date. Advice and staff notes alone do not prove cash. | Treat insurer portal, remittance advice, or staff attestation as sufficient. | Finance controller / accountant; security owner for evidence handling | Pending |  |
| Finance roles and separation of duties | Separate receipt preparer, verifier, and allocator; no self-approval of obligation, transfer, or refund. Name exact permissions and any approved compensating control. | One operator performs all steps; implicit administrator override. | Finance controller / security owner | Pending |  |
| Insurer, patient, and guarantor liability | Require explicit authorized responsibility. Insurer obligation approval needs approved authority/evidence policy; patient amount is explicit; guarantor authority is separately established; remainder remains unresolved. | Infer patient remainder; treat claim approval as collectible; assume patient is payer. | Finance controller / product / legal | Pending |  |
| Responsibility corrections and transfers | Use linked immutable correction/transfer events with effective and recorded time, reason, evidence, and separate approval. Never silently transfer insurer responsibility to patient. | Edit prior assignment; automatic transfer on denial or underpayment. | Finance controller / product / legal | Pending |  |
| Partial allocations and overpayments | Permit explicit staff-directed partial allocation, bounded by verified unapplied cash, payer responsibility remaining, and line collectible balance. Keep excess unapplied to its original payer; no FIFO or cross-patient setoff. | Automatic FIFO; convert excess to patient credit or another invoice. | Finance controller | Pending |  |
| Refunds and reversals | Distinguish allocation release from cash refund; use compensating events; reserve pending refund amounts; refund only to authorized entitled source after completion evidence. | Negative Payment; edit/delete prior event; refund another party. | Finance controller / accountant; legal for insurer contract restrictions | Pending |  |
| Period closing and backdating | Preserve effective/value and recorded timestamps; no closed-period backdating; correct in open period. Reopening requires controller approval and reconciliation. | Unrestricted backdating; reopen without approval. | Finance controller / accountant | Pending |  |
| Currency | Require exact currency equality and current two-decimal minor-unit handling; no FX or cross-currency netting. Use separate receipts per currency. | FX conversion without approved rate, date, rounding, and gain/loss policy. | Finance controller / accountant | Pending |  |
| Historical payments, hosted checkout, and compatibility | Preserve legacy rows without inferred payer/line facts; reconcile before opt-in. Keep legacy semantics for legacy records; opted records require version-aware APIs/readers and a closed legacy boundary. Checkout must target explicit patient responsibility. | Infer historical splits; leave gross-balance checkout and old writers active on opted records. | Finance controller / product owner / architecture | Pending |  |
| Production database, concurrency, and rollback | Prefer PostgreSQL-only activation until equivalent guarantees are proven elsewhere. Require documented lock order, DB aggregate guards, race/retry/recovery tests, and a cutover/rollback owner. | Activate across engines based on application checks alone. | Architecture / release owner | Pending |  |
| Accounting recognition, write-offs, tax, and evidence retention | Keep revenue recognition and speculative tax/write-off treatment out of settlement until separately approved; retain evidence under approved legal/accounting policy. | Infer revenue/tax treatment from receipt or allocation; delete evidence without retention approval. | Accountant / legal / finance; security/privacy owner for retention | Pending |  |
