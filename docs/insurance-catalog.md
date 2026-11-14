# P4-M1-A: Insurance Catalog Foundation

Status: implemented locally for code review; not merged or deployed. Scope authorization is the explicit P4-M1-A request. The broader ADRs remain recommendations awaiting their separate approvals; this foundation does not activate benefits, policies, evidence or financial settlement.

Design provenance: P4-M1-A was reviewed against the ADR-001/002/003 and P4-M0 drafts in the preserved architecture workspace. Those separate architecture/audit deliverables are not copied into this catalog-only change. This guide and the standalone OpenAPI contract describe the bounded foundation being proposed. Existing tenant/model guards, Sanctum routes, Gate policies, PermissionSeeder, ApiResponse, resources, audit workflow and PHPUnit conventions are reused.

## Scope and API contract

Tenant-owned insurer and plan definitions, metadata-only plan versions, explicit authorization and audit. No insurer catalog/benefit data is seeded. No patient policy, eligibility, benefit evaluator, evidence links, claims, remittance, invoice/payment changes or UI are included.

The authoritative additive contract is [insurance-catalog.openapi.json](insurance-catalog.openapi.json). It stands alongside the existing API contract; existing operations/exports are not rewritten. All routes below are under `/api/insurance` and use existing `auth:sanctum`, tenant resolution, ApiResponse's eight keys and private/no-store responses. Tenant ownership and actor attribution are server-controlled. Existing resources and billing behavior retain their contracts.

| Method | Path | Success data / behavior |
|---|---|---|
| GET, POST | `/insurers` | Paginated array / `data.insurer` created201 |
| GET, PATCH, DELETE | `/insurers/{insurer}` | `data.insurer`; DELETE archives with `active=false` |
| GET, POST | `/insurers/{insurer}/plans` | Paginated array / `data.plan` created201 |
| GET, PATCH, DELETE | `/plans/{plan}` | `data.plan`; DELETE archives |
| GET, POST | `/plans/{plan}/versions` | Paginated array / `data.plan_version` created201 |
| GET, PATCH, DELETE | `/plan-versions/{version}` | `data.plan_version`; PATCH edits drafts only; DELETE withdraws unpublished versions |
| POST | `/plan-versions/{version}/review` | Review draft metadata; `data.plan_version` |
| POST | `/plan-versions/{version}/publish` | Publish reviewed metadata; `data.plan_version` |

Lists default25 records, maximum100; `page`, `per_page`, `active` and `search` for insurer/plan lists; `status` instead of active/search for versions. Pagination stays in `meta.pagination`. Invalid input422, authentication401, missing ability403, foreign/missing record404, stale expected version/invalid lifecycle/overlapping publication409 `CONFLICT`. Invalid writes create no audit or partial data. No client chooses `tenant_id`, parent IDs, actor IDs, status, version numbers, timestamps, benefit rules or document IDs.

Insurer/plan create fields: immutable uppercase ASCII `code` (1..50, starts alphanumeric, then alphanumeric/underscore/hyphen), required nonblank `name` (max255), optional boolean `active` (defaulttrue). Codes are unique per tenant for insurers; per tenant/insurer for plans. PATCH permits name/active, not code or parent changes. Mutation requires `expected_version`, matching current integer `lock_version`; DELETE accepts it as JSON or query. Mutation increments lock_version once. Published history remains accessible after parent archival; new versions and publication require active parents.

Version create fields:

```json
{
  "name": "Catalog metadata revision",
  "currency": "JMD",
  "effective_from": "2026-01-01",
  "effective_to": "2026-12-31",
  "source_reference": "Non-sensitive catalog reference"
}
```

`source_reference` is optional catalog text (max255), not retained evidence or proof of benefits. Do not place PHI or policy/member identifiers in catalog metadata. Currency is a required uppercase three-letter metadata label, not an FX operation or seeded fee/benefit schedule. Dates are ISO calendar dates within years0001..9999; expiry is inclusive, optional/null for open-ended, and cannot precede effective_from. A raw label does not assert eligibility or insurer confirmation.

