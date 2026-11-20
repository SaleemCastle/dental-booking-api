# P4-M1-C Insurance Evidence Protection

Implemented locally for code review on `p4/m1-c-insurance-evidence`, based on origin/main `6532492` (merged P4-M1-A PR51). No merge/deployment or financial feature is included. Design inputs were ADR-002, ADR-001 and ADR-003 in the preserved architecture worktree; those drafts are not tracked in main and have not been silently copied into this feature. The explicit M1-C implementation request authorizes this bounded foundation, not unresolved legal or operational activation.

## Contract and activation boundary

Single content store: the existing `patient_documents` table remains the stored-object metadata, using the same DocumentStorage/FilesystemDocumentStorage, dedicated private disk, MIME/extension/size validation and private streamed downloads. There is no second bucket, parallel file service, public URL fallback, cross-tenant deduplication, insurer API or financial ledger.

Both `insurance_evidence.uploads_enabled` and `linking_enabled` default to **false** in server configuration. No request may set either. Enable uploads only after all readers/deleters run the protected binary, purpose/classification governance is approved and private object/backup controls are verified. Even then, the default UnconfiguredEvidenceScanner returns pending: insurance/mixed versions remain quarantined and cannot be listed/read/downloaded/linked until an approved scanner integration is installed. There is no quarantine-release endpoint or automatic insurance-purge switch. Clinical uploads retain their ordinary clinical read behavior when the scanner is pending; they cannot become insurance evidence until a trusted clean verdict exists. A scanner that flags a clinical file quarantines it too.

Capabilities are exact, deliberately assigned permissions, with no default admin/wildcard shortcut:

| Capability | Authority |
|---|---|
| insurance_documents.view | Authorized patient evidence, additionally requiring patient object access; mixed/clinical sources also require clinical access |
| insurance_documents.upload | Upload/version/archive authorized insurance documents; never clinical write authority |
| insurance_documents.link | Create a typed evidence link after independent source/target authorization and activation checks |
| insurance_documents.tenant_view | Additional dedicated authority for tenant/batch metadata, lists and downloads; patient grants do not imply it |
| insurance_documents.tenant_upload | Additional dedicated tenant/batch upload/version authority |
| document_holds.manage | Place/release an explicitly reviewed hold within authorized document purpose/ownership |
| document_quarantine.manage | Quarantine authorized content; does not release quarantine or permit purge |

There are 79 permission definitions versus 72 on the catalog baseline; default admin grants remain 68. Clinical read/write rules remain those of the legacy policy. A billing role alone, insurance permission, admin role or wildcard cannot bypass evidence purpose/target checks, retention or quarantine. A tenant batch cannot be linked to a patient to make it visible. Mixed sources are patient-owned and require both clinical and insurance read authority. Replacing a version cannot declassify a linked series: evidence-purpose access and retention apply to its entire lineage, while the link pins one exact version/hash. A clinical replacement cannot be uploaded using billing's insurance-write authority.

[OpenAPI contract](insurance-evidence.openapi.json) is authoritative for new routes. [Collection](postman/insurance-evidence.postman_collection.json) and [environment](postman/insurance-evidence.postman_environment.json) are deterministic local exports, default allow_writes=false. No Postman workspace was initialized or published. Obtain existing Sanctum authentication, select a tenant and deliberately grant the required exact abilities before local use; these artifacts do not manufacture identities or activate the gates.

| Method | Route | Behavior |
|---|---|---|
| GET/POST | /api/insurance/documents | Authorized tenant list/upload; additional tenant abilities required |
| GET/POST | /api/patients/{patient}/insurance-documents | Patient evidence list/upload; same-tenant patient authorization required |
| GET/DELETE | /api/insurance/documents/{document} | Purpose-authorized metadata / retention-protected deletion (insurance deletion blocked) |
| GET | /api/insurance/documents/{document}/download | Exact private, hash-verified, audited stream; never insurer disclosure |
| POST | /api/insurance/documents/{document}/versions | Immutable replacement; expected_version must match latest series version |
| POST | /api/patients/{patient}/documents/{document}/versions | Clinical replacement using existing clinical write authority; cannot alter classification |
| POST | /api/insurance/documents/{document}/archive | Authorized archival; no byte purge, hold release or history removal |
| POST | /api/document-evidence/{document}/links | One patient_id OR plan_version_id; real constrained targets only |
| POST | /api/document-evidence/{document}/holds | Place hold, or supply release_of_id for an explicit append-only release |
| POST | /api/document-evidence/{document}/quarantine | Empty body; exact quarantine permission; monotonic/idempotent effect |

