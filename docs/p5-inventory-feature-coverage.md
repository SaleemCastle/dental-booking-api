# P5 Inventory Feature Coverage

| Milestone | Status | Evidence |
| --- | --- | --- |
| M0 inventory, procurement and financial boundaries audit | Merged | [M0 audit](P5-M0-inventory-procurement-financial-boundaries-audit.md), merged in PR #75 |
| M1 stock catalog and flat locations | Merged | Tenant SKU and location code constraints, fixed item base unit, catalog list and creation APIs |
| M1 lots and expiry | Merged | Optional item lots and dates. The expiry guard merged in PR #77. |
| M1 movements and balances | Merged | Exact receipt, issue, adjustment and correction deltas. Balance queries sum the same movement history guarded by PostgreSQL. |
| M1 permissions, audit and integrity | Merged | [Inventory PostgreSQL tests](../tests/Feature/Api/InventoryPostgresTest.php), [OpenAPI](openapi.yaml), [inventory migration](../database/migrations/2026_11_26_000001_create_stock_inventory.php) |
| M2 supplier directory and draft purchase orders | Merged | PR #78, [Procurement PostgreSQL tests](../tests/Feature/Api/ProcurementPostgresTest.php), [procurement migration](../database/migrations/2026_11_26_000003_create_procurement_drafts.php) |
| M3 direct supplier receiving with lot and expiry | Merged | PR #79, [Receiving PostgreSQL tests](../tests/Feature/Api/SupplierReceivingPostgresTest.php), [receipt migration](../database/migrations/2026_11_26_000004_create_supplier_receipts.php) |
| Purchase order matching and stock valuation | Deferred | M3 records physical quantities directly from suppliers. |
| M4 supplier invoice register | Merged | PR #80, [invoice register PostgreSQL tests](../tests/Feature/Api/SupplierInvoicePostgresTest.php), [invoice register migration](../database/migrations/2026_11_26_000005_create_supplier_invoice_register.php) |
| Supplier payables and payments | Deferred | Invoice totals and due dates are recorded as supplier observations. |
| M5 equipment registry, maintenance and warranties | In review | [Equipment PostgreSQL tests](../tests/Feature/Api/EquipmentPostgresTest.php), [OpenAPI](openapi.yaml), [equipment migration](../database/migrations/2026_11_26_000006_create_equipment_registry.php) |
| M6–M7 | Later milestones | Reporting, alerts and end-to-end acceptance. |

## M1 operations

Use the Sanctum API and active clinic context. Assign `inventory.view`, `inventory.manage_catalog`, `inventory.record_movements` and `inventory.correct_movements` explicitly to clinic roles as needed. Reseed the permission catalog with `php artisan db:seed --class=PermissionSeeder` after applying migrations during rollout. Catalog edits, archival and transfers have no M1 API.

Use `GET` or `POST` under `/api/inventory/items`, `locations`, `lots` and `movements`. Use `GET /api/inventory/balances` to list physical ledger balances. A bucket is the combination of item, location and nullable lot. Balances include zero for buckets with history and omit buckets without history. An undated lot and unbatched stock are separate buckets. A dated lot can be issued through its expiration date in UTC. PostgreSQL rejects later issues. Receipts, adjustments and corrections remain available to account for expired physical stock and retain its history.

Send a nonzero decimal string with at most 12 integer and six fractional digits in the fixed base unit. Receipts are positive, issues are negative and manual adjustments may use either sign. `POST /api/inventory/movements/{movement}/corrections` appends the exact inverse of an eligible original, once, in the same bucket. A correction that would make the balance negative is rejected. A replacement movement can be recorded separately when needed. Every movement or correction requires a reason and a UUID `Idempotency-Key`. A key belongs to a clinic and item. A replay must use the same actor and normalized payload.

## PostgreSQL rollout and verification

Focused PostgreSQL 16 verification passed 11 tests with 314 assertions. This run covered inventory API behavior, exact arithmetic, correction and replay limits, tenant and permission boundaries, database guards, audit rollback, and the permission catalog. The concurrency case held an open issue transaction while a second PostgreSQL session attempted to issue the same stock. After the first commit, the second issue was rejected and the ledger reconciled to zero. The OpenAPI/Postman exporter tests passed 12 tests with 708 assertions.

After adding the expiry guard, all seven inventory PostgreSQL tests passed with 146 assertions, including expired-lot rejection through the API and direct database writes, valid lot issues, physical adjustments, and the concurrent stock issue. The same OpenAPI/Postman tests passed with 708 assertions.

The migration introduces four tables, tenant-qualified foreign keys, per-clinic business keys and database guards for fixed identities and immutable history. A movement writes to the owning item row to serialize concurrent changes before checking the bucket sum. PostgreSQL sums exact numeric deltas with no cached stock quantity to repair. The item lock serializes all locations and lots of a single item. Release QA should measure lock contention and history query performance with realistic data volumes.

Apply through the normal PostgreSQL migration flow. Pause inventory writers for rollback. Rollback is available before recording movements. Preserve populated ledgers and use a forward migration afterward. Production roles with schema-owner privileges can change database guards and should be restricted according to the deployment model.

Inventory workflows use their own catalog and ledger. Treatment services, invoices, payments and clinical workflows keep their existing behavior. Automatic depletion and financial posting belong to separate milestones.

## M2 supplier directory and drafts

The supplier directory stores a clinic-unique code, name, optional email and phone, and active state. Staff can edit contact details or deactivate a supplier. Historical drafts retain the supplier relationship. A new draft requires an active supplier and one to 100 distinct stock items, each with a positive decimal quantity in the fixed base unit. Drafts contain quantities and optional notes. The persisted order state is `draft`.

