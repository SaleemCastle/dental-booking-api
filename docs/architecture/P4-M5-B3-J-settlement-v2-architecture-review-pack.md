# P4-M5-B3-J: Settlement v2 Architecture Review Pack

Status: **Proposed technical contract; not an approval to post or activate Settlement v2.** ADR-001 remains Recommended/awaiting architecture approval. ADR-004 remains Proposed and requires finance, product, and architecture approval. Accepted product direction is not formal policy approval.

Baseline reviewed: latest `main` verified through the connected GitHub repository source at `bab704281a9bf263f345b4a87483ab3c8348ac25` (PR #68 merge). The local isolated worktree is at that same commit. `git fetch` could not update the shared `.git/FETCH_HEAD` because the sandbox cannot write the shared Git metadata; direct GitHub network access is blocked. The connected read-only repository source confirmed the latest commit. No ACL changes were made. B3-H and B3-I were provided as prior design outputs in the task history; no tracked B3-H/B3-I architecture-pack files were found in the reviewed tree.

## 1. Recommended decisions

### 1.1 Payment identity and `invoice_id`

Keep `payments` as the one canonical cash-receipt ledger. Introduce an explicit immutable settlement contract version on each Payment:

- **v1:** `invoice_id IS NOT NULL`; preserve existing invoice-bound behavior, validation, amount limits, resources, and hosted checkout semantics.
- **v2:** `invoice_id IS NULL` for every v2 receipt, including receipts that are ultimately applied to only one invoice. Invoice and line association exists only on allocation events. Tenant and payer/source remain explicit; patient context is attached to each allocation because one receipt may cover invoices for multiple patients.
- Apply a database check equivalent to `(contract_version = 1 AND invoice_id IS NOT NULL) OR (contract_version = 2 AND invoice_id IS NULL)`. Keep tenant identity non-null. V1 invoice FKs remain tenant-qualified; v2 invoice/patient lineage is carried only by tenant-qualified allocations.
- Keep payer/source/verification provenance linked one-to-one to Payment. Do not duplicate amount or create a second authoritative receipt balance.
- The existing B3-B provenance schema requires `invoice_id`, and its allocation schema is invoice-bound. Evolve provenance to reference Payment without requiring an invoice; retain invoice and line references on actual allocation events.

**Alternatives:** A second receipt table duplicates cash authority. A synthetic holding invoice corrupts invoice and patient balances. One Payment per allocation duplicates a receipt and cannot represent unapplied cash. These are rejected.

**Compatibility consequence:** Nullable IDs are safe only with version-discriminated readers and writers. Legacy code must never load a v2 Payment as a v1 invoice Payment. Every legacy consumer listed in section 4 must filter/version-gate. Old resources must never serialize a null invoice ID. This is a breaking internal invariant change, even if legacy API behavior is preserved.

### 1.2 Historical carryforward and opening reconciliation

Do not create new Payments for historical payments and do not reclassify them. For a legacy invoice, calculate a cutover snapshot from existing rows:

```text
H_invoice = sum(existing legacy Payment amounts)
require H_invoice = Invoice.amount_paid
legacy_open_balance = Invoice.amount_due - H_invoice
```

Create an idempotent `legacy_carryforward` event for each source Payment, uniquely keyed by its existing Payment ID. It is a historical application pointer, not another receipt, new cash, or an allocation to an invoice line. Retain payer as `unknown` and line attribution as `unattributed`; do not make the amount available as v2 unapplied cash. It is counted once in invoice-level opening settlement and in receipt conservation through the separate carryforward term H; never create a duplicate Payment or cash receipt.

Opening balances preserve gross and paid facts only. Unpaid residuals remain `unresolved` until an explicit responsibility decision; never derive patient liability as gross less insurer expectation. Do not distribute H across invoice lines by ratio or chronology. If a partially paid invoice cannot be reconciled to trustworthy line-level opening attribution, keep that invoice in v1; block v2 line-level allocations and responsibility posting on it until an authorized, evidenced reconciliation exists. Do not invent historical bank-clearance evidence: label legacy origin as legacy-recorded and require evidence review before refunds or other cash-sensitive corrections.

Cutover must fail closed if payment sum differs from `amount_paid`, gross/line totals differ, a duplicate source mapping exists, currency is inconsistent, or checkout state is pending/unknown. The carryforward batch must be restartable and idempotent and produce a source-to-opening reconciliation report.

### 1.3 Allocation reversals and refunds

Store every application, reversal, refund reservation, release, completion, and recovery as immutable events. A reversal names the exact original allocation; cumulative reversals cannot exceed that application. A reversal releases cash back to the same receipt and payer; it is not an outbound refund.

A pending refund reservation:

1. Locks the relevant invoice(s), receipt(s), and allocation(s) in the canonical order.
2. Names exact source receipt(s), payer, currency, amount, and (if allocated) exact allocation(s). It cannot reserve another payer’s funds.
3. Checks the amount against available unapplied funds plus the specifically reserved allocated amounts, excluding funds already reserved by another pending refund.
4. Records a reservation event. It does not yet reduce invoice allocations, receipt cash, or the balance projection; it only reduces the amount available for new allocations, transfers, or refunds.

On successful verified disbursement, one database transaction rechecks the reservation and source balances, appends allocation reversals for the reserved allocated portion, appends the completed refund/outflow event, updates projections, records audit, and marks the reservation fulfilled. Any failure rolls the whole operation back. If disbursement is uncertain, keep the reservation and reconcile; do not issue another refund. If cancelled/failed with confirmed no cash outflow, append a reservation-release event. Refund recovery is a separate event which reduces net refunds and restores unapplied cash; it never silently recreates a previous allocation.

A responsibility correction/reversal is independent from cash movement. Do not automatically transfer a reopened amount to the patient. An allocation reversal without a refund increases same-payer unapplied cash. A pending refund does not change conservation totals. Carryforward H cannot be reserved for refund until the legacy source, entitled payer, and opening application are reconciled; completion releases the matching H and records F atomically.

### 1.4 Conservation contracts

Use exact integer minor units for supported currency exponents. For every invoice line `i`:

```text
G_i = immutable issued gross
C_i = credit applications - credit reversals
A_i = allocation applications - allocation reversals
B_i = G_i - C_i - A_i
G_i = C_i + A_i + B_i
0 <= C_i + A_i <= G_i
B_i = patient_i + insurer_i + other_i + unresolved_i
```

The payer amounts partition B; they do not overlap. Allocations must match the effective assigned payer. Any transfer is a separate authorized event. At invoice level, `G_invoice = C_invoice + H_invoice + A_invoice + B_invoice`. For invoices with historical unattributed carryforward, line balances are not declared complete until that carryforward is attributed through an approved reconciliation; invoice-level opening balance and line-level projected balances must not be summed as though they covered the same amount.

For each receipt `r`:

```text
R_r = valid receipt amount - invalid-entry corrections
H_r = legacy invoice-level carryforward applications - their reversals
A_r = v2 allocation applications - v2 allocation reversals
F_r = completed refunds/returns - cash recoveries
U_r = R_r - H_r - A_r - F_r
R_r = H_r + A_r + U_r + F_r
H_r, A_r, U_r, F_r >= 0
```

`H_r` is zero for new v2 receipts. It is an opening application tied to an existing v1 Payment; it is not a line allocation or spendable cash. Any carryforward reversal references the original H event.

Invalid-entry corrections are allowed only when no corresponding cash was received. Actual cash leaving the practice is a refund/return event, never a receipt deletion. Pending reservations do not enter R, A, F, or U. Available-to-allocate cash is `U_r - pending_unapplied_refund_reservations`; amounts already allocated and reserved for refund remain in A until refund completion, but cannot be allocated or reserved again.

### 1.5 Amount precision, bounds, currency and rounding

- Keep v1 behavior at its existing `numeric(10,2)`/`Money::MAX_MINOR` ceiling (`99,999,999.99`).
- For cross-invoice v2 receipts, recommend widening the shared Payment amount column to `numeric(18,2)`; largest row amount is `9,999,999,999,999,999.99`, or `999,999,999,999,999,999` minor units. This is below signed 64-bit `PHP_INT_MAX` (`9,223,372,036,854,775,807`) minor units. Preserve the v1 validation cap after widening. This deliberately extends the current single-invoice `Money` bound for v2 receipt rows, so ADR-004 must be amended or explicitly approve this separate receipt cap before schema work. Invoice/line/allocation bounds remain at the approved/current invoice limit unless separately approved.
- Enforce an aggregate ceiling per tenant and currency of `PHP_INT_MAX` minor units (`92,233,720,368,547,758.07`) unless calculations are moved to a proven exact-decimal implementation. PostgreSQL `SUM(numeric)` stays exact; application code must check the aggregate before converting to PHP integer. Never cast through float. If the product needs larger aggregates, approve a decimal/big-integer arithmetic contract before writers.
- Require currency to be in an explicit enabled currency/exponent registry. Interim v2 supports only configured two-decimal currencies; reject more than two fractional digits. Do not round, infer currency, apply FX, net across currencies, or use exchange rates. Match currency exactly across receipt, Payment, invoice, line, obligation, responsibility and allocation.
- Retain current v1 per-invoice maximum and add per-line/invoice/allocation ceilings. Any higher v2 invoice/line cap requires an explicit precision review of all dependent model casts, validation and reporting.

## 2. Database and API contracts

### Database

- Add `settlement_contract_version` with existing rows explicitly v1; make v2 enablement false by default and enforce the version/nullable-invoice check in PostgreSQL.
- Keep `payments` as receipt authority. Use tenant-qualified FKs for Payment, invoice, patient, insurer, user, claim/obligation and evidence where applicable. Enforce one-to-one provenance, currency equality, positive amounts, exact idempotency uniqueness, source-transaction uniqueness, and append-only triggers.
- Allocation and credit/reversal event rows reference their original event and lineage; unique tenant/idempotency key plus request hash; no mutable status column that erases history.
- Cross-row caps cannot be expressed as ordinary row `CHECK`s. Enforce them within transactions under canonical parent locks and add PostgreSQL trigger/constraint guards for tenant, ownership, currency, immutable lineage, and forbidden states. A tenant/currency aggregate guard row (or equivalent lock target) is required for exact aggregate caps.
- Versioned projection rows carry `tenant_id`, invoice ID, contract version, currency, revision/event watermark, and rebuild status. Projections are rebuildable and never accepted as the sole basis for a write without locked journal revalidation.

### API

- Preserve v1 routes and response envelope for v1 records. V1 Payment serialization requires non-null invoice ID; all old writers, callbacks and reads reject v2 records.
- Use explicit `/api/billing/v2` resources for receipt/provenance, invoice settlement projection, allocations, allocation reversals, credit events and refunds/reservations. Require exact finance permissions, tenant/patient scoped route binding, idempotency keys and pagination for histories.
- Return exact decimal strings and explicit currency. Include `contract_version`, projection revision/event watermark, unapplied/reserved totals, and unresolved responsibility; never expose an apparently complete legacy `balance` as the v2 collectible patient amount.
- Recommended legacy contract: a single-record request for a v2 invoice/payment returns the existing envelope with `409 SETTLEMENT_VERSION_REQUIRED`. A legacy collection or aggregate whose scope includes v2 data also returns that conflict; it must not silently omit rows. A caller may request an explicitly v1-only scope, which returns a completeness declaration limited to v1. Version-aware mixed reports use the v2 report contract. Never return stale totals.

## 3. External clients and checkout boundary

Before opt-in, inventory and version every external consumer of invoice/payment APIs, reports, dashboards, notifications, exports, and frontend billing. ADR-001 identifies the frontend billing service as summing legacy invoice balances; it cannot derive v2 responsibility. Mixed-v1/v2 reports require a version-aware report contract; otherwise fail closed.

Legacy checkout remains invoice-bound and based on gross unpaid balance. It must never be created for a v2 invoice. Resolve every active, completed-without-payment, pending, or uncertain link before cutover. Cutover and callbacks serialize on the invoice lock and immutable payment-link version. An unexpected late v1 completion after v2 opt-in is quarantined for reconciliation; it must neither be dropped nor posted through the v2 path. Callback and reconciliation handlers never route v2 settlement through the legacy PaymentSettlement writer.

Any future v2 checkout must bind a fixed amount to explicitly assigned patient responsibility and line target(s), check that target under lock before provider session creation, and settle Payment plus allocation atomically through v2 services. If the provider result is uncertain, reserve capacity and reconcile rather than retrying an external operation or enabling duplicate checkout.

## 4. Transaction, locking and idempotency

All v2 operations run on PostgreSQL in one transaction. Recommended order:

1. Tenant-scoped idempotency/command row.
2. All affected invoices, ascending ID.
3. Existing checkout links, ascending ID; then provider event/config rows in stable identity order, preserving legacy invoice → link ordering.
4. Responsibility and obligation parents, ascending stable key.
5. Tenant/currency aggregate guard, then receipts/Payments, ascending ID.
6. Invoice lines, original allocation/credit events, reservations/refunds, ascending ID/type.
7. Append events, audit/outbox and projection revisions; commit together.

All commands use this order, lock every affected parent before computing aggregate availability, and retry only database deadlocks/serialization failures with bounded retries. Do not hold these locks during external bank/provider calls. Unique constraints are the final defense against concurrent idempotency/source duplication. Same key and same request hash returns the original outcome; same key with a changed hash conflicts. Tenant isolation is enforced in query scope, authorization and composite DB relationships.

## 5. Migration and rollback boundary

1. Add schema and version checks while v2 remains disabled; no writer or existing behavior changes in this review pack.
2. Deploy version-aware readers, hard legacy barriers and observed metrics before any v2 writer.
3. Backfill v1 markers and idempotent carryforward pointers without changing Payment or invoice totals; produce reconciliation evidence and shadow projections.
4. Reconcile external clients, legacy checkout, balances, sources, currencies, and carryforward. Keep any unresolved invoice in v1.
5. After formal approvals, database concurrency/recovery QA and release gates, enable a limited cohort with v2 writers.

Before v2 events exist, rollback may remove unused additive structures. After events exist, rollback is forward-only: disable new commands but retain v2 readers, evidence and reconciliation. Never delete append-only history or drop populated structures. Do not narrow a widened amount column if v2 rows exceed v1 bounds. Restore requires tested backups and replayable audit/event history.

## 6. Approvals and blockers

**Architecture approval:** Payment version/nullability invariant; canonical event/projection model; carryforward semantics; exact aggregate and currency bounds; DB guard/locking strategy; legacy API conflict contract; rollback boundary.

**Finance/controller approval:** cleared-cash evidence; historic cash treatment; payer responsibility and transfers; reservation/refund authorization and separation of duties; credits/write-offs; period close/backdating; handling of overpayments, refunds and recovered refunds.

**Product approval:** external-client inventory and version migration; acceptance of the recommended fail-closed 409 behavior and explicit v1-only completeness response; frontend behavior and rollout timing.

**Security approval:** exact v2 permissions, tenant/patient isolation, audit and evidence retention/access.

**Release/architecture approval:** PostgreSQL-only support, independent-connection concurrency, deadlock/retry, rollback/restore and migration rehearsal requirements.

ADR-001 remains provenance-unverified and awaiting architecture approval; ADR-004 remains proposed. B3-H/B3-I recommendations and product direction do not satisfy either ADR's approval requirements. Financial posting remains blocked.

