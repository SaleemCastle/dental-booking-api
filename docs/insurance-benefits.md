# P4-M2-B — Insurance benefit rules and limits

Base: `origin/main` at `ae7a920` (freshly fetched), including M1-A, M1-B, M1-C and M2-A. Branch: `p4/m2-b-insurance-benefit-rules`. The original worktree's staged and unstaged changes are preserved. No commit, push, merge or deployment is authorized. P4-M3 is outside this change.

## Gap analysis and architecture decisions

| Existing capability | Reuse / remaining gap addressed here |
|---|---|
| Tenant insurers, plans, draft/reviewed/published/withdrawn plan versions | Reuse the existing catalog. Structured benefit rules were absent. Attach new rules to draft versions; freeze the complete rule history on review. |
| Editor tracking and separate catalog review/publication | Every rule insertion also increments the catalog `lock_version` and tracks its actor as an editor in a database trigger. Rule authors cannot review/publish their own version, including raw insert paths. |
| Optional patient insurer associations, immutable provider/policy revisions | Reuse associations, pin the current provider revision, optionally pin an exact same-association policy revision. No policy, insurer catalog entry, or published plan is required for patient observations. |
| M2-A manual eligibility observations | Retain the existing eligibility API and outcome semantics. Eligibility is not a benefit rule or insurer confirmation. Benefits have their own append-only records rather than expanding the existing check contract. |
| Treatments with category and local prices; treatment-plan item price/name snapshots | No authoritative insurer procedure-code mapping exists. Store explicitly supplied category labels or procedure code plus code-system name. Do not infer mappings, rates, coverage, or insurer limits from local prices. |
| Tenant context/scopes, patient view policy, exact insurance permissions, audit service | Reuse these in direct workflows as well as HTTP. Parent ownership is reloaded from tenant-scoped queries; same-patient association checks apply to every patient endpoint. |
| M1-C document protection, retention/hold/quarantine/purge fences | No new document links, upload, confirmation or disclosure capabilities. Both activation flags stay false. Unknown evidence fields are rejected. |
| Standard API envelope and deterministic Postman generator | Add a separate OpenAPI contract and generated collection/environment; retain all existing patient, eligibility and billing contracts. |

Reviewed ADR-001, ADR-002 and ADR-003 in the original audit worktree. They are architecture proposals, not silently approved deployment policies. This implementation applies their separation of reported benefits, eligibility, insurer confirmation and actual financial settlement; their protected evidence boundary; and their immutable source/version/actor history. It does not implement their future evaluator, estimates, claims, authorizations ledger or settlement model.

Decision: use one additive `insurance_benefit_rules` table, with exclusive plan-version or patient-association ownership. Source is explicitly `insurer_plan`, `patient_reported`, or `staff_observation`. These indicate the reported origin, not authenticated insurer confirmation. Every response has `confirmation: unconfirmed`; there is no confirmation command.

Each record contains one target (`all`, `category`, `procedure`), coverage (`covered`, `excluded`, `unknown`, `unspecified`), and benefit type (`percentage`, `fixed`, `unknown`, `unspecified`). No benefit data is seeded. A manual record can contain only explicitly unknown facts. Nullable amounts/limits/waiting/authorization/date scope remain unspecified; zero and false remain explicit facts. Excluded/unknown/unspecified coverage cannot carry an asserted percentage/fixed payout.

Annual, per-visit and procedure limits are reported monetary caps. Procedure limits require a procedure target. Currency is mandatory for every monetary value, must match a plan's currency, and is never converted. Waiting periods are reported integer days; no waiting-start date is inferred. Nullable authorization requirements do not mean approval was obtained. Annual benefit-year anchors, family/usage allocation and limit consumption are deliberately not inferred. Effective service dates are inclusive source scope, independent of observed and recorded timestamps; absent bounds mean unspecified scope, not perpetual eligibility.

