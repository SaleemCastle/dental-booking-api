# ADR-004: Insurer Cash Application and Financial Compatibility

Status: **Proposed; requires finance, product, and architecture approval. Not implemented.**
Date: 2026-11-22. Scope: P4-M5-B3-A policy and backward-compatibility design.
Baseline: `origin/main` at `72e87e21b637277b71ade8d3090b3f6b323f3e15`, including [PR #64](https://github.com/SaleemCastle/dental-booking-api/pull/64).
Companion: [ADR-001: Insurance Billing and Financial Settlement](ADR-001-insurance-billing-and-financial-settlement.md).
This ADR refines ADR-001 for cash confirmation, responsibility, and migration. It does not approve posting or supersede any pending ADR-001 decision.

## Context

The existing billing ledger treats each `Payment` as a positive settled amount attached to one invoice. `Invoice.amount_due` is the issued gross amount, `amount_paid` is the sum of payments, and `balance` is gross less paid. Database guards prevent overpayment and payment/invoice rewrites. The terminal `paid` state cannot be reopened. [Billing contract](../billing.md)

`PaymentSettlement` posts completed hosted-checkout funds against one invoice. It validates provider outcome, amount, currency, and unique transaction identity. [Payment settlement](../../app/Services/PaymentSettlement.php)

M5-B insurer receipts are immutable staff observations. Their verification row proves that an authorized staff member recorded a matching amount and currency; it does not prove that cash cleared. M5-A remittance advice and claim decisions remain informational and cannot fund an allocation. M5-B2 obligation approvals are explicit staff finance records linked to a line, but still require approved authority and responsibility policy before they become collectible insurer receivables.

ADR-001 recommends one extended billing ledger, receipt/allocation events, explicit settlement-version compatibility, and immutable corrections. It remains **Recommended; awaiting architecture approval. Not implemented.** Its recommendations, including those repeated here, are not approved operating policy. The ADR-001 file was absent from the tracked `origin/main` tree at the B3-F baseline (`3bded85`); B3-F restores a local ignored draft as a tracked-document proposal. Its presence on this branch does not establish approval, and its source/provenance still requires owner confirmation.

## Proposed decisions

### 1. Cash receipts and invoice payments are separate facts

- A **cash receipt** records money received by the practice (with later returns recorded separately), its payer class, currency, value date, source identity, and verification evidence. Recording a claim, estimate, remittance advice, insurer promise, pending transfer, or un-cleared cheque does not create a cash receipt.
- An **allocation** applies part of one actual receipt to a specific invoice line. Reusing received cash for another invoice/line creates an allocation, not a second receipt.
- An invoice's gross amount and line snapshots remain immutable. For version 2 settlement, define `G` as gross charges, `W` as approved noncash credits, `A` as net cash allocations, and `B = G - W - A`. Require `W + A <= G` per line. Do not relabel `G`, cash received, payer responsibility, or revenue as interchangeable balances.
- Outstanding responsibility partitions only the remaining `B`: `B = insurer_responsibility + patient_responsibility + unresolved_responsibility`. Each component is an explicit approved decision. Unassigned remainder stays unresolved; no automatic patient transfer.
- For each receipt, `cash_received = net_allocations + unapplied_cash + completed_refunds`. Pending refunds reserve available funds but do not count as completed cash outflow. Unapplied cash remains owed to its entitled source; it is neither invoice payment nor patient credit by default.

### 2. Extend the canonical Payment ledger; do not create an insurer cash ledger

**Recommendation:** Keep one authoritative `payments` ledger for cash receipts. Extend it for version-2 receipt identity, payer class, insurer where applicable, source/currency/date/provenance, and nullable invoice association for receipts that may fund multiple invoices. Keep existing invoice-bound rows and legacy APIs intact. Do not create `insurer_payments`, a second receipt balance table, or a parallel insurer ledger.

Add immutable `payment_allocations` as applications of a Payment to invoice lines, plus linked compensating allocation-reversal events. These are not a second cash ledger: they distribute the one cash fact held in `payments`. A separate `billing_refunds` event records actual cash returned and references the original receipt. Do not add negative Payments or rewrite prior Payment rows.

One insurer receipt may produce one Payment and many allocations, or remain wholly unapplied. Enforce one posted Payment per source receipt. A remittance/advice reference is supporting provenance and grouping, never a second cash posting. `payment.amount` is received cash; `payment_allocations.amount` is applied cash. Invoice settlement is derived from net allocations, not from the total receipt amount.

**Rejected alternatives:** A separate insurer payment ledger duplicates cash authority and permits double settlement. One Payment row per invoice allocation makes a single receipt look like several receipts and obscures unapplied cash. Negative Payments and rewriting `amount_paid`/settled Payment rows conflict with current immutable positive-payment rules. Treating estimates, approved claim amounts, or reported remittance paid amounts as Payments falsely reports cash.

### 3. Responsibility and allocation rules

- An approved M5-B2 obligation may support an insurer responsibility assignment only when an authorized finance decision and its evidence meet the approved policy. Tie the assignment to that exact approved obligation, insurer, invoice, line, patient, and currency.
- Add immutable, versioned responsibility events for insurer, patient, and unresolved portions. Their sum must not exceed the line's remaining collectible `B`. Patient responsibility requires an explicit assignment; it is never calculated as the leftover amount by default.
- This release supports at most one insurer per invoice line, consistent with B2's one-approved-obligation-per-line constraint. Secondary-insurer coordination is excluded pending separate policy.
- Staff explicitly select invoice lines/approved obligations for an allocation. Do not apply FIFO, infer a match from claim/remittance line references, or net across patients. A remittance reference can help staff select a target but is not cash confirmation.
- Partial allocations are allowed. Cap each allocation by all of: unapplied cash on the source Payment, the assigned responsibility remaining for that payer, and the invoice line's remaining gross collectible balance. A patient allocation cannot satisfy insurer responsibility, nor an insurer allocation patient responsibility, without a separate authorized responsibility-transfer event.
- Overpayment remains unapplied to its original payer. Reject allocation above any cap. Never turn the excess into a patient credit, write-off, benefit consumption, or another invoice payment automatically.

### 4. Currency

Require exact currency equality across receipt, Payment, allocation, invoice, line responsibility, and obligation. Money remains decimal strings using the current two-decimal minor-unit arithmetic and must respect the current single-invoice bound; aggregate columns need sufficient precision for multiple invoices. No FX conversion, cross-currency netting, exchange gain/loss, or implicit currency default is included. Multi-currency receipts require separate receipt/Payment records in each currency.

### 5. Cash-confirmation evidence and finance authority

**Proposed evidence threshold:** A receipt is postable only after evidence indicates settled funds reached a practice-controlled bank/cash account. Accept an independently sourced bank statement/deposit record, or a payment-provider settlement record that confirms completed settlement to that account, with transaction identity, amount, currency, and value date. A remittance advice, claim response, insurer portal processed/paid status, transfer instruction, uncleared cheque, or staff-entered note alone is insufficient. Insurer confirmation alone is insufficient unless it proves completed settlement under an approved evidence standard.

Attach a protected immutable evidence reference or approved source record; provenance text alone is not the evidence. Store no credentials or unnecessary full bank-account data. If the source is ambiguous, retain the receipt as unconfirmed and do not create a Payment.

**Proposed segregation of duties:**

1. A finance preparer records the receipt and source evidence.
2. A different finance verifier confirms cleared-cash evidence, amount, currency, and source uniqueness. The preparer cannot verify their own receipt.
3. A separately authorized finance operator allocates the confirmed receipt within system-enforced caps. Responsibility transfers and refunds require distinct approval; the requester cannot approve their own transfer/refund.
4. Any small-clinic compensating control where role separation is impossible requires written finance/controller approval and an auditable second review. It is not a code wildcard or implicit administrator grant.

Use exact proposed permissions such as `finance.receipts.record`, `finance.receipts.confirm`, `finance.allocations.post`, `finance.responsibility.assign`, and `finance.refunds.approve`, in addition to required invoice/patient reads. Finance must approve the final permission names, role grants, thresholds, and any compensating control before implementation.

### 6. Historical payments and hosted checkout

- Preserve legacy invoice fields, Payment rows, routes, resources, idempotency behavior, and legacy state meanings. Do not rewrite historical payments or infer their payer or invoice-line split.
- For a version-2 opt-in, preserve an existing settled Payment as one historical cash receipt and, if needed for the projection, an invoice-level legacy allocation with payer and line marked `unknown`. Do not duplicate it into another cash row. Require manual reconciliation of open invoices and responsibility before enabling new payer-specific collections; unknown historical responsibility remains unresolved.
- Reconcile pending/uncertain payment links before cutover. The current checkout amount is based on gross invoice balance and has no payer assignment. Version-2 checkout creation must specify the patient responsibility target and cannot offer the gross insurer/unresolved balance as a patient checkout amount. Hosted payment settlement and manual payments must both post through the canonical ledger and common idempotency/overpayment rules.
- Use explicit version-2 API/resources (ADR-001 proposes `/api/billing/v2/...`). Legacy resources must not expose a nullable-invoice Payment or present a stale legacy balance as current liability. For opted-in invoices, legacy reads/writes and aggregates fail closed with an approved version-required conflict until compatible clients are deployed. No silent field-semantic change.
- Backfill invoice gross, paid totals, source references, and currency from recorded source data only. Do not infer a patient's amount from gross minus insurer obligation. No opt-in until historic balances, receipt evidence, outstanding checkouts, and client/readers reconcile.

### 7. Refunds, reversals, reopening, and period close

- An **allocation reversal** releases an application back to unapplied cash; it is not a refund. A **refund** is an actual cash outflow to the entitled original payer. Record both as immutable compensating events with source IDs, amounts, currency, reason, evidence, actor, approval, and idempotency identity. Never delete or edit a Payment/allocation.
- For an applied refund, release the affected allocation and record the completed refund atomically after external confirmation. For an unapplied refund, reduce unapplied cash only when the outbound payment is confirmed. Pending or uncertain refund instructions reserve funds and block reallocation; they do not count as completed refunds. Do not hold a DB transaction open during a bank/provider call.
- Refunds, allocation reversals, and approved responsibility corrections update the version-2 settlement projection and may reopen its current state. Preserve the original gross invoice and historical legacy `paid` state/resource. A reopened balance returns to the original payer unless an explicit approved transfer changes responsibility. No automatic patient substitution.
- Do not allow voiding an invoice after cash, allocation, claim, or financial adjustment exists. Correct billing errors with linked approved adjustments, not a gross-amount rewrite.
- Preserve both effective/value date and recorded timestamp. Do not backdate into a closed period. Post corrections in the current open period with a link to the original event; reopening a closed period requires documented controller approval, audit, and reconciliation. Finance must approve close cutoff, backdating, refund, adjustment, and reopening rules before activation.

## Database, API, migration, and rollback strategy

1. **Additive model:** Add an invoice settlement-version/cutover marker; extend Payment for v2 source/payer fields; add tenant-qualified immutable allocation, allocation-reversal, responsibility, and refund events; add rebuildable versioned settlement projections. Preserve existing rows and gross invoice columns. Do not edit the original billing migrations.
2. **Enforce invariants:** Add composite tenant/patient/invoice/line/source foreign keys, exact currency checks, unique source-cash identity and command idempotency, immutable event guards, and parent-row trigger/constraint enforcement for cumulative receipt, responsibility, and gross caps. A normal `CHECK` cannot safely enforce a cross-row `SUM` without serialization.
3. **Compatibility:** Keep the old billing contract in legacy mode. Add separately versioned settlement, payment, allocation, responsibility, refund, and history resources. Update all payment writers/readers—manual payments, `PaymentSettlement`, payment links/webhooks, invoices, reports, dashboard, notifications, exports, and frontend consumers—before version-2 activation.
4. **Backfill and shadow:** Preserve each existing Payment as its original cash record. Build invoice-level unknown-payer allocations/projections from those rows without inventing patient/insurer or line facts. Compare version-2 gross/paid/balance projections against legacy totals. Reconcile open invoices and hosted checkouts manually; block cutover where responsibility is unresolved or totals differ.
5. **Opt-in:** Enable version 2 only after finance approval, supported clients, reconciled records, permission rollout, and an operational cutover owner. Legacy API behavior remains available only for non-opted records; opted records use version-2 APIs and an explicit legacy conflict.
6. **Rollback:** Before any version-2 financial event exists, additive schema can be disabled or removed only if no dependent data was written. Once version-2 receipts, allocations, responsibilities, or refunds exist, do not roll them back or run old writers against them; retain read/reconciliation support and repair forward. Do not delete history to make a down migration succeed.

## Transaction and concurrency recommendation

Each allocation command operates on a specified invoice line and source Payment. For a batch, acquire all invoice locks in ascending ID order. Then lock the source Payment/receipt, obligation/responsibility rows, and target line/allocation rows in a documented global order. Verify authorization and tenant membership on every command/retry. Compute all caps from committed rows under those locks using integer minor units.

Use a unique tenant/operation/idempotency key bound to actor, canonical payload hash, and result; a repeated key/hash returns the original result, while a changed payload conflicts. Independently enforce unique posted source cash identity so a second command key cannot repost one bank transaction. Insert the event, update projections/state, and append audit in one transaction. Audit failure rolls back the posting. PostgreSQL parent locks and database guards—not application-only checks—must serialize allocations across invoices and prevent the same receipt or obligation being spent twice.

The existing payment contract supports SQLite/MySQL/PostgreSQL while the insurer B2 migrations are PostgreSQL-specific. The supported production database for version-2 posting is not approved. Until each supported engine has equivalent constraint and concurrency guarantees, recommend **PostgreSQL-only activation for version 2** while legacy billing remains unchanged. Require independent-connection PostgreSQL race and migration rehearsal before release.

## Alternatives rejected

| Alternative | Reason |
|---|---|
| Separate insurer payment/cash ledger | Duplicate authoritative cash, reconciliation drift, and double-post risk. |
| Treat a remittance or approved claim as cash | Advice/adjudication is not cleared money and would overstate collections. |
| Use the full gross balance for patient collections | Can collect an insurer/unresolved share from the patient without an approved transfer. |
| Automatically assign line remainder to patient or apply FIFO | Invents liability/order and can transfer insurer risk without consent or policy. |
| Convert currencies during allocation | No approved FX source, date, spread, rounding, or gain/loss treatment. |
| Rewrite old Payments, gross invoices, or legacy `paid` status | Destroys historic facts and breaks current clients/reconciliation. |
| Use negative Payments for refunds | Conflicts with positive immutable Payment constraints and obscures cash-in versus cash-out. |
| Enable version 2 on an unreconciled legacy invoice | Unknown payer/line allocations can cause double collection or inaccurate patient responsibility. |

## Decisions requiring explicit approval

All rows are **pending**. Approval must be recorded by the named financial/product authority; this ADR is a proposal, not evidence of approval.

| Decision | Recommended default | Required approver |
|---|---|---|
| Cleared-cash evidence standard and meaning of `verified` | Independent bank/deposit or completed provider settlement evidence; advice/claim/portal status alone cannot post. | Finance controller / accountant |
| Finance roles and separation of duties | Different receipt preparer and verifier; no self-approval for obligation, responsibility transfer, or refund. | Finance controller / security owner |
| Payer partition and patient transfers | Explicit patient and insurer amounts; unresolved remainder stays unresolved; no automatic transfer. | Finance controller / product/legal |
| Allocation order / underpayment | Explicit staff-directed targets only; partial allocations allowed; no automatic FIFO or cross-patient setoff. | Finance controller |
| Excess and unapplied cash | Remains with original payer; refund or reapplication requires an explicit authorized event. | Finance controller |
| Currency and monetary precision | Same currency only; no FX; preserve current two-decimal input bounds. | Finance controller |
| Historical payment and checkout classification | Preserve payer/line as unknown absent evidence; manual reconciliation before opt-in. | Finance controller / product owner |
| API compatibility boundary | Explicit settlement v2; legacy endpoints conflict for opted records; reconcile clients before opt-in. | Product owner / architecture |
| Refund, reversal, and period close | Separate release versus cash refund; append-only compensation; no closed-period backdating; controller approval to reopen. | Finance controller / accountant |
| Production database engine | PostgreSQL-only v2 activation until equivalent concurrency/constraint verification exists elsewhere. | Architecture / release owner |
| Revenue, write-off, tax, retention | No revenue recognition or speculative tax/write-off rules in B3; retain existing pending ADR-001/002 approvals. | Accountant / legal / finance |

These decisions map to, and do not satisfy, ADR-001 FIN-01 through FIN-06. Before implementation, update ADR-001's status only after the required authority records approvals and reconcile any contradictory decision. P4-M5-B3-A itself authorizes no application change, migration, test, commit, push, rollout, or cash posting.

## Safe implementation sequence after approval

1. Record finance and product approvals above and settle ADR-001 conflicts.
2. Specify version-2 contracts, source identity, exact role grants, evidence policy, and ledger invariants.
3. Implement additive schema and database guards; prove migration on a restored PostgreSQL copy without enabling writes.
4. Backfill/shadow-project historical payments as unattributed; reconcile balances, checkouts, and consumers; block unresolved invoices from opt-in.
5. Deploy version-aware readers and old-route barriers; verify supported clients before enabling commands.
6. Enable controlled receipt confirmation, responsibility decisions, allocations, and refunds only behind explicit permissions and operational approvals. Run independent-connection PostgreSQL concurrency and recovery QA before production activation.

Financial posting remains disabled pending explicit finance and architecture approval and completion of release gates. Until then, insurer receipts and approved obligations remain nonposting records, and the existing invoice/payment behavior remains authoritative.
