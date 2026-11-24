# Insurance v1 feature coverage

This matrix records repository evidence for the P4 Insurance v1 closeout. "Implemented" means code and contract evidence exists; it does not imply every runtime acceptance suite passed in every environment.

| Card | Scope | Status | Evidence |
| --- | --- | --- | --- |
| P4-M0 | Insurance domain and catalog foundations | Implemented | `docs/insurance-catalog.md`; catalog models, migrations, API and Postman contract |
| P4-M1 | Patient policies, dependents and evidence | Implemented | `docs/patient-insurance.md`, `docs/insurance-evidence.md`; `PatientInsuranceApiTest`, evidence contract tests |
| P4-M2 | Manual eligibility and benefit observations | Implemented | `docs/manual-insurance-eligibility.md`, `docs/insurance-benefits.md`; eligibility, benefits and contract tests |
| P4-M3 | Provisional treatment estimates | Implemented | `docs/insurance-estimates.md`; immutable estimates are projections, not confirmed payer liability |
| P4-M4 | Claims, authorizations, responses, appeals and corrections | Implemented; targeted integration tests require PostgreSQL CI | `docs/insurance-claims.md`, `docs/prior-authorizations.md`, `docs/p4-m4-c-gap-analysis.md`; M4-A through M4-D work on main |
| P4-M5-A/B | Remittance advice, receipt observations and obligation register | Implemented as nonposting observations/proposals | `docs/insurance-remittances.md`, `docs/insurance-receipts.md`, `docs/insurance-obligations.md`; no invoice/payment/balance mutation |
| P4-M5 reconciliation / Settlement v2 | Financial posting, cash allocations, refunds and activation | Deferred | ADR-001/ADR-004 remain proposed/pending; feature flag defaults false; no posting writer is part of Insurance v1 |
| P4-M6 | Aggregate insurance reporting, access controls and read audit | Merged in PR #70; focused local PostgreSQL API suite passes after reporting service/table mapping fixes (3 tests / 35 assertions); hosted release QA outstanding | `docs/insurance-reporting.md`; `InsuranceReportingApiTest`; exact `insurance_reports.view` plus `patients.view`; aggregate-only and audited |
| P4-M7 | End-to-end acceptance and PostgreSQL release evidence | Curated local PostgreSQL acceptance passes (126 tests / 1,545 assertions across 17 P4 files); hosted CI evidence and API-only scope decision remain | `docs/insurance-v1-acceptance-qa.md`; frontend estimate-slice checks merged in `SaleemCastle/dental-booking` PR #31; all 40 insurance-prefix routes have OpenAPI coverage and generated Postman exports. |

## Boundaries

Manual eligibility, benefits, estimates, claims, authorization decisions, appeals, remittance advice, receipts, and approved obligation proposals are reported observations or workflow records. No report or insurer status represents cash receipt. Financial Settlement v2 posting and allocation, liability transfer, refunds, and reversals are outside Insurance v1 and remain inactive.

## Verification status

Previously recorded focused domain runs passed (27 tests / 148 assertions; claim-response tests 9 / 43 assertions; M6 access-policy tests 4 / 8 assertions). Frontend PR #31 passed 21 focused assertions, 23 Node test files, typecheck, lint, and build; it covers the existing estimate UI only. The final curated local PostgreSQL run passed 126 tests / 1,545 assertions across 17 P4 files; the M5 OpenAPI/Postman exports and all 40 insurance-prefix routes are covered. `tests/Unit/PostmanExportTest.php` passed 12 tests / 680 assertions and `php tools/postman.php --check` passed. PR #70's application-test workflow failed with zero jobs, and no pull-request application workflow ran for PR #73's merge. See the M7 QA report for remaining external release checks. Do not mark M7 complete until hosted PostgreSQL evidence is recorded and the product owner confirms the frontend/API-only scope.