Upload/version fields are file, UUID command_key, nonblank provenance (max500), optional insurance/mixed classification on insurance routes, and expected_version for replacements. Clinical replacement rejects classification input. All storage keys, tenant/patient ownership, actor IDs, hashes, scan verdicts, state and timestamps are server-controlled. Hold commands require UUID command_key and nonblank reason (max2000); reasons are encrypted and never returned in ordinary responses or generic audit. Link commands require one typed target and UUID command_key. No policy/claim/eligibility/remittance target is fabricated; future modules must add constrained typed targets, never a generic polymorphic integer. Evidence links have no withdrawal/update/delete API: corrections and withdrawal require an approved append-only design before activation.

Lists default15/max100, filter purpose/target/quarantine before pagination and counts. Existing patient list fields, PatientDocumentResource, MIME/security headers and ApiResponse/Sanctum contracts remain intact. New metadata adds series/version IDs, predecessor, owner scope, classification, hash, scan status and quarantine state, never disk/key/public URL. Unknown fields422, missing capability403, foreign/mismatched owner404; protection conflicts409 with DOCUMENT_RETENTION_BLOCKED, DOCUMENT_QUARANTINED, DOCUMENT_PURGE_IN_PROGRESS, DOCUMENT_VERSION_CONFLICT or DOCUMENT_EVIDENCE_NOT_ACTIVATED. Object-storage outages503; audit failures have no successful result. A 409 retention response does not claim erasure.

## Model and migration inventory

Migration: `database/migrations/2026_11_14_000001_protect_document_evidence.php` requires PostgreSQL. It adds guarded nullable patient ownership to existing stored-document rows without changing any existing object's key, bytes or clinical filename. Existing non-null patient ownership remains immutable. Tenant-owned documents cannot carry appointment/clinical-note links.

| New table | Purpose / constraints |
|---|---|
| document_series | Immutable tenant, owner and classification; guarded revision used to serialize writes; same-tenant patient FK |
| document_versions | One immutable companion per stored document; unique tenant/series/version and command; exact predecessor in same series; SHA256, trusted scan observation, provenance and observation time |
| document_protections | Guarded quarantine and purge-state projection; unique same-tenant version FK; quarantine cannot silently clear |
| document_hold_events | Append-only series-scoped placed/released records; same-tenant self-FK for release; one release per placement; encrypted reason and actor/command identity |
| document_evidence_links | Immutable exact-version reference to same-owner patient or tenant plan version; mutually exclusive real FKs; unique target/version and command |

Legacy rows are assigned a version1/series with `legacy_unverified`, null checksum, and explicit import provenance. No storage copying, malware verdict or invented byte hash occurs during migration. A missing historical file stays unavailable. New accepted versions compute SHA256 from the actual uploaded bytes; content is verified again before streams or evidence pinning. Hashes identify bytes, not truth, insurer confirmation or de-identification. Legacy unknown versions require a newly accepted/scanned replacement before evidence use.

Composite tenant candidate/FK keys, immutable history/identity guards, deferred version+protection requirement, series ownership checks, typed link checks and monotonic quarantine/purge transitions supplement application authorization. Eight owned helper functions and 23 new triggers protect the five new tables and existing document boundary. Existing insert/update document guards are replaced with explicit tenant-owned branching while retaining clinical/source/actor/tombstone invariants. CREATE OR REPLACE helper creation survives fresh migration recreation. Restrictive, dependency-ordered down removes only owned objects when empty; any populated protection/history refuses rollback, including legacy backfill. No unrestricted CASCADE drop was added; test fresh resets use only the disposable test database.

## Purge and concurrency guarantees

