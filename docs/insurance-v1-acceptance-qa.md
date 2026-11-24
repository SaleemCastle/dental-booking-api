# P4 Insurance v1 acceptance QA

## Scope and result

This review covers the merged P4 insurance domain through M6 and the M7 acceptance criteria. Local PostgreSQL testing verified targeted API paths, but this is not production or hosted release evidence. The review found and fixed two M6 runtime defects: the reporting controller lacked its service import, and the remittance advice model queried a singular table name while the migration creates the plural table. **M7 remains incomplete pending a green curated application suite, contract/export reconciliation, hosted release QA, and the product scope decision below.**

Settlement v2 posting, allocation, refunds, reversals, and activation remain deferred. The nonposting eligibility, benefits, estimate, claim, authorization, appeal, remittance, receipt, and insurer-obligation workflows are within Insurance v1; deferred financial posting is not an Insurance v1 defect or blocker.

## Acceptance trace

| Area | Contract and implementation evidence | Review finding |
| --- | --- | --- |
| Domain rules | `insurance-catalog.md`, `manual-insurance-eligibility.md`, `insurance-benefits.md`, `insurance-estimates.md`, `insurance-claims.md`, `prior-authorizations.md`, `insurance-remittances.md`, `insurance-receipts.md`, `insurance-obligations.md` | Manual insurer data remains staff-recorded evidence/observations. Claim and appeal history is append-only. Remittances and receipts do not prove cash posting. |
| Tenant/patient authorization | Feature policies and tenant middleware across insurance controllers; tenant-qualified foreign keys and migration guards; M6 `InsuranceReportAccessPolicy` | Existing patient APIs use patient-scoped routes and object authorization. M6 reporting additionally requires active tenant membership and exact `patients.view` plus `insurance_reports.view`; wildcard permissions do not grant it. Runtime IDOR/cross-tenant cases still need PostgreSQL execution. |
| API contracts | Per-feature OpenAPI files and Postman collections under `docs/`; M6 contract in `docs/insurance-reporting.md` and root OpenAPI/Postman exports | The reviewed API additions follow the standard success/error envelope. Reporting is aggregate-only, bounded to a validated date range, and returns `private, no-store`. Frontend compatibility evidence is in merged `SaleemCastle/dental-booking` PR #31. |
| Audit | Insurance workflow documentation and `AuditLogService` call sites, including `insurance_report.viewed` | Sensitive decisions and reporting reads are audited by the existing application paths. Audit failure behavior and append-only database protections require PostgreSQL tests. |
| Migrations and history | Insurance migration chain under `database/migrations`; PostgreSQL-specific evidence, claim, receipt, obligation and report indexes/guards | Migrations are additive and tenant-qualified by the feature designs. Several intentionally reject SQLite or depend on PostgreSQL triggers/constraints; schema compatibility has not been exercised in this environment. |
| Financial/clinical ledgers | `docs/insurance-v1-feature-coverage.md`, M5 workflow contracts, ADR-001/ADR-004 status | Reviewed insurer observation/reporting paths do not post insurer cash or mutate invoices, payments, receivables, patient balances, benefits, or clinical records. Settlement v2 remains disabled/deferred. |

## Test and CI evidence

- Previously recorded focused unit/domain runs: 27 tests / 148 assertions; claim-response tests: 9 / 43 assertions; M6 access-policy tests: 4 / 8 assertions. These results are carried forward as prior evidence and were not rerun during this M7 pass.
- `InsuranceReportingApiTest`: **3 tests / 35 assertions passed** on a disposable local PostgreSQL 16 database after the two runtime fixes. The cases cover exact permission and wildcard denial, active tenant membership, tenant isolation, aggregate-only fields, audit, date validation, and cache headers.
- `PatientInsuranceApiTest`: **16 tests / 179 assertions passed** when run by itself on the same PostgreSQL test database. This confirms the patient API count failures in the curated combined run were caused by cross-file migration/test ordering, not by the isolated test file.
- A curated run of 16 P4 insurance feature files completed **125 tests / 1,472 assertions with 2 errors and 9 failures**. It is not green acceptance evidence. The errors are rollback tests invoking individual `down()` migrations while later insurance tables still hold foreign keys. Failures include a stale global permission count (82 expected vs 116 after newer permissions were added), contract tests whose route inventory omits newer insurance APIs, stale generated Postman exports, and patient-table count assertions that fail only in the combined run. These remain suite/contract reconciliation work; they were not hidden or counted as passes.
- Tests ran with the repository's locked Composer dependencies and PHP 8.2 `pdo_pgsql` extension, using an isolated local PostgreSQL 16 test database. No production database or live insurer service was used.
- GitHub PR #70 was merged at `55d6b48`. Its `tests.yml` workflow run [45253685640](https://github.com/SaleemCastle/dental-booking-api/actions/runs/45253685640) concluded failure with zero jobs. The successful `pull_request_target` run was a metadata workflow, not application testing. Therefore there is no green hosted PostgreSQL test evidence for M6/M7.
- Frontend PR [#31](https://github.com/SaleemCastle/dental-booking/pull/31) merged at `e39d1c0`. It records compatibility for the P4-M3 estimate client and reports 21 focused assertions, all 23 Node test files, typecheck, lint, and production build passing; dependencies were already installed, so no install or lockfile change was needed. The frontend contains no client/screens for insurance policies/evidence, eligibility, benefits, claims/responses, authorizations, appeals, remittances, insurer receipts/obligations, or aggregate insurance reporting. Frontend end-to-end acceptance therefore covers only the estimate slice; whether the remaining API workflows require user-facing frontend flows is a product scope decision, not a backend defect.

## Remaining release QA

1. Reconcile failing contract tests with the merged M4/M5 route set and regenerate affected Postman exports from their current OpenAPI documents; keep exact route coverage and write-disabled safeguards.
2. Correct the curated test ordering/rollback setup so migration compatibility checks run in valid reverse dependency order and each test has isolated data. Update permission tests to assert the permissions relevant to their feature rather than a repository-wide count.
3. Resolve why the M6 `tests.yml` run ended with zero jobs, then ensure the tests workflow runs for pull requests targeting `main`; do not treat the separate pull-request metadata check as test evidence.
4. Product/release owner confirms whether the unrepresented Insurance v1 API workflows are intentionally API-only or need frontend screens before M7 can be accepted.
5. Repeat the focused hosted PostgreSQL security/migration acceptance after these corrections, capture the job URL, and mark the Trello M7 card Done only after it passes and the frontend scope decision is recorded.

No Settlement v2 activation or financial posting is part of these checks. Open Dental work remains out of scope until authorized sandbox/test access is available.
