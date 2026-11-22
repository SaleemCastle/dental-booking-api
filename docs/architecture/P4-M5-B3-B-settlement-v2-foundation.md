# P4-M5-B3-B Settlement v2 foundation

Status: inactive additive foundation. It does not approve or enable financial posting.

## Architecture review and gaps

- ADR-004 is Proposed and ADR-001 remains awaiting architecture approval. ADR-004 links to ADR-001, but the ADR-001 document is absent from this checkout; that is a documentation/source-of-truth gap to resolve before policy implementation.
- `BillingWorkflow` and `PaymentSettlement` use the existing `payments` ledger and synchronously update `invoices.amount_paid` and invoice status. `PaymentSettlement` only records provider-completed checkout. Both writers remain untouched.
- `PaymentLink` and hosted-checkout reconciliation continue to follow the existing invoice-level contract. This migration does not add or change their paths.
- Insurer receipt verification is an observation, not sufficient authority for posting. Approved insurer obligations are immutable nonposting history and do not create responsibility assignments.
- Finance has not approved cash evidence, payer attribution, responsibility transfer, allocation ordering, refund/reversal, period-close, or activation policy. This foundation stores no records and makes no liability inference.

## Structures

- `settlement_v2_payment_provenance` is an empty sidecar for future explicit payer/source provenance. It does not classify existing payments. Insurer receipt linkage remains provenance only.
- `settlement_v2_allocation_events` is an empty append-only shape for future invoice-line applications, with optional tenant-qualified approved-obligation lineage. It contains no reversal event or posting workflow.
- Both structures use exact `numeric(10,2)` amounts, uppercase three-letter currency checks where applicable, tenant-qualified references, UUID idempotency keys, request hashes, actor/timestamp fields, append-only triggers, and history indexes.
- `billing.settlement_v2_enabled` reads `SETTLEMENT_V2_ENABLED` and defaults to `false`. No production code consumes this flag and no activation route exists.

## Compatibility and deferred verification

No existing payment, invoice, invoice-line, payment-settlement, payment-link, status, balance, or hosted-checkout behavior is changed. No historical rows are backfilled or reclassified. No API endpoints or financial writer are introduced.

Before any writer is added, finance and architecture must approve ADR-001/004 decisions, including the distinct cash receipt/payment semantics, eligible responsibility source, allocation and overpayment rules, segregation of duties, and legacy API/cutover behavior. PostgreSQL release QA must verify migration/rollback behavior, composite foreign keys, immutability triggers, tenant actor membership, idempotency uniqueness, and concurrent over-allocation prevention. The last guarantee is not provided by this inert schema and requires an approved transactional locking design.
