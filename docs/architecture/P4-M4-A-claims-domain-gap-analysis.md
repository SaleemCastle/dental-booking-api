# P4-M4-A Insurance Claims Domain Gap Analysis

## Existing capabilities

- M1-A/M1-B provide tenant-owned insurer catalog entries, patient insurance associations (including provider-only associations), optional policy records, and immutable policy revisions with encrypted identifiers.
- M1-C provides protected insurance evidence/document workflows. This milestone does not add a claims evidence attachment path; documents must continue using those workflows and their purpose/retention controls.
- M2-A/M2-B provide manual eligibility observations and immutable benefit-rule revisions.
- M3-A/M3-B provide immutable estimates, optional treatment-plan item linkage, stale/superseded indicators, and informational billing previews.
- ADR-001 keeps estimates and claims outside invoices, payments, receivables, balances, and cash collection. ADR-002 governs sensitive evidence access and retention. ADR-003 separates claim preparation/submission from acknowledgement, adjudication, appeals, remittance, and cash.
- Existing tenant context, composite ownership constraints, `AuditLogService`, explicit permission seeding, Sanctum routes, `ApiResponse`, PostgreSQL triggers, and integer-minor-unit billing helpers are reusable.

## Gaps and minimum safe design

No claims, claim-line, submission, or lifecycle-event records or routes exist. Add tenant-bound claims with a required patient insurance association, optional policy revision and estimate, a generated local claim identifier (not an insurer reference), and optimistic `lock_version`. Add mutable draft lines with integer quantities and exact `numeric(14,2)` unit amounts; PostgreSQL derives gross amounts. Add immutable submission snapshots with sequence and idempotency key, and append-only lifecycle events. PostgreSQL foreign keys/triggers enforce tenant/patient lineage, actor membership, allowed transitions, submission lineage, and immutability after submission. Service transactions lock the claim and require expected versions.

Claim readiness requires a selected association, at least one valid line, and internally consistent optional policy/estimate references. No policy is required. A ready claim can return to draft through an authorized edit; every edit increments the version and records that transition. Only ready claims can be submitted. Submitted claims cannot be edited or returned to draft. Corrections require a new claim linked to its predecessor, retaining the original submission.

Submission channel is a staff-entered classification and metadata only. It is not proof of transmission or insurer receipt. No external reference is fabricated. Submission snapshots retain claim header and commercial lines, and identify actor/time/version. Sensitive insurance identifiers are not copied into claim or submission responses.

## Lifecycle table

| From | To | Command | Conditions |
|---|---|---|---|
| none | draft | create | Patient and explicitly selected association belong to current tenant and patient |
| draft | ready | validate readiness | Required association and at least one valid line |
| ready | ready | validate readiness | Revalidation succeeds; no duplicate event required |
| ready | draft | edit header/lines | Expected version matches; edit is transactional; version increments |
| draft | cancelled | cancel | Explicit reason and expected version |
| ready | cancelled | cancel | Explicit reason and expected version |
| ready | submitted | submit | Readiness rechecked under row lock; idempotency key unique; immutable snapshot inserted |

`submitted` is terminal in M4-A. `acknowledged`, `under_review`, `additional_information_requested`, `approved`, `partially_approved`, `denied`, `appealed`, withdrawal, remittance, and settlement are deferred. `ready` means locally validated only.

## Security and financial boundary

Every API and workflow entry point requires explicit `insurance_claims.view`, `.prepare`, or `.submit` as appropriate plus patient scope and active tenant membership. Wildcard/admin grants are not accepted as claims authority. Raw writes are constrained by tenant-qualified keys and PostgreSQL triggers. Read operations audit disclosure; create/edit/submit/cancel audit IDs and actors only. Neither claim status nor a submission creates invoice or payment changes, receivables, benefit usage, remittances, or revenue.

## Deferred

Evidence pinning and insurer transmission protocols need approved ADR-002 purpose/retention, consent, channel, payload, and legal requirements. Acknowledgement/adjudication/appeals and payer-specific transitions belong to later M4 work. Remittance, settlement, cash receipt, allocations, and revenue remain behind ADR-001/P4-M5 approval. No frontend changes or insurer integration are included.
