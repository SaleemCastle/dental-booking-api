# P4-M4-B Prior Authorizations and Supporting Evidence

## Reused capabilities and review notes

- ADR-001 defines authorizations and claims as informational workflow facts; they do not post cash, allocations, receivables, payments, or invoice changes. ADR-001 is marked recommended and awaiting architecture approval.
- ADR-002 requires tenant/patient ownership, immutable document versions, clean scan provenance, audited access, retention holds, and purge barriers. The implementation reuses `DocumentProtection`, `DocumentStorage`, `PatientDocumentPolicy`, `document_versions`, and the existing `evidence_retained` fence. Its linking activation remains false.
- ADR-003 separates clinic request preparation, insurer contact/acknowledgment, decision, claim submission, adjudication, and settlement. No insurer integration or direct verification is implied.
- M1-B provides tenant/patient insurance associations and optional policy revisions. Provider-only associations remain usable without inventing policy details.
- M1-C provides protected patient document series and immutable versions, scan/quarantine state, retention holds, audit, and guarded deletion. New evidence rows point to immutable version IDs and are included in the same retention fence.
- M2 provides manual eligibility/benefit observations; M3 provides immutable informational coverage estimates. Neither is consumed or changed here.
- M4-A provides tenant/patient claims, append-only claim events, claim submissions, explicit permissions, exact decimal money, and no-ledger mutation guarantees.
- Existing tenant context, composite foreign keys, `Gate`, `AuditLogService`, Sanctum routes, `ApiResponse`, and Postman generation conventions are reused.

The ADR-001/002/003 source files were present in the existing M4-A checkout but are not included in the fetched `origin/main` tree. Their contents were reviewed from that checkout; this branch documents the relevant decisions above.

## Authorization record

`insurance_prior_authorizations` is tenant- and patient-bound. It requires an explicitly selected patient insurance association and exact requested amount/currency, but no policy. Policy revision, claim, treatment-plan item, and estimate references are optional and validated against the same tenant and patient. A UUID request identifier is clinic generated. A separate caller-supplied idempotency key is tenant scoped and bound to a request hash. Insurer references are encrypted at rest and omitted from responses.

Insurer decisions are separate immutable rows tied to immutable lifecycle events. Amount/currency are nullable together; absence means unknown. Scope, explicit validity dates, communication channel, decision time, recorder, and source provenance are retained. Corrections append a new decision that references the latest decision; prior rows are never edited. The API marks returned decisions as `staff_entered` and does not expose the encrypted insurer reference.

## State-transition table

| From | To | Command | Meaning and guard |
|---|---|---|---|
| none | draft | create | Clinic creates a local request; required patient association and explicit requested money; idempotent key |
| draft | draft | update | Clinic changes draft under expected version; append event |
| draft | requested | record request | Staff records channel/time/provenance that the clinic sent it; does not prove insurer receipt |
| requested | pending | acknowledge / request more information | Staff records an insurer communication as manually observed |
| requested or pending | approved / partially_approved / denied | record decision | Authorized staff enters decision, provenance, optional reference/amount/scope/dates |
| approved / partially_approved / denied | approved / partially_approved / denied | correct decision | Append explicit correction linked to latest decision; retain the prior decision |
| draft / requested / pending | cancelled | cancel | Explicit staff cancellation with reason |
| approved / partially_approved | expired | expire | Staff records expiry only when an explicit insurer `valid_through` is before today |

`approved`, `partially_approved`, and `denied` are terminal except for explicit decision correction; approved states may also expire after their supplied validity date. There is no default validity period. Submission is a clinic-recorded action; only a separately recorded staff observation moves it to pending.

## Evidence linkage and security boundaries

Typed immutable association rows support authorization, decision, claim, and claim-submission targets. They reference `document_versions`, not mutable document IDs. PostgreSQL verifies version scan state, patient ownership, protection state, target lineage, tenant membership, and claim patient. A row is never unlinked; its existence participates in `evidence_retained`, preventing deletion/purge. Link creation additionally uses the existing series advisory fence, storage document lock, Gate policy, purge checks, clean-scan requirement, and audited command key. Quarantined, purging, or non-clean versions cannot be linked or read.

Evidence operations require explicit `insurance_prior_authorizations.evidence` and `insurance_documents.view`; linking additionally requires authorization management. Ordinary authorization operations require exact `insurance_prior_authorizations.view`, `.manage`, or `.decide`, plus patient view and active membership. No wildcard or admin shortcut is used. Evidence listing checks document visibility again and audits each version reference. It never returns file bytes; the existing insurance-document download route remains the only content path and remains governed by its own policy.

The existing `insurance_evidence.linking_enabled` setting is false and is not changed. Upload, disclosure, and physical purge activation paths are not enabled.

## Financial boundary and deferred work

Authorization amounts and decisions are operational facts only. No invoice, invoice line, payment, balance, benefit usage, remittance, receivable, settlement, claim status, claim submission, or estimate is mutated by authorization workflows. No adjudication, insurer API, insurer transmission, patient responsibility transfer, or cash entry is included. Payer-specific procedure code mapping, insurer forms, legal retention schedule, evidence disclosure consent, and insurer reference reveal workflow remain deferred.
