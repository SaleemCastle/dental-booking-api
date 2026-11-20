# P4-M2-A: Manual insurance eligibility assessment gap analysis

Base: origin/main 116cf20b9fcff913248d826713aeb5d9baf76404, containing merged M1-A, M1-B and M1-C. Branch: p4/m2-a-manual-eligibility.

**Phase 1 conclusion: M2-A is already implemented by M1-B.** No additional production model, service, endpoint, migration, permission or financial behavior is needed for the requested scope. Recommend closing M2-A after the focused verification below. This task does not authorize M2-B.

## Existing implementation and requirements mapping

| M2-A requirement | Existing implementation / evidence | Functional gap |
|---|---|---|
| Insurance optional for ordinary clinic work | Insurance operations are confined to PatientInsuranceController and PatientInsuranceWorkflow; patient registration/update, appointment/check-in and treatment requests do not reference insurance | None |
| Provider only; incomplete or absent policies | PatientInsurerAssociation accepts a tenant insurer ID or an unverified name. recordCheck requires only its association; policy_revision_id and all policy details remain optional | None |
| Manual card terminals, portals, telephone, paper, other | recordCheck accepts external_card, external_portal, telephone, paper, other. No live insurer or card-reader client exists | None |
| unknown, active, inactive, unable_to_verify | Workflow allowlist and PostgreSQL outcome constraint implement all four | None |
| Specific service date distinct from check/record dates | Optional YYYY-MM-DD service_date is stored separately from offset-qualified checked_at (normalized to UTC) and server-recorded recorded_at. Resource returns all three | None |
| Provenance, reference, staff and timestamps | Required encrypted provenance, optional encrypted reference, authenticated actor_id, checked_at and database recorded_at | None |
| Immutable history; subsequent/correcting observations | POST appends a new PatientInsuranceCheck; PostgreSQL rejects UPDATE/DELETE/TRUNCATE. GET returns paginated history, not a derived current-eligibility field | None |
| Association/policy history pinned | Each check references the current immutable association revision and optionally an exact same-association policy revision through tenant-qualified FKs | None |
| No old report silently treated as current coverage | Every response retains the source and dates. No current-coverage projection, expiry assumption, verified-benefit state or guarantee is derived | None |
| Tenant, patient and exact permissions | TenantContext, tenant-scoped models, patient binding/view authority, exact patient_insurance.view/manage/reveal and direct workflow checks | None |
| Sensitive access and audit | Provenance/reference encrypted and hidden in ordinary responses; explicit reveal requires its own capability and audit. Writes/list reads are audited; insurance exception scopes redact bindings | None |
| M1-C retention and activation | Existing evidence helpers, purge fences, holds/quarantine, permissions and disabled upload/link gates are independent; no check can activate or release them | None |
| No payments, responsibility, claims or remittances | Workflow only writes insurance observations and audit records. No billing/settlement operation is invoked | None |

Source pointers:
- [Workflow](../app/Services/PatientInsuranceWorkflow.php): authorize, association, checks, recordCheck, revealCheck.
- [Model](../app/Models/PatientInsuranceCheck.php), [resource](../app/Http/Resources/PatientInsuranceCheckResource.php), [controller](../app/Http/Controllers/Api/PatientInsuranceController.php).
- [Existing migration](../database/migrations/2026_11_15_000001_create_patient_insurance.php): observation columns, composite foreign keys and immutable-history triggers.
- [M1-B contract](patient-insurance.openapi.json), [Postman collection](postman/patient-insurance.postman_collection.json), [developer guide](patient-insurance.md).
- [M1-C activation configuration](../config/insurance_evidence.php), document protection/workflows and [evidence guide](insurance-evidence.md).

## Date and correction semantics already supported

A date-scoped assessment supplies service_date explicitly. checked_at answers when the external check was performed; recorded_at answers when the clinic saved it. Service dates may be past or future and are never calculated from checked_at, policy dates or the latest observation. A later-created record can describe an earlier check. List ordering by record ID is history ordering, not a statement that its first row is current or applicable to every date.

M1-B clients may continue omitting service_date or providing null. That means unspecified scope, not today's service date, and must not be presented as verified coverage for another date. Requiring service_date globally would break the existing contract and is unnecessary to support a date-scoped check.

Corrections/subsequent checks are new observations with their own provenance and timestamps. Earlier rows and association/policy snapshots remain intact. No structured supersession or current-coverage resolver is required by this task, and none is invented. The ordinary response label staff_recorded_external is deliberately different from insurer-confirmed benefits or a cash receipt.

## Architecture review and milestone boundaries

Reviewed the preserved architecture drafts ADR-001 (financial facts versus estimates/receipts), ADR-002 (evidence access/retention and purge synchronization), and ADR-003 sections 3.2/3.3 (eligibility outcomes versus benefit confidence and policy lineage). These recommended drafts remain in the original audit worktree and are not copied or silently approved by this task.

ADR-003's broader proposals for rule versions, valid_until, usage/deductible observations, confidence and evidence manifests are not a reason to make complete policy details mandatory or duplicate the implemented check store. The current optional-insurance amendment and explicit M2-A scope govern this review.