Records are never updated or deleted. `supersedes_id` explicitly corrects a prior record of the same parent with a complete replacement payload and required reason. The predecessor survives; revisions increase by one and each predecessor has at most one successor. Corrections can explicitly replace a mistaken rule with unknown/unspecified facts. Independent rules may coexist, including conflicting source observations; list order does not choose a coverage winner. A published plan needs a new plan version for changed benefits. There is no implicit copying or recalculation.

## Migration and integrity

`2026_11_16_000001_create_insurance_benefit_rules.php` is PostgreSQL-only, additive, with no data backfill. It creates:

- `insurance_benefit_rules`: typed targets, coverage/benefit values, monetary limits, waiting/authorization facts, inclusive optional service dates, source timestamp, server record timestamp, actor, encrypted private details and correction lineage.
- Tenant-qualified FKs to plan versions, associations, provider snapshots, policy revisions and predecessor records; unique predecessor and tenant/ID keys; tenant/parent/ID browse indexes.
- Insert guards for staff membership, current provider snapshot, same-parent correction and revision number, active draft status at the workflow boundary, draft status and matching currency at the database boundary.
- Plan editor attribution and `lock_version` increment in the insert transaction, serialized on the version row against review/publication. Workflow locks use the existing insurer → plan → version order; patient writes lock patient → association.
- Database rejection of all benefit UPDATE/DELETE/TRUNCATE operations. A currency guard prevents changing a plan's currency to conflict with any recorded monetary benefit. The catalog workflow returns 422 for this condition.

Database timestamps use UTC. Encrypted `provenance`, `reference` and `reason` use the existing application encryption key and are hidden from model serialization. Monetary columns use `numeric(14,2)`; requests require decimal strings with at most twelve integer digits and two fractional digits. Percentage is a string in the inclusive range 0–100 with at most two fractional digits. Returned numeric values have exactly two decimal places.

`down()` refuses any populated benefit table. Empty rollback removes the currency trigger, three functions and the table without changing existing catalog, patient, evidence or financial data. Never drop populated history to roll back. No dependencies or configuration values change.

## API contract

All routes use Sanctum, tenant middleware, the existing response envelope, and `Cache-Control: private, no-store` on successful responses.

| Method / suffix | Behavior / exact permission |
|---|---|
| GET `/api/insurance/plan-versions/{version}/benefits` | Paginated full history; `insurance_catalog.view` |
| POST same | Record rule or correction; view + `insurance_catalog.manage`; draft active plan; current `expected_version` |
| GET same + `/{benefit}/details` | Audited private provenance/reference/reason; view + manage |
| GET `/api/patients/{patient}/insurance-associations/{association}/benefits` | Paginated full history; `patient_insurance.view` and existing patient view policy |
| POST same | Record patient report or observation; also `patient_insurance.manage` |
| GET same + `/{benefit}/details` | Audited private details; also `patient_insurance.reveal` |

No new permission is auto-granted. Wildcard insurance permissions alone cannot bypass the exact capability checks. Existing patient-policy administrator behavior remains unchanged, but an administrator still needs exact insurance permissions. Platform access alone grants no clinical data access.

Required POST fields: `source`, `scope`, `coverage`, `benefit_type`, offset-qualified nonfuture `observed_at`, nonblank `provenance`, and nonblank `reason`. Plan requests additionally require `expected_version`; patient requests optionally accept `policy_revision_id`. Optional fields: target fields, `percentage`, `amount`, `currency`, `annual_limit`, `visit_limit`, `procedure_limit`, `waiting_period_days`, `prior_authorization_required`, `service_from`, `service_to`, `reference`, `supersedes_id`. Every unrecognized field is rejected. Full input validation also applies to direct workflow calls.