Hold, link and delete workflows first acquire the same session advisory fence keyed by tenant and immutable document-series ID. Within it, row lock order is business target (links only) -> series -> stored document -> protection/hold/link/audit. Pure hold/purge commands do not acquire business locks later. Replacement uploads lock patient before series where applicable. Raw reference and protection writers advance the series revision under lock; this prevents stale-snapshot races. The retention SQL function is VOLATILE so its guard sees committed protection after waiting, rather than a stale statement snapshot.

A clinical delete rechecks current authorization and every series hold/link under the parent lock, commits access revocation plus purge_in_progress and purge_started audit before external deletion, then marks purged only after a successful storage acknowledgment. The shared session fence spans these commits and the storage operation; every workflow releases it in finally on success or exception. Contention returns 409 DOCUMENT_PURGE_IN_PROGRESS. A committed hold/link makes subsequent deletion return 409 DOCUMENT_RETENTION_BLOCKED. After acquiring synchronization, holds/links recheck the lifecycle of every version in the series: any tombstone or non-retained purge state returns 409 DOCUMENT_PURGE_IN_PROGRESS, including sibling versions and failed purges. The link route binds tombstones so authorized late requests receive that conflict explicitly. Direct adapter deletion also checks canonical keys, retention and the committed fence, including forged unsaved model instances. Accepted canonical object keys are write-once: direct adapter writes cannot overwrite committed content. Flysystem normalization must leave every object key unchanged, preventing traversal, separator and trailing-slash aliases. The storage adapter is trusted server infrastructure, not an alternate user authorization API.

Failed purge keeps access revoked, records purge_failed and allows an authorized retry of the same immutable key. Worker death after deletion but before final acknowledgment leaves the fence/tombstone; another authorized worker retries idempotent deletion and completes the projection without restoring reads. No automatic insurance purge worker/outbox or retention-schedule activation is enabled. A session-affine/direct PostgreSQL connection is required for the advisory execution protocol; transaction-pooling configurations must not activate it without separate validation. Physical deletion of the live object is not proof of deletion from backups or insurer-held copies.

Mandatory audit covers list/read/download, version upload and replay, archive, link, hold placement/release, quarantine, deletion request, purge start/failure/completion. A stream audit means authorized stream opened, not delivery. Sensitive document exceptions log safe exception class/request context, not SQL bindings, filenames, provenance or hold reasons. Audit failure rolls back business history/protection; upload compensation removes only the uncommitted orphan UUID object. Failed compensation logs tenant ID only for private object-inventory reconciliation.

## Verification and compatibility

P1 purge synchronization correction (2026-11-15): the original advisory fence excluded only deleters and used the stored-document ID; hold/link workflows relied on transaction-scoped series locks and did not participate in that fence. Those row locks and triggers protect database invariants, but do not provide a common workflow fence across storage I/O. Hold eligibility checked only the requested version, while link readability/tombstone binding could produce a 404 or sibling-series trigger error instead of an explicit purge conflict. The correction shares the existing advisory helper by tenant/series, checks series-wide lifecycle after synchronization, and retains the existing purge states, triggers, authorization, and retry protocol.

Focused PostgreSQL 16 / PHP 8.2.35 verification for this correction: **75 tests, 782 assertions; zero failures, errors or skips**. `DocumentEvidencePostgresTest` passed 17 tests / 235 assertions (102.122 seconds); `InsuranceEvidenceApiTest`, `InsuranceEvidenceContractTest` and unchanged `PatientDocumentApiTest` passed 58 tests / 547 assertions (24.041 seconds). Commands: `php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/DocumentEvidencePostgresTest.php` and `php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/Api/InsuranceEvidenceApiTest.php tests/Feature/Api/InsuranceEvidenceContractTest.php tests/Feature/Api/PatientDocumentApiTest.php`. Both used an isolated disposable PostgreSQL database. No full regression suite or other database engine was run. JUnit reports: ignored `storage/app/insurance-evidence-verification/results/p1-purge-race.xml` and `p1-purge-api.xml`.

