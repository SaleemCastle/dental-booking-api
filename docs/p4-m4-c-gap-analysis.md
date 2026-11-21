# P4-M4-C architecture gap analysis

## Reviewed foundation

- P4-M4-A and P4-M4-B are ancestors of the fetched `origin/main` (claims merged in PR #58; prior authorizations in PR #60).
- Claims have patient and tenant ownership, mutable draft lines, immutable submission snapshots, append-only lifecycle events, idempotent submissions, PostgreSQL tenant/source guards, and `insurance_claims.view/prepare/submit` permissions.
- Prior authorization decisions are staff-entered observations with provenance and non-settlement informational amounts. Evidence links are protected by `InsurancePriorAuthorizationEvidenceWorkflow` and document retention/scanning checks.
- Tenant and patient access is checked by tenant-scoped queries, membership, `patients.view`, and the patient policy. Audit operations use `AuditLogService`.
- ADR-001/002/003 were not present in this checkout. Existing claims OpenAPI/Postman artifacts were also absent.

## Gaps and decisions

- Claim statuses and PostgreSQL event guards only represented draft, ready, submitted, and cancelled. They needed explicit insurer decision states, longer columns, and transition validation.
- No response observation existed. This change stores a staff recorder, observed insurer date, recording time, channel, source/provenance, optional reference/notes, and exact submission reference. It treats amounts as informational and never mutates financial records.
- Denial is appealable; administrative rejection and partial approval are not appealable by default. Rejection remains a separate claim state. Appeal eligibility needs a future explicit payer/reason classification decision before broadening.
- Evidence references reuse the existing protected evidence association table and must target the exact patient claim submission. Evidence linkage must already have passed the enabled evidence workflow; this change does not transmit evidence.
- Corrections create a new claim and preserve the predecessor/submission. Correction reason is mandatory. One correction child per predecessor is enforced with a partial unique index; correction chains cannot point forward because only an already existing claim may be selected.
- Appeal submissions and responses are append-only snapshots. Insurer identifiers are recorded only when actually supplied; no identifiers are generated for appeals.

## Residual architectural decisions

- Confirm whether payer specific rules should later permit appeals for administrative rejections or partial approvals.
- Confirm retention and access expectations for insurer response source notes and appeal rationale.
- PostgreSQL trigger/FK enforcement has not been exercised in this isolated worktree because dependencies and a PostgreSQL test database are unavailable; see release-QA risk in the milestone report.