POST returns 201 with `data.benefit`. Lists return `data` array and `meta.pagination`; `page >= 1`, `per_page` 1–100, default 15. Private details are returned only in `data.details`. Foreign/mismatched parents, benefit IDs, predecessor IDs and policy revisions return 404. Missing authority gives 403; stale plan versions/frozen versions/inactive catalog or competing corrections give 409; invalid/contradictory input gives 422. Audit unavailability fails access closed; mutations and plan editor changes roll back together.

The existing plan-version resource retains its shape; `benefits_configured` now reports whether this version has recorded benefit history. This flag says nothing about confirmation, completeness or calculated coverage. Every plan rule write increments the existing `lock_version`; callers must reload the version before subsequent edits, review or publication. Existing versions with no benefits still report false.

Example provider-only unknown observation (synthetic data):

```json
{
  "source": "staff_observation",
  "scope": "all",
  "coverage": "unknown",
  "benefit_type": "unknown",
  "observed_at": "2020-01-02T15:00:00Z",
  "provenance": "Staff has not obtained benefit details",
  "reason": "Record explicitly unknown benefits"
}
```

OpenAPI is the source of truth for the additive export. Regenerate or check with:

```text
php tools/insurance-benefits-postman.php --write
php tools/insurance-benefits-postman.php --check
```

Import the generated collection and environment, set authentication, tenant and specific parent IDs deliberately. Writes default to disabled (`allow_writes=false`). These exports are request catalogs, not an ordered automated production scenario. Plan writes require the operator's current version. No workspace publication is performed.

## Security and backward compatibility

Sensitive reads, detail reveals and changes require a successful safe-metadata audit. The audit stores actor/action/resource/time and route templates, never benefit content or correction text. Plan benefit exceptions join the existing insurance redaction boundary to prevent SQL bindings/private content appearing in exception logs. Source notes and references cannot activate evidence uploads, release holds, bypass quarantine, or establish insurer confirmation.

The new endpoints do not change patient registration, appointments, check-in, treatments, insurer associations, optional partial policies or manual eligibility. Uninsured patients remain supported. Published plan versions and all previous benefit records stay immutable. Actor history survives later membership removal. Existing application-key protection, backup and restore requirements also protect the new encrypted text.

No insurance payment, invoice mutation, receivable, deduction, claim, remittance, estimate, financial usage reservation or external insurer request is created. Existing invoices/items/payments are compared byte-for-byte in focused tests after benefit recording. No evidence flags, retention policies, document streams or purge code change.

## Verification and merge review

Final verification: **46 tests, 686 assertions, zero failures/errors/skips**, PostgreSQL 16 (disposable `postgres:16-alpine` container), PHP 8.2.0, PHPUnit 10.5.58. PHPUnit wall time: **109.011 seconds** (JUnit sum 108.883822 seconds). The database was bound to localhost and removed with its test-only volume after verification.

| Focused class | Cases | Assertions |
|---|---:|---:|
| InsuranceBenefitRulesTest | 11 | 183 |
| InsuranceBenefitContractTest | 1 | 19 |
| InsuranceBenefitRulesPostgresTest | 3 | 15 |
| ManualInsuranceEligibilityTest (existing) | 13 | 234 |
| InsuranceCatalogApiTest (existing) | 15 | 164 |
| InsuranceCatalogContractTest (existing) | 1 | 6 |
| PatientInsuranceContractTest (existing) | 1 | 34 |
| InsuranceEvidenceContractTest (existing) | 1 | 31 |

Actual final invocation used a local ignored configuration listing exactly those eight files:

```text
php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit -c storage/logs/benefits.phpunit.xml --do-not-cache-result --log-junit storage/logs/benefit-final.xml
```

Process environment explicitly set `DB_CONNECTION=pgsql`, localhost host/port and a dedicated disposable database, with a synthetic test application key; SQLite defaults were never used. RefreshDatabase recreated the full schema; the independent-connection cases used dedicated randomly named schemas. No full regression, SQLite, MySQL or frontend suite ran.

