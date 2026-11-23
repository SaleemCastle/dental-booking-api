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
| P4-M6 | Aggregate insurance reporting, access controls and read audit | Merged in PR #70; runtime API tests remain unverified | `docs/insurance-reporting.md`; `InsuranceReportingApiTest`; exact `insurance_reports.view` plus `patients.view`; aggregate-only and audited |
| P4-M7 | End-to-end acceptance and PostgreSQL release evidence | Incomplete; release QA required | `docs/insurance-v1-acceptance-qa.md`; no application-test job completed for PR #70, and local PHP lacks `pdo_pgsql` and installed Composer dependencies. |

## Boundaries

Manual eligibility, benefits, estimates, claims, authorization decisions, appeals, remittance advice, receipts, and approved obligation proposals are reported observations or workflow records. No report or insurer status represents cash receipt. Financial Settlement v2 posting and allocation, liability transfer, refunds, and reversals are outside Insurance v1 and remain inactive.

## Verification status

Previously recorded focused domain runs passed (27 tests / 148 assertions; claim-response tests 9 / 43 assertions; M6 access-policy unit tests 4 / 8 assertions). These are prior evidence and were not rerun during M7. M6 API feature tests have not reached assertions: the local PHP build lacks `pdo_pgsql`, the applicable migrations intentionally reject SQLite, and this checkout has no `vendor` dependencies. PR #70's application-test workflow run failed with zero jobs; the separate pull-request metadata workflow passed but did not execute application tests. See the M7 QA report for release checks and links. Do not mark M7 complete until PostgreSQL acceptance evidence is green and reviewed.
