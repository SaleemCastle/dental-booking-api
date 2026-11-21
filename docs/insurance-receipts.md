# P4-M5-B insurer receipt foundation

This milestone records manual insurer receipt evidence and a separate immutable
staff verification observation. The word *verified* means staff recorded that
the amount/currency matched cited evidence. It does not mean an insurer API or
bank has been queried by this system. M5-A remittance advice remains an advice
record and is never proof of payment.

Receipt evidence is isolated from the canonical `payments` ledger. A receipt is
not an invoice payment, allocation, receivable, patient credit, or settlement.
No posting, allocation, reversal, or patient responsibility transfer endpoint
is implemented. Existing `BillingWorkflow::recordPayment` remains the only
manual invoice payment path and enforces the existing settled payment
semantics.

## Unresolved approved architecture

The repository does not define an insurer-responsibility amount on invoices or
receivables, nor rules for eligibility, partial allocations, multiple claims
per invoice, currency/date application, overpayment, reversals, or transferring
unpaid insurer amounts to a patient. M5-A advice and claim approval amounts are
informational; they cannot establish these values. The missing ADR-001 source
and a milestone decision leave financial authority unapproved. Therefore this
change deliberately stops at a nonposting receipt register and verification
history. No allocation or reversal records are created until approved rules
exist; the existing payment ledger is not extended or duplicated.

## Security and integrity

Exact `insurance_receipts.view`, `.record`, and `.verify` permissions plus
active tenant membership are required. Permissions are not auto-granted to
administrators. Receipt and verification records carry tenant IDs, recorder,
timestamps, provenance and exact decimal strings. PostgreSQL unique indexes
enforce idempotency and external-reference duplicate prevention. Triggers
enforce immutable records, actor membership and advice/insurer/currency
consistency. Verification must match receipt amount and currency. Reads and
writes are audited; API responses are private/no-store.

## Endpoints

- `GET /api/insurance/receipts` (paginated list)
- `POST /api/insurance/receipts` (manual nonposting receipt evidence)
- `GET /api/insurance/receipts/{receipt}` (receipt and verification)
- `POST /api/insurance/receipts/{receipt}/verification` (append verification)

## Deferred decisions and release QA

Before financial allocation work, approve the insurer-obligation source,
invoice-to-obligation relationship, allocation eligibility and ordering,
currency rules, reversal/correction semantics, and whether/how liability may
ever move to a patient. Do not infer these from remittance advice or approved
claim amounts. PostgreSQL-only FK, trigger, uniqueness/concurrency, immutability
and rollback behavior was reviewed statically and remains unverified until
release QA runs against the approved PostgreSQL environment.

## Focused validation

- `InsurerReceiptDomainTest`: 2 tests, 17 assertions passed for decimal normalization, verification match rules, and exact permission/membership decisions.
- `PostmanExportTest`: 12 tests, 664 assertions passed. `php tools/postman.php --check` passed.
- PHP lint and Pint passed for changed PHP files. `git diff --check` passed.
- Coverage could not be measured because the available PHP runtime has no Xdebug or PCOV driver. The focused domain test exercises `InsurerReceiptRules` and `InsurerReceiptAccessPolicy`; changed application-code line coverage was not measured.
- No PostgreSQL or broader integration/regression tests were run. See deferred database guarantees above.