Deferred, not missing M2-A functionality:
- **M2-B:** published benefit rules, coverage limits, exclusions, waiting periods, authorization requirements, verified/confirmed benefit semantics and any approved validity policy.
- **M3:** coverage calculations, estimates, coordination/priority evaluation and patient/insurer responsibility.
- **M4:** claims, submissions and claim-specific evidence/lineage.
- **M5:** remittances, actual receipts, payment allocations and settlement.
- Live insurer APIs, physical readers, automatic source authentication, typed eligibility-document links and external disclosures require separately approved scope. Existing M1-C activation restrictions remain in force.

## Minimal implementation decision

No production implementation change. Existing checks already cover M2-A. No migration or API contract change; therefore OpenAPI and Postman artifacts remain byte-for-byte unchanged.

Verification gaps are not new features: add only a focused regression test class exercising existing service-date behavior, all channels/outcomes, subsequent historical checks, optional partial policies, direct check/reveal authorization, patient/tenant isolation, encryption/audit, and M1-C/financial boundaries. Existing tests must not be deleted or weakened. Run only this new PostgreSQL test class; do not repeat previously passing M1-B/M1-C suites without a production change.

Exact changes:
- .gitignore: allow this local gap/verification document in Git.
- docs/manual-insurance-eligibility.md: this requirements mapping, scope decision and verification evidence.
- tests/Feature/Api/ManualInsuranceEligibilityTest.php: focused verification of already implemented behavior.

## Verification results

Executed on PostgreSQL 16 with PHP 8.2.35 / PHPUnit 10.5.58 in a disposable, isolated database, with migration recreation through RefreshDatabase:

```text
vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Api/ManualInsuranceEligibilityTest.php
vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Api/ManualInsuranceEligibilityTest.php --filter test_failed_observation_or_reveal_audit
```

- Initial run: 13 cases, 229 assertions, one test-fixture failure, zero errors/skips; 123.792 seconds. Twelve cases passed. The audit mock was installed after earlier HTTP fixture requests had cached the controller's original workflow dependency, so the simulated failure did not reach the workflow.
- Corrected the fixture by flushing cached route controllers after installing the audit mock. Reran only that failed case: 1 case, 10 assertions, zero failures/errors/skips; 142.995 seconds, including fresh migration recreation.
- Final unique coverage: **13 passing cases, 234 assertions** across those runs (224 assertions from the twelve passing cases plus 10 from the corrected case). Total PHPUnit wall time: **266.787 seconds**. This is not a claim that an unchanged thirteen-case suite was rerun successfully in one invocation.
- All five channels and four outcomes, provider-only and partial-policy checks, date handling/legacy omissions, immutable subsequent history, tenant/patient boundaries, direct-service authorization, encrypted private fields, audited disclosure/failure rollback, held/quarantined evidence and unchanged financial records are covered.
- PHP lint, targeted Pint and git diff --check pass. Production app/database/routes/config/dependency sources and existing OpenAPI/Postman artifacts compare unchanged against origin/main. No existing tests were changed or removed.
- Previously passing M1-B/M1-C suites were not repeated. No full backend suite, SQLite/MySQL matrix or frontend tests ran.

## Security, compatibility and operational review

No runtime or schema changes; no migrations, backfill or new permissions. Existing tenant-qualified foreign keys, exact permission checks, encryption, redaction and audit boundaries remain intact. Insurance remains optional in registration/update, booking, check-in and treatment code; the existing M1-B contract remains compatible, including observations with unspecified service date. Invoice/payment posting and allocation sources are unchanged. The focused test also verifies an existing invoice/payment is not changed by observations and rejects attempted benefit/payment fields.

M1-C's shared tenant/series advisory fence, committed purge states, lifecycle rechecks, finally-based lock release, storage-failure recovery and hold/link/delete ordering are unchanged. The focused boundary case verifies retained/quarantined documents remain protected and both insurance upload/link flags remain false; it does not repeat the previously passing independent-connection purge race suite. Physical insurance purge and external disclosure remain unavailable.

Existing activation gates still apply: PostgreSQL session-affine connections without incompatible transaction pooling; coordinated API/background-worker rollout; approved retention/hold policies; scanner integration and backup/recovery verification before activation. This task enables no evidence capability.

Remaining limits are intentional: staff report provenance does not authenticate an insurer or guarantee benefits/payment. A null service date has unspecified scope. Consumers must display service/check/record dates and must not interpret list order as current coverage. Encryption requires existing application-key protection and recovery procedures. Structured supersession, automatic validity/confidence decisions, live integrations and the financial/claims milestones above remain deferred. Architecture drafts are review inputs, not approval to activate broader features.

**Recommendation: SAFE TO MERGE this documentation/verification-only patch after PR review; close M2-A as already implemented by M1-B.** No functional M2-A blocker was found. Finalization includes committing and pushing the reviewed three-file patch and opening a PR targeting main. Merge, deployment and M2-B implementation remain outside this task.
