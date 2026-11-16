# P4-M1-B: Optional patient insurance information

Insurance is optional for patient registration, patient updates, appointment booking, check-in and ordinary treatment. An uninsured patient needs no insurance record. Validation below applies only to the new insurance-specific endpoints; existing demographic, appointment, treatment, invoice and payment contracts do not change.

Three supported levels are independent conveniences, not mandatory onboarding steps:

1. **Provider only:** zero, one or multiple patient-insurer associations. Reference a tenant insurer with `insurer_id`, or supply an `unverified_insurer_name` such as Canopy or Sagicor. Exactly one provider representation is needed when creating an association. Free text never creates or verifies a catalog insurer. No policy, member ID, subscriber, plan or published plan version is required. Coverage priority stays null unless explicitly recorded; list order never implies primary/secondary coverage.
2. **Card present / external manual check:** staff may present/swipe a card in an external insurer system, then explicitly record its reported outcome. M1-B records this manual observation; it does not operate a reader, call an insurer API, authenticate a source or promise benefits. Record provenance, channel, staff identity, checked/recorded timestamps and an optional external reference. No policy is required. Provider information and a staff-recorded eligibility observation remain separate facts. Neither an active observation nor a reference is received payment.
3. **Optional full policy:** a separate policy identity and append-only revisions allow progressively recording policy number, membership ID, subscriber name, self/spouse/child/other relationship, tenant plan and effective/expiry dates. Every field can remain unknown/null; at least one policy field must be supplied to start a policy. No fake number or placeholder subscriber is generated. Explicit null clears a field in a new revision; omitted fields retain the preceding value. A selected plan must belong to the association's referenced insurer; no published plan version is required.

Offer tenant catalog selection first where available; free text remains unverified until staff explicitly match a catalog insurer. Association `active` is administrative record state, never an eligibility outcome. A subscriber need not be a clinic patient, and an insurance relationship grants no guardian, portal or other-patient access. Policy dates and details are recorded information, not automatically verified eligibility.

## Local API contract

The [OpenAPI contract](patient-insurance.openapi.json) is authoritative. The [Postman collection](postman/patient-insurance.postman_collection.json) and [environment](postman/patient-insurance.postman_environment.json) are deterministic local exports from `php tools/patient-insurance-postman.php --write`; `--check` verifies them. Writes are disabled by default in the collection environment. No workspace is initialized or published.

All paths below start `/api/patients/{patient}/insurance-associations`, require existing Sanctum authentication and tenant context, and preserve ApiResponse. Reads require `patient_insurance.view` and patient-view authorization; writes also require `patient_insurance.manage`; sensitive reveals also require `patient_insurance.reveal`. These three exact capabilities are not default admin grants or wildcard shortcuts. Selecting catalog sources additionally requires catalog-view authorization. Responses use `Cache-Control: private, no-store`.

| Method / suffix | Input / result |
|---|---|
| GET `/` | Paginated associations, including inactive history, ordered by ID; default 15, max 100 |
| POST `/` | `insurer_id` OR `unverified_insurer_name`; optional nullable positive `coverage_priority`; creates association and revision 1 |
| GET `/{association}` | Current provider information; no implicit verified eligibility |
| PATCH `/{association}` | `expected_version`, nonblank `reason`, and one or more provider/priority/active changes; appends revision |
| GET `/{association}/history` | Paginated immutable provider/priority snapshots and actor/timestamp; private reasons omitted |
| GET `/{association}/policy-revisions` | Paginated policy history with masked identifiers; latest version first |
| POST `/{association}/policy-revisions` | `expected_version` (0 for first policy), nonblank `reason`, and partial policy fields; appends a complete snapshot |
| GET `/{association}/policy-revisions/{revision}/identifiers` | Explicit audited sensitive reveal for that exact revision |
| GET `/{association}/checks` | Paginated immutable manual observations; private provenance/reference omitted |
| POST `/{association}/checks` | `outcome`, `channel`, `checked_at`, nonblank `provenance`; optional `reference`, `policy_revision_id`, `service_date` |
| GET `/{association}/checks/{check}/details` | Explicit audited reveal of provenance/reference for that exact observation |