Coverage includes percentages/fixed amounts, all coverage states, every limit type, nullable facts, procedure/category/all targets, waiting and authorization values, invalid/financial/evidence input rejection, provider-only and partial policies, explicit correction history, encrypted details and audited reads, exact permissions/wildcard denial, patient/tenant boundaries, plan editor attribution, reviewed/published immutability, stale versions, currency protection, empty rollback and populated rollback refusal, and unchanged existing invoice/payment rows. Independent PDO connections verify rule-write/review/publication serialization and one-successor correction races. Existing M2-A cases verify retained/quarantined evidence and the disabled activation flags. Existing M1-C purge races were not repeated because no evidence/purge implementation changed.

Earlier verification runs: initial 9-case benefit suite passed 151 assertions in 102.840 seconds; expanded 30-case run took 100.216 seconds with one stale catalog contract inventory failure. That existing test was extended without removing its original assertions; the final 46-case run above passed in one invocation. Total PHPUnit wall time across these three invocations: 312.067 seconds. Do not add assertions from overlapping runs as distinct coverage.

PHP syntax validation passes for all 16 changed/new PHP files; targeted Pint passes; `git diff --check` passes. Deterministic benefit, catalog, patient-insurance and evidence OpenAPI/Postman export checks all pass. Postman CLI 1.83.1 reports no initialized workspace/session (and an EACCES skills-repository status probe); no setup, login, remote publication or live collection execution was needed or performed. Local contract tests check exact route coverage, middleware, export reproducibility, disabled writes and valid generated POST payloads.

**Recommendation: SAFE TO MERGE after code review for this bounded recording feature.** This is not deployment or evidence-activation approval. Apply the additive migration before activating the new code; preserve the existing encryption key and history. No commit, push, merge or deployment was performed.

Remaining limits: staff provenance is not authenticated by this feature; source contradictions are preserved for human review; no precedence resolver, benefit evaluation, estimate, service-code mapping, benefit consumption, current-entitlement projection or insurer integration is implemented. Those are later scoped work, including P4-M3. Structured evidence attachment/confirmation remains behind M1-C and later approved workflows. Annual period interpretation and usage at other clinics must remain unknown until explicitly supplied and supported by a future calculation contract.

## Exact changed-file inventory

- `.gitignore`
- `app/Exceptions/Handler.php`
- `app/Http/Controllers/Api/InsuranceBenefitController.php`
- `app/Http/Resources/InsuranceBenefitRuleResource.php`
- `app/Http/Resources/InsurancePlanVersionResource.php`
- `app/Insurance/BenefitInput.php`
- `app/Models/InsuranceBenefitRule.php`
- `app/Services/InsuranceBenefitWorkflow.php`
- `app/Services/InsuranceCatalogWorkflow.php`
- `database/migrations/2026_11_16_000001_create_insurance_benefit_rules.php`
- `docs/insurance-benefits.md`
- `docs/insurance-benefits.openapi.json`
- `docs/postman/insurance-benefits.postman_collection.json`
- `docs/postman/insurance-benefits.postman_environment.json`
- `routes/api.php`
- `tests/Feature/Api/InsuranceBenefitContractTest.php`
- `tests/Feature/Api/InsuranceBenefitRulesTest.php`
- `tests/Feature/Api/InsuranceCatalogApiTest.php`
- `tests/Feature/Api/InsuranceCatalogContractTest.php`
- `tests/Feature/InsuranceBenefitRulesPostgresTest.php`
- `tools/insurance-benefits-postman.php`

Ignored runtime artifacts (not deliverable changes): copied existing vendor dependencies; local PHPUnit configuration and JUnit logs in `storage/logs`; disposable localhost PostgreSQL test container.

The existing catalog tests retain their original assertions. The migration roundtrip now unwinds/reinstalls the new benefit FK dependency in order. The namespace contract test retains its 17 catalog-route assertion and adds the evidence/benefit contracts to check all 27 `/api/insurance/*` routes with exact equality. No existing test is removed or relaxed.