Version response fields: id, insurance_plan_id, server-assigned sequential `version`, optimistic `lock_version`, status, name/currency/effective dates/source_reference, creator/last editor/reviewer/publisher IDs and review/publication timestamps, created_at/updated_at. `benefits_configured` is always false in this foundation; publishing catalog metadata cannot be used to claim confirmed benefits or a usable benefit evaluator.

## Lifecycle, permissions and audit

Lifecycle: `draft -> reviewed -> published`, or `draft/reviewed -> withdrawn`. Only drafts can be edited. Reviewed versions are frozen pending publication; corrections require a new draft version rather than overwriting the review. Published/withdrawn versions reject every update or physical deletion. Published ranges of one plan cannot overlap, including their common endpoint; a new version starting the day after the previous expiry is permitted. Plan archival prevents new selection while retaining all version history. Retirement/amendment of a published schedule is future lifecycle work, not a published-row PATCH here.

| Exact permission | Authority |
|---|---|
| `insurance_catalog.view` | List/read catalog objects |
| `insurance_catalog.manage` | Create/edit/archive definitions and drafts; withdraw unpublished versions |
| `insurance_catalog.review` | Review a draft the actor has never edited |
| `insurance_catalog.publish` | Publish a reviewed version the actor has never edited |

No admin/billing role shortcut or `*`/`insurance_catalog.*` shortcut grants these abilities. PermissionSeeder defines four concrete slugs but does not auto-assign them to any role, including admin. Existing admin grants remain unchanged; authorized clinic role-management explicitly assigns catalog permissions. Users can hold multiple capabilities, but any version editor is barred from reviewing or publishing that version. A non-editor may both review and publish if explicitly granted both permissions; three-person approval is not assumed. The editor history tracks every editing user, not merely the latest editor, including raw draft writes. Membership removal preserves historical actor IDs; new attribution requires current tenant membership.

Workflow-level Gate checks protect direct callers as well as controllers/FormRequests. The common Gate first checks tenant membership/object ownership, then delegates these new models to their special policies so wildcard shortcuts cannot bypass separation. Tenant-scoped route binding and fresh scoped reloads reject foreign objects even for a shared staff identity. There is no globally writable configuration.

Audit events use existing AuditLogService: `created`, `updated`, `archived` for definitions; `created`, `updated`, `reviewed`, `published`, `withdrawn` for versions. Business row/editor tracking and audit commit together; audit failure rolls back all writes. Generic audit retains safe IDs/request context, not catalog text. Published metadata is its immutable decision snapshot. Draft mutations use optimistic concurrency but do not implement a new generic HTTP idempotency engine; callers must reread after an uncertain create response instead of blindly repeating POST.

## Migration and integrity review

Migration: `database/migrations/2026_11_13_000001_create_insurance_catalog.php` creates only `insurers`, `insurance_plans`, `insurance_plan_versions` and `insurance_version_editors`. No existing financial, patient or document table is modified. No data backfill or catalog fixtures are seeded. Same-tenant composite candidate/FK keys, scoped codes, unique version numbers and tenant-first browse indexes constrain ownership and lookup. Historical actor attribution uses membership checks on new/changed actors, rather than a cascading membership FK that would erase or block historical membership departure.

Database guards enforce stable ownership/code/parent/version identity, version increments, date order, currency/code shape, review/publication attribution, permitted transitions, all-editor separation and immutable history. AFTER triggers populate immutable editor history, including on direct SQL draft edits. Database deletes are rejected; API DELETE performs an audited archive/withdrawal. PostgreSQL also rejects TRUNCATE. SQLite/MySQL TRUNCATE/privileged DDL is not claimed protected by row triggers; restrict application DB privileges appropriately.