The new concurrency cases pause actual workflows at audit-before-commit or storage-after-commit boundaries, invoke competing HTTP requests on verified distinct PostgreSQL backend connections, and assert HTTP 201/200/409 outcomes and exact conflict codes. Both hold/delete and link/delete orderings cover the same version and sibling versions in one series. Additional assertions cover committed retention, late requests after fence release, unchanged bytes on rejection, advisory-lock release on success and exceptions, reference audit rollback, and storage failure followed by authorized purge retry. Existing raw-trigger and crash-recovery tests remain. Pint passes for the five changed PHP files; `git diff --check` passes. Session-affine PostgreSQL connections and coordinated worker rollout remain required; transaction-pooling behavior and the full regression suite were not verified.

Earlier foundation verification follows:

PostgreSQL only, PHP8.2.35 / PHPUnit10.5.58 / PostgreSQL16.10 Debian, on a newly created port-free disposable test database. The existing clinic database and preserved worktrees were not reset or migrated. The focused filter includes only InsuranceEvidenceApiTest, InsuranceEvidenceContractTest, DocumentEvidencePostgresTest, PatientDocumentApiTest, ApiResponseContractTest, PermissionSeederTest, RoleSeederTest, the affected TenantFoundation permission test and the catalog empty-migration financial-preservation test. The latter now unwinds/restores its new empty evidence FK dependency without changing any assertion.

**Final result: 79 tests, 782 assertions, zero failures/errors/skips; PHPUnit elapsed 62.053 seconds.**

| Focused test class | Tests | Assertions | Seconds |
|---|---:|---:|---:|
| ApiResponseContractTest | 5 | 41 | 4.234 |
| InsuranceCatalogApiTest | 1 | 5 | 0.970 |
| InsuranceEvidenceApiTest | 26 | 182 | 17.468 |
| InsuranceEvidenceContractTest | 1 | 31 | 0.047 |
| PatientDocumentApiTest | 31 | 334 | 12.787 |
| PermissionSeederTest | 4 | 117 | 2.165 |
| RoleSeederTest | 3 | 24 | 3.360 |
| DocumentEvidencePostgresTest | 7 | 41 | 18.055 |
| TenantFoundationTest | 1 | 7 | 2.716 |

Executed in the disposable runtime: `php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter 'ApiResponseContractTest|InsuranceEvidenceApiTest|InsuranceEvidenceContractTest|DocumentEvidencePostgresTest|PatientDocumentApiTest|PermissionSeederTest|RoleSeederTest|test_same_role_slugs_and_room_names_are_valid_in_different_clinics|test_additive_migration_and_empty_rollback_preserve_existing_financial_rows' --log-junit /tmp/evidence/results/code-review.xml`. Raw JUnit/log evidence is preserved locally under ignored `storage/app/insurance-evidence-verification/results/`.

Static verification: Pint `--test` on all 28 changed PHP files, `git diff --check`, JSON parsing, local documentation links, and evidence/catalog/main Postman exporters `--check`. The PostgreSQL-only workflow parses and retains the full PG CI test command; no full-suite execution is implied.

The seven PostgreSQL lifecycle/race tests use committed fixtures and independent connections: hold before delete, link before delete, committed purge barrier/duplicate worker rejection, crash recovery, repeated fresh/empty rollback and unknown legacy hash/backfill preservation, and fresh replacement authorization after a concurrent purpose link. The API tests cover real billing/clinical roles, list counts, all legacy endpoints, mixed/tenant context, exact permissions/direct calls/revocation, idempotency/version conflicts, hash corruption/quarantine, raw ownership/history changes, mandatory audit rollback and storage path normalization. All 31 existing PatientDocumentApiTest cases are retained unchanged. No original assertion was deleted or relaxed. Seeder counts increase only for seven explicit abilities; old default clinical/admin grants remain unchanged.

CI is aligned to the confirmed PostgreSQL-only project policy: the legacy SQLite/MySQL matrix entries and unused MySQL service were removed; every PostgreSQL test and the existing full PG suite command remain intact. The workflow was parsed/checked statically; it was not executed here. No full backend suite, SQLite/MySQL suite, real malware/provider request, production migration, backup/restore drill or live permission review ran. Legacy whole-schema rollback fixtures outside the focused set are not certified by this task. The main/catalog Postman artifacts remain unchanged; the new evidence exports are isolated.

## Legal and operational activation gates