Policy fields: `policy_number`, `membership_id`, `subscriber_name`, `relationship` (`self`, `spouse`, `child`, `other`), `plan_id`, `effective_date`, `expiry_date`. Dates use YYYY-MM-DD, ordered when both supplied. Policy numbers/member IDs/subscriber names and change reasons are encrypted at rest; ordinary resources never serialize them in plaintext. Short identifiers remain fully masked. No identifier search/fingerprint or cross-patient subscriber authority is introduced.

Check outcomes: `unknown`, `active`, `inactive`, `unable_to_verify`. Channels: `external_card`, `external_portal`, `telephone`, `paper`, `other`. `checked_at` is an ISO timestamp including an offset, no later than recording time. `service_date` is optional scope, not an eligibility calculation. Staff identity and recorded_at are server-controlled. Each check pins the current association revision and, optionally, an exact same-association policy revision. Later corrections do not rewrite earlier observations. Active/inactive are staff-recorded external reports at a time, never an inferred current patient status or insurer-confirmed guarantee.

Unsupported fields return 422, foreign/mismatched patient, insurer, plan or revision targets return 404, denied capabilities return 403, and stale expected versions return 409. Creation is an explicit new record; clients should reload after uncertain outcomes before creating another association/check. Revision retries with stale versions cannot create a second revision. Pagination accepts only page/per_page. No insurance validation is attached to existing patient or treatment requests.

Examples (synthetic values): provider-only `{ "unverified_insurer_name": "Canopy" }`; manual observation `{ "outcome": "active", "channel": "external_card", "checked_at": "2026-11-15T09:00:00-05:00", "provenance": "Staff checked the external insurer portal after presenting the card" }`; first policy revision `{ "expected_version": 0, "reason": "Patient supplied member ID", "membership_id": "SYNTHETIC-1234" }`; later revision `{ "expected_version": 1, "reason": "Patient supplied policy details", "policy_number": "SYNTHETIC-5678", "relationship": "self" }`.

## Storage, access and milestone boundaries

Lightweight associations and their revision history are separate from policy identities/revisions and manual observations. Tenant-qualified database constraints protect every patient/catalog/policy link. Immutable history includes actor and recorded time; changes and sensitive reveals require a mandatory audit. Sensitive request fields and SQL bindings must not enter exception logs or ordinary audit metadata. Parent rows serialize revision writes, with optimistic versions checked under lock. Populated history cannot be rolled back or physically deleted.

M1-B does not enable M1-C uploads, evidence linking, quarantine release, physical insurance purge or external disclosure; no document adapter, existing document route or evidence activation flag is changed. Evidence attachments require the separately protected M1-C workflow. Originally based on 6532492, M1-B is now integrated against origin/main 31ba1dbaf6bdbde089786ba2c0dc8ba905d07ed1, which includes merged M1-C.

Benefit calculations and patient/insurer responsibility allocation remain P4-M2/M3. Claims and settlement remain P4-M4/M5. No observed coverage outcome posts an invoice adjustment, payment, benefit reservation or cash allocation. Scanner, retention/hold and backup/recovery activation gates from M1-C remain separate. No merge or deployment is authorized.

## Initial verification before integration

Focused PostgreSQL-only verification passed on PHP 8.2.35 / PHPUnit 10.5.58: **44 tests, 499 assertions, zero failures, errors or skips**, in 56.613 seconds. This includes 16 PatientInsuranceApiTest cases (179 assertions), one contract/export case (34), all 18 unchanged PatientApiTest cases (147), four PermissionSeederTest cases (103), three RoleSeederTest cases (24), the directly affected tenant permission-count case (7), and the catalog empty-migration financial-row preservation case (5). The catalog fixture now unwinds/restores the new empty FK dependency without relaxing its financial assertions. Initial failures were corrected before this final run: association creation explicitly initializes its first revision number, and test fixtures supply the existing dentist and explicit multi-clinic selection requirements.

