# P4-M5-B2 insurer obligation foundation

This milestone records a manually proposed insurer obligation against a line
on an already issued invoice and permits a separately authorized finance user
to approve that exact amount. A proposal or approval is an explicit staff
financial decision with provenance; it is not inferred from an estimate, claim
approval, insurer response, remittance advice, or receipt.

## What is implemented

- Tenant-qualified insurer obligation proposal records linked to patient,
  insurer association, issued invoice and invoice line.
- Optional claim, immutable submission, approved response context and protected
  evidence references.
- Positive exact two-decimal amount validation, currency match, and cap at the
  invoice-line gross charge.
- Immutable proposal and approval records; approval copies the proposed amount
  unchanged. Approval is idempotent by UUID command key.
- Exact `insurance_obligations.view`, `.propose`, and `.approve` permissions,
  plus invoice and patient read permissions. Tenant membership and model Gates
  are checked for each patient/invoice read. Actions and replay are audited.
- APIs: list, propose, retrieve, approve.

Approval history is append-only and returned as version 1 (proposal) and
version 2 (approval). It does not mutate the invoice, invoice line, payment
ledger, receivable, or patient balance.

## Responsibility assignments are blocked

The current payment model records payments only against an invoice and subtracts
them from gross `amount_due`; it has no payer attribution, insurer-specific
outstanding cap, or line-level payment allocations. Exposing patient-versus-
insurer responsibility assignments now would imply balance semantics that the
existing ledger cannot enforce. Therefore this milestone does not create
responsibility assignment records or the corresponding assignment read API.

Before that portion can be implemented, architecture must define payer
attribution in the payment ledger, eligible insurer responsibility and its
relationship to invoice lines, how partial payments consume each payer's
share, and explicit authorization/rules for any patient transfer. No secondary
insurer coordination is supported. Until then, an approved obligation remains
an immutable informational finance record and never changes patient liability.

## Open decisions and release QA

- ADR-001 financial authority / approval policy is not present in this checkout;
  approve who may propose/approve and what evidence constitutes an obligation.
- Decide whether the proposer and approver must be different staff members.
- Define currency application if multi-currency invoices are enabled in future.
- Decide whether repeated proposals for one invoice line need a business-level
  duplicate rule beyond command idempotency. The database allows multiple
  proposals but only one approval per line and prohibits secondary insurers.
- PostgreSQL trigger, composite foreign key, concurrency/idempotency, immutability
  and rollback behavior has only been statically reviewed; verify these against
  the release PostgreSQL version before production migration.
- This foundation deliberately has no obligation reversal or correction API.

No payments, allocations, balance changes, financial posting, or remittance
reconciliation are implemented.
