# P4-M5-B3-C Nonposting responsibility and allocation rules

## Scope and baseline

This milestone adds pure candidate-validation rules only. It adds no persistence, API, permission grant, audit event, payment allocation, cash posting, invoice mutation, or Settlement v2 activation. `settlement_v2_enabled` remains false by default.

The current `BillingWorkflow` and `PaymentSettlement` remain authoritative for invoice-bound payments and continue to update `Invoice.amount_paid` and status under existing rules. This change does not call or modify them. Responsibility candidates are accepted only for invoices whose status is `issued`, which avoids line-attribution assumptions for invoices with recorded payment.

## Validation introduced

- Candidate assignments require explicit payer kind and exact positive decimal amount. Partial amounts are allowed; candidate totals must not exceed the issued line charge. Unspecified remainder is left untouched and is not assigned to a patient or insurer.
- Tenant, patient, invoice, invoice-line, and currency identifiers must match the supplied line context.
- Patient candidates explicitly identify the invoice patient. Insurer candidates require a matching approved-obligation reference and may not exceed its approved amount. This verifies lineage and arithmetic only; it does not establish collectible insurer liability.
- The rule accepts context values; any future caller must load invoice, line, and obligation/approval records through tenant-scoped server-side queries. User-supplied IDs or an asserted approval flag are not authorization evidence.
- Duplicate payer assignments and multiple insurer assignments on one line are rejected. Secondary-insurer coordination remains excluded.
- The existing allocation amount rule remains unchanged. It checks caller-supplied available-cash and eligible-outstanding ceilings, but this milestone does not derive those values from responsibility records or make an allocation eligible for posting.

## Unresolved decisions and blockers

- ADR-004 is Proposed. It states that approved obligations require an approved finance authority/evidence policy before becoming collectible responsibility. That policy remains pending, so the new insurer check is only a candidate-shape validation and must not be used as approval or collection authority.
- ADR-004 references ADR-001 and says ADR-001 awaits architecture approval. ADR-001 was absent from the tracked `origin/main` tree at this note's baseline; B3-F restores a local ignored draft as a tracked-document proposal, still awaiting architecture approval. Confirm provenance and resolve the source-documentation discrepancy before financial implementation.
- Finance must decide authority and separation of duties for patient and insurer responsibility, evidence required for insurer liability, guarantor/patient identity rules, responsibility revisions, and how prior allocations/write-offs affect line remainder.
- Allocation eligibility, payer-specific cash caps, ordering, transfer, idempotency across competing commands, and concurrency locking remain unimplemented until those decisions are approved. PostgreSQL release QA is required before any future writer; this milestone adds no database guarantees.

## Compatibility

No database migration or model changes were made. No historical payment classification, invoice balance/status mutation, hosted-checkout change, refund/reversal behavior, or financial posting is introduced. Responsibility data passed to the rules is transient context only and is not stored.
