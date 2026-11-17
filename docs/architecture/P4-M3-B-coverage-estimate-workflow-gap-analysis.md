# P4-M3-B Coverage Estimate Workflow Integration

## Existing foundations

- ADR-001 keeps invoice line amounts and payments as posted financial facts; provisional benefit estimates remain informational and cannot change invoice balances or create receivables.
- ADR-002 protects insurance evidence and sensitive provenance. This integration does not expose or duplicate evidence.
- ADR-003 and M3-A provide immutable estimate snapshots, exact-decimal calculations, explicitly selected benefit rules, unresolved reason codes, audit records, and estimate-specific permissions.
- Treatment plans already snapshot catalog treatment names and prices. Their items do not define a currency, service date, or procedure code.
- Invoice creation copies treatment-plan item values into gross invoice lines. Draft invoice preview is separate from insurance estimates.

## Gaps

There is no treatment-item reference on an estimate, no treatment-plan estimate endpoint, no estimate freshness/supersession indicators in the response, and no provisional coverage summary in the existing invoice form. The estimator must not infer missing currency/date/code or select benefits. A patient's association may identify a provider only; benefit observations remain optional.

## Minimum safe integration

Add an optional tenant-qualified treatment-plan-item link to immutable estimates. A treatment-specific request derives its description and gross amount from the selected item, and requires explicit service date and currency. Procedure code remains null because the treatment catalog has no authoritative mapping. The existing M3-A workflow/calculator remains the sole calculation engine. Estimate listing can be filtered to a treatment plan for the existing billing preview UI. Read responses can flag a child superseding snapshot and compare the stored treatment facts with current source facts; rule revisions are flagged without refreshing any snapshot.

The UI shows provisional responsibility beside gross cost only for selected treatment-plan lines, with unresolved/stale/superseded explanations. It does not submit estimate values with invoice writes or present them as payments. Uninsured and provider-only association workflows remain valid.

## Boundaries and deferred work

No invoice/payment/claim/receivable/benefit-usage writes, insurer integrations, authorization, adjudication, remittance, or settlement are included. No automatic procedure mapping, currency inference, benefit selection/stacking, estimate refresh, or policy reconstruction is allowed. Currency and date remain explicit inputs pending authoritative source fields in treatment planning.