Deployment requires PostgreSQL session-affine connections for advisory locks; an incompatible transaction-pooling proxy must not sit between these workflows and PostgreSQL. Coordinate API and background-worker rollout so every reader, writer and deleter uses the protected implementation before activation. Retention and hold policies require approval, and the scanner integration plus backup/recovery behavior must be verified before activation. Insurance uploads and linking remain disabled by default. This release does not activate insurance physical purge or external disclosure.

- EVD-01: qualified approval of lawful basis, retention classes/anchors/durations, data-subject handling, tenant responsibilities and insurer contracts. No statutory duration or approved retain_until is invented. Fail-closed retention is not a claim that indefinite retention is lawful.
- EVD-02: approve who may place/release holds, legal authority/review, series versus version/patient/tenant/case granularity, and urgent late-hold handling. The current manage capability includes both placement and release; splitting authorities requires an approved later change. Current holds cover the document series; wider legal case orchestration is not implemented. Deleted/uncertain-purge content cannot receive a false preservation acknowledgment.
- EVD-03: inventory backup/object-version/export copies; approve restore suppression before serving restored data, deletion-in-backup policy, residency and external-copy handling. This code does not assert that backup copies are erased.
- EVD-04: approve billing-purpose and mixed classifications, source/target access and redaction/disclosure rules. Routine replacements inherit restrictions; reviewed redacted cross-series derivatives/disclosure manifests require later bounded work.
- EVD-05: install an approved scanner and policy with trustworthy clean attestation; validate private disk/permissions, bounded storage timeouts, session-affine PG connections, orphan inventory and failed-purge recovery. No scanner/quarantine-release provider is assumed.
- EVD-06: approve client wording and coordinated rollout: drain every old reader/deleter, apply the migration and deploy protected binaries in a controlled window, verify legacy clinical writes/reads, then deliberately enable new uploads. Old binaries cannot safely run with evidence activated; deferred version requirements can reject old unversioned writes. Populated rollback needs forward recovery/approved restore, not old immediate-purge code.

Keep both upload/link gates off until the relevant gates pass. Even after those are enabled, quarantine and mandatory clean verification remain enforced, and insurance purge/disclosure remain unavailable. No claims submission, eligibility, patient policy, remittance, accounting or settlement feature is activated.

## Exact implementation file inventory

```text
.github/workflows/tests.yml
.gitignore
app/Documents/DocumentProtection.php
app/Documents/EvidenceScanner.php
app/Documents/FilesystemDocumentStorage.php
app/Documents/UnconfiguredEvidenceScanner.php
app/Exceptions/DocumentProtectionConflict.php
app/Exceptions/Handler.php
app/Http/Controllers/Api/InsuranceDocumentController.php
app/Http/Controllers/Api/PatientDocumentController.php
app/Http/Resources/InsuranceDocumentResource.php
app/Models/DocumentSeries.php
app/Models/DocumentVersion.php
app/Models/PatientDocument.php
app/Policies/PatientDocumentPolicy.php
app/Providers/AppServiceProvider.php
app/Services/InsuranceDocumentWorkflow.php
app/Services/PatientDocumentWorkflow.php
config/insurance_evidence.php
database/migrations/2026_11_14_000001_protect_document_evidence.php
database/seeders/PermissionSeeder.php
docs/insurance-evidence.md
docs/insurance-evidence.openapi.json
docs/postman/insurance-evidence.postman_collection.json
docs/postman/insurance-evidence.postman_environment.json
routes/api.php
tests/Feature/Api/InsuranceCatalogApiTest.php
tests/Feature/Api/InsuranceEvidenceApiTest.php
tests/Feature/Api/InsuranceEvidenceContractTest.php
tests/Feature/Auth/PermissionSeederTest.php
tests/Feature/Auth/RoleSeederTest.php
tests/Feature/DocumentEvidencePostgresTest.php
tests/Feature/TenantFoundationTest.php
tools/insurance-evidence-postman.php
```

Review recommendation: **SAFE TO MERGE as a disabled-by-default protection foundation after code review and standard checks; NOT APPROVED FOR ACTIVATION OR DEPLOYMENT.** The focused test result is not a claim that the unrun full CI suite, production privileges, legal schedules or backup protocol are verified. No commit, push, merge, deployment or further insurance milestone was performed.