Command: `php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Api/PatientInsuranceApiTest.php tests/Feature/Api/PatientInsuranceContractTest.php tests/Feature/Api/PatientApiTest.php tests/Feature/Auth/PermissionSeederTest.php tests/Feature/Auth/RoleSeederTest.php tests/Feature/TenantFoundationTest.php tests/Feature/Api/InsuranceCatalogApiTest.php --filter 'PatientInsuranceApiTest|PatientInsuranceContractTest|PatientApiTest|PermissionSeederTest|RoleSeederTest|test_same_role_slugs_and_room_names_are_valid_in_different_clinics|test_additive_migration_and_empty_rollback_preserve_existing_financial_rows' --log-junit storage/app/patient-insurance-final.xml`.

The disposable PostgreSQL database was isolated from clinic data. The tests cover no-insurance registration/update/booking/check-in/notes/treatment planning; provider-only and optional-plan records; multiple insurers and explicit priorities; progressive completion, clearing, stale versions and history; manual observations with no policy or payment; exact permissions, revoked membership, tenant/patient/revision isolation; encryption, short-ID masking, explicit audited reveals, safe exception logging, mandatory audit rollback, raw PostgreSQL guards and populated rollback refusal. Reports remain local under ignored storage/app. These initial results precede the combined integration review below.

## Final integration review against origin/main

Recommendation: **SAFE TO MERGE after review as the bounded optional-insurance feature, with M1-C evidence activation gates still closed.** This is not approval to activate evidence integrations or deploy.

All 24 original uncommitted files were copied and SHA-256 verified before integration, including the 16 untracked additions. The tracked patch and manifest remain in an ignored local integration backup. The branch was advanced from 6532492 to the fetched main commit 31ba1db using a fast-forward rebase, then the saved work was reapplied. No new feature/merge commit, push, merge command or deployment was performed. The resulting work remains uncommitted.

Eight overlap conflicts were resolved without dropping either feature:

- `.gitignore`: retain both features' documentation/export allowlists.
- `app/Exceptions/Handler.php`: retain M1-C's explicit protection-conflict response and document redaction, add M1-B route redaction, and preserve the union of sensitive never-flash fields.
- `database/seeders/PermissionSeeder.php`: register M1-B's three capabilities alongside M1-C's seven and the catalog's four; exclude all 14 from automatic admin grants. The combined registry has 82 definitions and default admin grants remain 68.
- `routes/api.php`: retain all 13 M1-C operations, including tombstone bindings, alongside 11 additive M1-B operations under existing Sanctum/tenant middleware.
- `tests/Feature/Api/InsuranceCatalogApiTest.php`: unwind M1-B -> M1-C -> catalog, restore catalog -> M1-C -> M1-B, and retain the exact financial-row preservation assertions.
- `tests/Feature/Auth/PermissionSeederTest.php`, `tests/Feature/Auth/RoleSeederTest.php`, `tests/Feature/TenantFoundationTest.php`: use the combined permission totals and preserve explicit-capability/default-grant checks.

Two M1-C migration fixtures in DocumentEvidencePostgresTest now unwind the empty M1-B dependency before removing M1-C's patient key and restore it afterward. No M1-C assertion was removed or relaxed. The M1-B migration itself was preserved: tests verify that its patient FK actually references `evidence_patient_tenant_unique`, that no redundant M1-B patient key is created, that empty recreation preserves existing patient rows and an unrelated sentinel function, and that M1-B rollback leaves M1-C's key/tables intact. All insurer/plan/policy/revision relationships remain tenant-qualified.

M1-C production document workflows, storage adapter, protection helper, policies, migration, and configuration are identical to current main. Delete/hold/link still share the tenant/series advisory fence, recheck eligibility after synchronization, release session locks in finally, and retain recoverable purge failures. Uploads/linking remain false by default; no insurance physical purge, quarantine release or external disclosure is introduced.