Use `/api/procurement/suppliers` and `/api/procurement/purchase-orders` under Sanctum and the selected clinic. Assign `suppliers.view`, `suppliers.manage`, `purchase_orders.view`, and `purchase_orders.manage` explicitly to clinic roles after reseeding permissions. Draft creation requires a clinic-scoped UUID idempotency key. The same author and normalized create payload return the current draft on replay. Replacing a draft requires its current version and atomically replaces the lines and notes while retaining its original supplier.

PostgreSQL enforces tenant-qualified supplier, order and stock item relationships, exact line quantities, unique supplier codes and unique command keys. Supplier writes and draft edits include an audit entry in the same transaction. Order approval, supplier dispatch, stock receiving, unit prices, tax, payable balances and payments are outside M2. Roll back the procurement migration only when no supplier or order data has been recorded.

Focused PostgreSQL API and permission checks passed 8 tests with 256 assertions. They covered draft creation and replay, stale versions, same-clinic relationships, wildcard permission denial, input validation, audit rollback and the stock and billing boundaries. OpenAPI/Postman checks passed 12 tests with 729 assertions.

## M3 physical goods receiving

Use `GET` and `POST /api/procurement/supplier-receipts` and `GET /api/procurement/supplier-receipts/{supplier_receipt}`. Reseed the permission catalog and explicitly grant `supplier_receipts.view` or `supplier_receipts.record`. Receiving authority can record supplier deliveries without manual inventory adjustment authority. Record from an active supplier using a reference unique for that supplier and a physical receipt date through today UTC. The UUID idempotency key is clinic scoped and replays require the same actor and normalized request.

Each line records a positive decimal quantity in the item's fixed base unit and names an existing clinic location. Where lot tracking applies, register the item's lot and expiry in the inventory catalog before receiving against it. The detail response includes original movement IDs, lot codes and expiry dates. Physical arrivals can include expired lots, and M1 continues to block issues from expired lots. Existing inventory correction authority can append a compensating stock movement against an original receipt movement.

Receipt headers and their linked movements commit with their audit entries in one PostgreSQL transaction. The database seals movement membership after commit and requires at least one movement per receipt. Balance queries sum the same stock ledger. All line writes acquire item locks in a consistent order. Apply the additive migration before enabling the endpoints and roll it back only while the receipt register is empty. Receipt recording leaves purchase orders, invoices, payments and payable balances unchanged.

The focused receiving, inventory and permission run passed 16 PostgreSQL tests with 391 assertions. It exercised expiry enforcement, balance reconciliation, duplicate and foreign clinic rejection, audit rollback, sealing after commit and the existing concurrent issue invariant. The OpenAPI/Postman run passed 12 tests with 739 assertions.

## M4 supplier invoice register

Use `GET` and `POST /api/procurement/supplier-invoices` and `GET /api/procurement/supplier-invoices/{supplier_invoice}`. Reseed permissions and explicitly grant `supplier_invoices.view` or `supplier_invoices.record` in each clinic. The register records a supplier reference, invoice date, optional reported due date, uppercase currency label, exact nonnegative reported total and recorder. An optional receipt link provides provenance and must reference the same clinic and supplier. Historical invoices can be recorded from an inactive supplier already in the clinic directory.

The workflow preserves the invoice number's original case and detects case-insensitive duplicates per supplier and clinic. A clinic-scoped UUID idempotency key replays the same normalized observations for the original recorder. PostgreSQL enforces receipt lineage, exact decimals and append-only history. Recording commits with its audit event. Payable recognition, payments, matching, credit notes and corrections to recorded observations are outside this scope. Use a forward migration after invoice history exists.

Focused supplier invoice, receiving and permission checks passed 13 PostgreSQL tests with 313 assertions. They covered exact totals, duplicate and replay rules, foreign clinic and wrong supplier links, immutable records, audit rollback and stock and billing boundaries. OpenAPI/Postman checks passed 12 tests with 748 assertions.

## M5 equipment registry

Use `GET` and `POST /api/equipment` and `GET` or `PATCH /api/equipment/{equipment}`. Reseed the permission catalog and explicitly grant `equipment.view`, `equipment.manage` or `equipment.record_history` in the clinic. The registry assigns a clinic-unique fixed asset tag. Name, serial number and a free-text location label can be changed with the current record version. New assets start active. Staff may mark an asset out of service, return it to active use, or retire it. Retirement is terminal. The status records the clinic's observation and does not change appointment availability.

Use `GET` and `POST` on the `maintenance` and `warranties` child paths. Maintenance records describe completed service and may include an informational next due date. A warranty records its provider and an ordered start and end date, with an optional reference and details. Entries may be added for retired assets to preserve historical documentation. Each history write uses a clinic-scoped UUID for idempotency and commits with its audit event. Maintenance and warranty entries are append-only.

The PostgreSQL migration adds equipment and history tables with tenant-qualified equipment relationships, recorder validation, unique asset tags, status and version guards, and immutable history. Roll back only while the register is empty. Maintenance scheduling, reminders, warranty claims, depreciation and financial posting are outside this milestone.

Focused equipment and permission checks passed 9 PostgreSQL tests with 249 assertions. They covered clinic isolation, exact permissions, stale versions, retirement, date ordering, idempotent and immutable history, audit rollback and separation from stock and billing. OpenAPI/Postman checks passed 12 tests with 768 assertions.
