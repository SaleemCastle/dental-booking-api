# P5 Inventory Feature Coverage

| Milestone | Status | Evidence |
| --- | --- | --- |
| M0 inventory, procurement and financial boundaries audit | Merged | [M0 audit](P5-M0-inventory-procurement-financial-boundaries-audit.md), merged in PR #75 |
| M1 stock catalog and flat locations | In review | Tenant SKU and location code constraints, fixed item base unit, catalog list and creation APIs |
| M1 lots and expiry | In review | Optional item lots with an optional calendar date. A lot belongs to exactly one clinic item and can be stocked at multiple clinic locations. |
| M1 movements and balances | In review | Exact receipt, issue, adjustment and correction deltas. Balance queries sum the same movement history guarded by PostgreSQL. |
| M1 permissions, audit and integrity | In review | [Inventory PostgreSQL tests](../tests/Feature/Api/InventoryPostgresTest.php), [OpenAPI](openapi.yaml), [inventory migration](../database/migrations/2026_11_26_000001_create_stock_inventory.php) |
| M2–M7 | Outside M1 | Supplier directory, purchase orders, procurement receiving, valuation, payables, equipment, reporting and end-to-end acceptance are separate milestones. |

## M1 operations

Use the Sanctum API and active clinic context. Assign `inventory.view`, `inventory.manage_catalog`, `inventory.record_movements` and `inventory.correct_movements` explicitly to clinic roles as needed. Reseed the permission catalog with `php artisan db:seed --class=PermissionSeeder` after applying migrations during rollout. Catalog edits, archival and transfers have no M1 API.

Use `GET` or `POST` under `/api/inventory/items`, `locations`, `lots` and `movements`. Use `GET /api/inventory/balances` to list the ledger balances. A bucket is the combination of item, location and nullable lot. Balances include zero for buckets with history and omit buckets without history. An undated lot and unbatched stock are separate buckets. Expiry is recorded for traceability. Expiry enforcement, quarantine and selection order need separate product rules.

Send a nonzero decimal string with at most 12 integer and six fractional digits in the fixed base unit. Receipts are positive, issues are negative and manual adjustments may use either sign. `POST /api/inventory/movements/{movement}/corrections` appends the exact inverse of an eligible original, once, in the same bucket. A correction that would make the balance negative is rejected. A replacement movement can be recorded separately when needed. Every movement or correction requires a reason and a UUID `Idempotency-Key`. A key belongs to a clinic and item. A replay must use the same actor and normalized payload.

## PostgreSQL rollout and verification

Focused PostgreSQL 16 verification passed 11 tests with 314 assertions. This run covered inventory API behavior, exact arithmetic, correction and replay limits, tenant and permission boundaries, database guards, audit rollback, and the permission catalog. The concurrency case held an open issue transaction while a second PostgreSQL session attempted to issue the same stock. After the first commit, the second issue was rejected and the ledger reconciled to zero. The OpenAPI/Postman exporter tests passed 12 tests with 708 assertions.

The migration introduces four tables, tenant-qualified foreign keys, per-clinic business keys and database guards for fixed identities and immutable history. A movement writes to the owning item row to serialize concurrent changes before checking the bucket sum. PostgreSQL sums exact numeric deltas with no cached stock quantity to repair. The item lock serializes all locations and lots of a single item. Release QA should measure lock contention and history query performance with realistic data volumes.

Apply through the normal PostgreSQL migration flow. Pause inventory writers for rollback. Rollback is available before recording movements. Preserve populated ledgers and use a forward migration afterward. Production roles with schema-owner privileges can change database guards and should be restricted according to the deployment model.

Inventory workflows use their own catalog and ledger. Treatment services, invoices, payments and clinical workflows keep their existing behavior. Automatic depletion and financial posting belong to separate milestones.
