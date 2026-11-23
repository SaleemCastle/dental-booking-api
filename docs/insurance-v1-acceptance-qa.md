# P4 Insurance v1 acceptance QA

## Scope and result

This review covers the merged P4 insurance domain through M6 and the M7 acceptance criteria. It is a repository/API review, not a claim that production or PostgreSQL end-to-end behavior has passed. No verified correctness or security defect requiring application changes was found in this pass. **M7 remains incomplete pending successful PostgreSQL application tests and release QA.**

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
- The M6 PR authored `InsuranceReportingApiTest` for exact permission, wildcard denial, tenant isolation, aggregate-only fields, audit, date validation, and cache headers. Its three API test cases did not reach assertions locally in the earlier run because PostgreSQL-only migrations rejected SQLite.
- This checkout has no `vendor/bin/phpunit` and PHP reports no `pdo_pgsql`; no PHP or API test command can be run here without installing dependencies or changing the database environment. No dependencies were installed.
- GitHub PR #70 was merged at `55d6b48`. Its `tests.yml` workflow run [45253685640](https://github.com/SaleemCastle/dental-booking-api/actions/runs/45253685640) concluded failure with zero jobs. The successful `pull_request_target` run was a metadata workflow, not application testing. Therefore there is no green hosted PostgreSQL test evidence for M6/M7.
- Frontend PR [#31](https://github.com/SaleemCastle/dental-booking/pull/31) merged at `e39d1c0`. It records compatibility for the P4-M3 estimate client and reports 21 focused assertions, all 23 Node test files, typecheck, lint, and production build passing; dependencies were already installed, so no install or lockfile change was needed. The frontend contains no client/screens for insurance policies/evidence, eligibility, benefits, claims/responses, authorizations, appeals, remittances, insurer receipts/obligations, or aggregate insurance reporting. Frontend end-to-end acceptance therefore covers only the estimate slice; whether the remaining API workflows require user-facing frontend flows is a product scope decision, not a backend defect.

## Remaining release QA

1. Run the focused insurance PostgreSQL API/security tests and relevant migration checks in a hosted PostgreSQL environment with Composer dependencies installed. Capture actual test names, counts, assertions, and job URL.
2. Resolve why the M6 `tests.yml` run ended with zero jobs, then ensure the tests workflow runs for pull requests targeting `main`; do not treat the separate pull-request metadata check as test evidence.
3. Product/release owner confirms whether the unrepresented Insurance v1 API workflows are intentionally API-only or need frontend screens before M7 can be accepted.
4. Review the evidence and mark the Trello M7 card Done only after hosted PostgreSQL tests pass and the frontend scope decision is recorded. Preserve any remaining production operational risks in the release record.

No Settlement v2 activation or financial posting is part of these checks. Open Dental work remains out of scope until authorized sandbox/test access is available.
