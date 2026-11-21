# Manual insurer remittance advice

P4-M5-A records insurer remittance advice as staff-entered observations. The
recording process does not call an insurer, validate the source with a payer,
or represent cash received.

## Recorded history

Each advice belongs to the active clinic and an insurer in that clinic. It
stores the insurer-provided external reference, advice date, server recording
timestamp, staff recorder, communication channel, source/provenance notes,
currency, reported totals, and immutable line items. Each line is associated
with a patient and that patient's insurer association; optional claim,
immutable submission, and already-authorized supporting evidence references
must match that patient and insurer context.

Reported billed, allowed, paid, denied, and adjusted amounts use exact decimal
strings. Unknown values remain `null`; reported values are not recalculated or
required to sum to totals. Adjusted amounts may be signed because this field
records the insurer's reported value only. No conversion, reconciliation, or
accounting adjustment is performed.

Advice and line items are append-only. A correction creates a new advice record
linked to its predecessor with a required reason; the original remains
unchanged. One correction is allowed per advice record. Duplicate original
insurer references and conflicting idempotency keys are rejected. UUID
`Idempotency-Key` values make retry behavior explicit.

## Permissions and tenant boundaries

- `insurance_remittances.view` plus `patients.view` and active clinic membership
  are required to list or retrieve advice and lines.
- `insurance_remittances.record` plus `patients.view` and active clinic
  membership are required to record advice.
- `insurance_remittances.correct` plus `patients.view` and active clinic
  membership are required to record a correction.
- Linking supporting evidence also requires `insurance_documents.view`, patient
  document authorization, and the existing evidence retention/readability
  protections.

Permissions are explicit and are not granted to administrator roles by this
change. Tenant-qualified database constraints and the tenant model scope
prevent cross-clinic references. Patient, association, claim, submission, and
evidence ownership is checked before recording and by database constraints or
triggers.

Reads and writes create append-only audit events. Responses use the standard API
envelope with private/no-store caching. Internal idempotency keys and hashes are
not serialized.

## API

- `GET /api/insurance/remittances` — paginated clinic history, optional insurer
  and advice-date filters.
- `POST /api/insurance/remittances` — record advice and lines.
- `GET /api/insurance/remittances/{advice}` — retrieve advice metadata and
  reported totals.
- `GET /api/insurance/remittances/{advice}/lines` — paginated line items.
- `POST /api/insurance/remittances/{advice}/corrections` — record a linked
  correction.

See [the OpenAPI contract](insurance-remittances.openapi.json) and the
`Insurance Remittances` folder in the generated Postman collection. Requests
that create records require `Idempotency-Key` to be a UUID.

## Financial boundary

No endpoint creates a payment, changes an invoice or invoice line, changes a
patient balance, creates a receivable, consumes benefit limits, records a
remittance as cash, posts an adjustment, or recognizes revenue. Reported paid
amounts are observations only. Settlement and payment allocation remain outside
P4-M5-A.

## Architecture decisions and remaining release-QA

The ADR-001/002/003 source files are not present in this fetched `origin/main`
checkout. Existing M4 architecture notes describe the applicable boundaries:
remittance observations remain separate from posted payments and receivables,
evidence retains purpose and access controls, and insurer contact remains
manual. This milestone does not treat those draft ADRs as new legal or
accounting approvals.

The missing source ADRs leave formal financial/evidence approval unverifiable.
The repository also contains no M5 decision for correction chains or for
external-reference reuse on corrections. This implementation records each
correction as a new linked immutable advice, allows at most one child correction
per advice, and applies duplicate-reference detection to original advice within
the same clinic and insurer. Advice and line totals remain observations; no
arithmetic reconciliation, currency conversion, or settlement rule is assumed.

The database migration is PostgreSQL-specific and was reviewed statically.
Release QA should verify fresh migration deployment, tenant/patient foreign
keys and triggers, immutability under update/delete/truncate, uniqueness under
concurrent duplicate requests, correction concurrency, and audit rollback
atomicity using the project's approved PostgreSQL environment. No PostgreSQL
integration suite is run as part of this focused milestone validation.

## Focused validation record

- `InsuranceRemittanceDomainTest`: 8 tests, 42 assertions passed. Covers exact
  decimal handling (including negative zero and unknown values), normalized
  duplicate references, correction eligibility, evidence/submission lineage,
  and explicit permission decisions.
- `PostmanExportTest`: 12 tests, 653 assertions passed. Includes all five
  remittance operations, UUID idempotency example, informational boundaries,
  and OpenAPI-to-Postman collection consistency.
- `php tools/postman.php --check`: passed; generated Postman artifacts match
  `docs/openapi.yaml`.
- Pint passed on 15 changed PHP files; PHP lint passed on all 15.
- Line coverage could not be measured: the available PHP 8.2 runtime has no
  Xdebug or PCOV driver. `phpunit.remittance-coverage.xml` scopes the intended
  domain measurement to `InsuranceRemittanceRules` and
  `InsuranceRemittanceAccessPolicy`; the coverage run executed all 8 tests but
  exited 1 with PHPUnit's “No code coverage driver available” warning.
- No PostgreSQL integration, full regression, or unrelated test suites were
  run. Tenant-key enforcement, immutable triggers, and concurrent uniqueness
  remain release-QA verification items.
