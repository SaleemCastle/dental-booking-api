# P4-M3-A Insurance Estimation: Architecture and Gap Analysis

## Minimum safe implementation

This feature is an informational, provisional estimate snapshot. It must not alter invoice items, invoice totals or statuses, Payments, payment links, claims, authorizations, remittances, or benefit usage. Currency calculations use integer minor units and the existing two-decimal `Money` boundary. Insurer and patient amounts are not cash or receivables.

### Existing foundations

- ADR-001 preserves gross invoice charges and positive actual Payments; it rejects benefit estimates recorded as cash and describes estimates as advisory overlays.
- ADR-002 requires tenant-bound, versioned evidence references and protects sensitive insurance evidence and disclosures.
- ADR-003 calls for immutable estimate revisions, pinned benefit versions, explicit unknown states, no implicit rule precedence, and exact provenance. It permits only typed, supported calculation strategies. Estimates do not reserve cash or consume benefit limits.
- Insurance benefit rules are append-only revisions, tied to either a published-plan candidate or a patient association revision. Inputs can be percentage, fixed, excluded, unknown, or unspecified, with optional monetary limits and effective service dates. Confidential provenance/reference/reason values are encrypted and hidden by default.
- Patient insurance associations can identify a provider without policy details. Rules may be staff observations without a published plan. Eligibility checks are dated external observations and do not prove benefits.
- Treatment catalog and plan prices lack a generally authoritative currency/procedure code. Invoice lines have gross price snapshots and currency is on the invoice. No conversion or inferred code is safe.
- Tenant global scopes, composite tenant FKs, patient policies, exact insurance permissions, Sanctum, standard `ApiResponse`, and audit logging are available.

### Gaps and implementation boundary

There is no estimate snapshot resource, calculation trace, estimate-specific permission, supersession lineage, or endpoint. Existing rules do not encode all deductible, coordination-of-benefits, frequency, remaining annual usage, allowed-fee, or limitation-order facts needed for a general insurer determination. Consequently this increment accepts a caller-supplied gross amount/currency and optional treatment description/code, evaluates only one explicitly selected benefit rule, and never auto-matches or stacks rules. Conflicting simultaneously applicable rules from a selected plan and patient observation remain unresolved. Percentage and fixed rules may be applied only when known limits are absent or an explicit procedure cap is available; annual and visit caps without remaining usage are unresolved. Required prior authorization and unresolved waiting periods also remain unknown. Unsupported/unknown facts produce reason codes and null responsibility amounts.

Snapshots pin the selected rule ID/revision, plan version or association/policy revision as provenance, all request facts, calculation version, assumptions, outputs/reasons, actor and timestamp. Creation requires a dedicated `insurance_estimates.create` permission alongside patient-view authorization; retrieval/history requires `insurance_estimates.view`. Creation and reads are audited without copying policy identifiers or encrypted provenance into audit metadata. Explicit selected rule references are tenant and patient-association constrained; uninsured estimates remain supported.

API scope is patient nested create/list plus estimate show, with stable standard response envelopes and no policy-secret disclosure. Superseding appends a relation between immutable estimates. The calculation endpoint stores no financial ledger fact and performs no benefit-limit reservation.

## Review notes

ADR-001 is marked recommended/awaiting architecture approval; ADR-002 and ADR-003 define the applicable evidence and lifecycle constraints. This implementation is a narrow informational foundation and does not activate claims or settlement. It does not infer currency, procedure mappings, payer rules, policy facts, or external integrations.
