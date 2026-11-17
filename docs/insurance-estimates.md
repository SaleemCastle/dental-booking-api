# Provisional insurance estimates

Coverage estimates are informational snapshots, not payment commitments, insurer acknowledgements, receivables, or changes to a patient balance. They never alter invoices, invoice lines, payments, claims, or benefit usage.

## Estimate a treatment-plan item

Authorized staff can request an estimate from the existing patient treatment-plan item view. The request uses the item's saved treatment description and estimated price. Staff must explicitly enter the service date and three-letter currency. A procedure code is not inferred because the treatment catalog does not provide an authoritative mapping. An insurance association and benefit observation are optional; without a selected benefit the saved snapshot is unresolved.

The backend endpoint is `POST /api/treatment-plans/{treatment_plan}/items/{item}/insurance-estimates`. It requires `patients.view`, `treatment_plans.view`, and `insurance_estimates.create`. Only `association_id`, `benefit_rule_id`, `service_date`, and `currency` are accepted. Estimate history can be filtered by `treatment_plan_id` on `GET /api/patients/{patient}/insurance-estimates`; this also requires `treatment_plans.view`. Add `latest_only=true` to return only each plan item's newest snapshot for a preview.

Snapshots pin the source item ID, amount, description, date, currency, selected benefit source, calculation, actor, and time. The current response marks an estimate superseded when a later estimate references it, and marks it stale if the treatment source changed or its benefit observation acquired a successor. Neither condition rewrites the stored calculation. Staff must explicitly create a new snapshot to refresh an estimate.

The existing invoice draft preview displays linked coverage estimates for selected plan lines. Different currencies, stale or superseded snapshots, and unresolved reason codes remain visible. Estimates are never included in invoice write payloads.