The reviewed M1-B workflow, models and resources remain unchanged from the preserved implementation. Insurance-specific validation is not attached to demographic, registration, booking, check-in or treatment requests. Provider-only records require no policy, membership identifier, subscriber, plan or published version. Manual card observations remain staff-recorded external reports, separate from verified benefits and actual payments. Policy identifiers/subscriber details and private provenance/reference remain encrypted and hidden from ordinary serialization; explicit reveals retain exact capabilities, patient/tenant checks, audit and no-store responses. Combined tests verify redaction for both insurance and legacy document routes.

Invoice/payment posting code, resources, requests, routes, dependencies and existing clinical response contracts are unchanged. Both the manual-observation financial snapshot check and the catalog migration's positive-payment preservation assertions pass. Complete diff review found no unrelated changes.

### Focused PostgreSQL verification

PHP 8.2.35 / PHPUnit 10.5.58; disposable PostgreSQL 16 database only:

| Run | Tests | Assertions | PHPUnit runtime |
|---|---:|---:|---:|
| New combined migration, capability, redaction and payment/quarantine boundaries | 4 | 70 | 55.484s |
| Focused M1-B and directly affected M1-C integration cases | 50 | 677 | 316.690s |
| Total | **54** | **747** | **372.174s (6m 12.174s)** |

Zero failures, errors or skips. The 50-case run includes all 16 M1-B API tests; both route/export contracts; all 17 M1-C lifecycle/concurrency tests (including independent-connection hold/delete and link/delete orderings); six directly relevant evidence activation, exact-authority, quarantine and audit cases; seven permission/role tests; the tenant permission-count case; and the catalog migration preservation case. The no-insurance M1-B case exercises registration, update, booking, check-in, notes and treatment planning on the combined schema. Unrelated passing patient/financial suites were not repeated; no full backend suite or SQLite/MySQL matrix ran.

JUnit evidence: ignored `storage/app/patient-insurance-integration-boundaries.xml` and `storage/app/patient-insurance-integration-focused.xml`. Pint passes on all 21 changed/new PHP files; diff whitespace checks, JSON parsing, and both contract/export tests pass.

### Remaining risks and activation requirements

No unresolved integration or authorization blocker was found in the reviewed scope. Session-affine PostgreSQL connections remain required for M1-C advisory locks; incompatible transaction-pooling proxies are unsupported. A coordinated API/background-worker rollout, approved retention/hold policies, approved scanner integration, and backup/recovery verification remain prerequisites before evidence activation. Existing encryption keys must remain available for historical policy/provenance decryption. Manual observations do not independently establish insurer truth, guaranteed benefits or cash receipt. Live insurer/card-reader integrations, production migration/restore, transaction-pooling behavior and the full regression suite were not exercised. Benefit calculations/responsibility allocation remain M2/M3; claims/settlement remain M4/M5.

### Exact diff inventory (26 files against 31ba1db)

```text
.gitignore
app/Exceptions/Handler.php
app/Http/Controllers/Api/PatientInsuranceController.php
app/Http/Resources/PatientInsuranceCheckResource.php
app/Http/Resources/PatientInsurancePolicyRevisionResource.php
app/Http/Resources/PatientInsurerAssociationResource.php
app/Models/PatientInsuranceCheck.php
app/Models/PatientInsurancePolicyRevision.php
app/Models/PatientInsurerAssociation.php
app/Services/PatientInsuranceWorkflow.php
database/migrations/2026_11_15_000001_create_patient_insurance.php
database/seeders/PermissionSeeder.php
docs/patient-insurance.md
docs/patient-insurance.openapi.json
docs/postman/patient-insurance.postman_collection.json
docs/postman/patient-insurance.postman_environment.json
routes/api.php
tests/Feature/Api/InsuranceCatalogApiTest.php
tests/Feature/Api/PatientInsuranceApiTest.php
tests/Feature/Api/PatientInsuranceContractTest.php
tests/Feature/Auth/PermissionSeederTest.php
tests/Feature/Auth/RoleSeederTest.php
tests/Feature/DocumentEvidencePostgresTest.php
tests/Feature/PatientInsuranceIntegrationPostgresTest.php
tests/Feature/TenantFoundationTest.php
tools/patient-insurance-postman.php
```
