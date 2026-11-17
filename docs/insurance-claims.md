# Insurance claims foundation

M4-A supports manually prepared claims. It does not transmit claims, confirm insurer receipt, adjudicate benefits, record insurer cash, or change invoices or patient balances.

## Workflow

Create a draft under a patient with an explicitly selected insurance association. A complete policy and an estimate are optional. Add exact commercial line snapshots with a real procedure code only when known. Each write requires the current `expected_version`; a successful edit increments `lock_version`. Editing a `ready` claim returns it to `draft`. Readiness requires at least one positive-amount line in a single currency. `ready` means local validation only.

Record submission only after readiness succeeds. Supply an `Idempotency-Key` and a channel (`portal`, `email`, `paper`, `other`). Submission records the staff action, channel metadata, actor/time and immutable versioned snapshot. It does not call a portal or email service and does not mean insurer acknowledgement. Repeating the same key and metadata replays the same submission. A submitted claim is immutable; make a correction as a new linked claim.

## Permissions

- `insurance_claims.view`: retrieve claims, lines, submissions and lifecycle history.
- `insurance_claims.prepare`: create/edit drafts, manage lines, validate readiness, and cancel draft/ready claims.
- `insurance_claims.submit`: record a submission snapshot.

Each action also requires `patients.view`, active clinic membership, and patient-scoped authorization. These insurance permissions are not granted automatically to administrator roles or by wildcard permissions. Reads and writes are audited. The API never returns raw policy or membership identifiers.

## API

- `GET/POST /api/patients/{patient}/insurance-claims`
- `GET/PATCH /api/patients/{patient}/insurance-claims/{claim}`
- `POST /api/patients/{patient}/insurance-claims/{claim}/lines`
- `PATCH/DELETE /api/patients/{patient}/insurance-claims/{claim}/lines/{line}`
- `POST /api/patients/{patient}/insurance-claims/{claim}/readiness`
- `POST /api/patients/{patient}/insurance-claims/{claim}/cancel`
- `POST/GET /api/patients/{patient}/insurance-claims/{claim}/submissions`
- `GET /api/patients/{patient}/insurance-claims/{claim}/events`

All responses use the standard API envelope and private/no-store caching. PostgreSQL enforces tenant/patient source relationships, valid transitions, history append-only behavior, and snapshot consistency. Claim creation, edits, readiness, cancellation, or submission never alter invoices, payments, receivables, benefit usage, or cash reports.

## Deferred

Insurer acknowledgement, review/adjudication, additional-information requests, approvals, denials, appeals, evidence attachments/transmission, insurer APIs, remittance, settlement, and cash posting require later approved milestones.