Common write lock order is insurer -> plan -> version -> editor history. Per-plan locks serialize application version numbering/publication and recheck expected_version under lock. PostgreSQL additionally uses a range exclusion constraint on plan ID and inclusive date range to reject overlapping publications even through concurrent raw SQL, without needing btree_gist or elevated extension privileges. SQLite/MySQL include overlap guards and application locks; equivalent raw-writer concurrent publication is not certified by these PostgreSQL checks.

Empty rollback drops only new schema/guards; populated rollback refuses to destroy catalog history. Rehearse future migration deployment normally with backup/permissions and existing engine-specific conventions; this task applies migrations only in disposable test environments. Old binaries may ignore new tables, but must not be allowed to mutate their history via elevated raw DB access. No live migration/deployment was performed.

## Local request artifacts and checks

Use `php tools/insurance-catalog-postman.php --write` to regenerate the standalone catalog collection/environment from the OpenAPI JSON; `--check` verifies reproducibility through the existing PostmanExport implementation. The existing main API/OpenAPI/collection/environment artifacts are not rewritten. Import [catalog requests](postman/insurance-catalog.postman_collection.json) and [catalog environment](postman/insurance-catalog.postman_environment.json), obtain an existing Sanctum token through the main auth workflow, and deliberately select tenant and resource IDs. `allow_writes=false` is the generated default. Do not run these requests on production.

Review/publish requires a different non-editor identity with the relevant exact permission; the collection does not manufacture users or switch identities automatically. Set expected_version from a fresh response at each mutation; generated examples use1 only as a placeholder. DELETE examples send it as query. Publication does not send anything to an insurer.

Focused tests: `tests/Feature/Api/InsuranceCatalogApiTest.php`, `tests/Feature/Api/InsuranceCatalogContractTest.php`, `tests/Feature/InsuranceCatalogPostgresTest.php`. Updated permission-seeder/tenant assertions distinguish the72 available permission definitions from the unchanged68 default admin grants. Tests use synthetic catalog metadata only, not fictional benefit rules.

PostgreSQL integrity tests use uniquely named schemas and drop only their verified random schema prefix; they never migrate/reset the configured database. The catalog API suite uses the repository's RefreshDatabase convention and therefore must use a disposable test database. The PostgreSQL race test uses two independent connections: the second overlapping publication blocks, then is rejected after the first commits. Tests also cover ownership, raw history mutation, TRUNCATE, source editor restrictions and empty rollback.

## Clean catalog branch and verification (2026-11-14)

Branch: `p4/m1-a-insurance-catalog`, based on refreshed `origin/main` at `ae3af8fafcc59fa18f47980c9430edc1dd3c1f54`. The original `p4/m0-p4-audit` worktree, staged additions, unstaged integration changes, ADR/M0 drafts and branch-only governance commit `75d76d3` are preserved. That governance commit is not an ancestor of this branch and none of its controllers, services, models, migration, endpoints, permission grants, tests or generated main-contract changes was transferred.

The transfer includes the22 new catalog files and eight narrowly adapted integration files. The permission baseline on origin/main is68 definitions, not the78 definitions on the governance branch. This catalog adds exactly four explicit abilities:72 total definitions, with unchanged68 default admin grants. Seeder tests retain the upstream messaging assertions and grant counts; only insurance expectations are added. TenantFoundationTest changes only the insurance permission assertion. The two unrelated retired-messaging expectation corrections remain in the preserved worktree.

Design provenance is retained above, but separate draft ADR/M0 deliverables were not added to this feature PR. The catalog contract, request artifacts and this guide are its relevant documentation. No invoice/payment workflow, model, resource, settlement behavior, main OpenAPI/Postman artifact, composer dependency, database configuration, deployment configuration or CI file changed.

The previously recorded21-test PostgreSQL result alone was insufficient for this transfer because removing governance changes the migration and permission baseline. Fresh evidence from the clean source tree:

| Verification | Result |
|---|---|
| Focused PostgreSQL tests | PASS:29 tests,352 assertions,14.230s; zero failures/errors/skips |
| Catalog subset |15 API tests,1 contract/export test,5 PostgreSQL lifecycle/integrity tests;218 assertions retained |
| Directly affected integrations |PermissionSeederTest, RoleSeederTest and the same-role/different-clinic TenantFoundationTest permission test |
| Three actual fresh migrations |PASS:1s,2s,2s in a disposable PostgreSQL database |
| Immutability, empty rollback, populated-history refusal, helper recreation, ownership and editor separation |PASS in the catalog subset |
| Independent-connection overlapping publication |PASS: blocking and exclusion violation retain one accepted publication |
| Changed-file Pint |PASS:25 PHP files; normalized line endings in the three adapted integration tests only |
| Diff whitespace |`git diff --check` and `git diff --cached --check` checked after staging |
| Local API artifact checks |Both catalog and unchanged main Postman exports match their respective contracts |

Runtime:PHP8.2.35, PHPUnit10.5.58, PostgreSQL16.10. Composer dependencies were installed from the unchanged lock file. Explicit PostgreSQL testing environment selected a newly created database; no existing clinic database was migrated/reset. The actual focused filter was `InsuranceCatalogApiTest|InsuranceCatalogContractTest|InsuranceCatalogPostgresTest|PermissionSeederTest|RoleSeederTest|test_same_role_slugs_and_room_names_are_valid_in_different_clinics`. No complete backend suite, database matrix, MySQL/SQLite tests, production migration or live provider call was run. The unchanged messaging-test corrections were not exercised as part of this bounded catalog-only verification.

Local logs and JUnit reports are under ignored `storage/app/catalog-pr-verification/` in this worktree. Earlier21/218 verification remains in the original worktree as historical evidence; the29/352 result is the evidence for this branch. Subsequent documentation and staging updates do not change the tested runtime, test, dependency or configuration files.

Exact PR file inventory (all tracked and staged):

```text
.gitignore
app/Http/Controllers/Api/InsuranceCatalogController.php
app/Http/Requests/InsuranceCatalogRequest.php
app/Http/Resources/InsurancePlanResource.php
app/Http/Resources/InsurancePlanVersionResource.php
app/Http/Resources/InsurerResource.php
app/Insurance/CatalogInput.php
app/Models/Concerns/BelongsToTenant.php
app/Models/InsurancePlan.php
app/Models/InsurancePlanVersion.php
app/Models/Insurer.php
app/Policies/InsurancePlanPolicy.php
app/Policies/InsurancePlanVersionPolicy.php
app/Policies/InsurerPolicy.php
app/Providers/AuthServiceProvider.php
app/Services/InsuranceCatalogWorkflow.php
database/migrations/2026_11_13_000001_create_insurance_catalog.php
database/seeders/PermissionSeeder.php
docs/insurance-catalog.md
docs/insurance-catalog.openapi.json
docs/postman/insurance-catalog.postman_collection.json
docs/postman/insurance-catalog.postman_environment.json
routes/api.php
tests/Feature/Api/InsuranceCatalogApiTest.php
tests/Feature/Api/InsuranceCatalogContractTest.php
tests/Feature/Auth/PermissionSeederTest.php
tests/Feature/Auth/RoleSeederTest.php
tests/Feature/InsuranceCatalogPostgresTest.php
tests/Feature/TenantFoundationTest.php
tools/insurance-catalog-postman.php
```

Migration safety remains unchanged: populated catalog rollback refuses destructive removal; published versions and editor history cannot be rewritten; PostgreSQL overlap exclusion and tenant FKs remain enforced. Production-role privileges, backup/restore and concurrent deploy coordination were not inspected. PostgreSQL is the sole target for new development, confirmed by the project owner for active production/staging deployments; legacy engine settings remain untouched by this PR.

Status: catalog-only diff ready for review and subsequent PR publication. All30 intended files are staged. No commit, push, hosted PR creation, merge, deployment or P4-M1-B/C work was performed. The original worktree remains the source of the separate governance/architecture work; no stash, reset, clean or overwrite was used there.
