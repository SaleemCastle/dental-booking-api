# Phase 2 M0: multi-tenant architecture contract

## Decision and boundary

Use one shared database/schema, with **one tenant representing one clinic**. Clinical records and roles have a non-null `tenant_id`. Users are global identities with globally unique email addresses and many-to-many clinic memberships in `tenant_user`. The permission catalog is a global vocabulary; a permission grant belongs to a clinic-local role, not to the user globally. No tenant creation, signup, invitations, billing, provisioning, or platform-administration API is introduced.

The `admin` system-role slug remains backward compatible and means **Clinic Admin**. `Role::CLINIC_ADMIN` aliases that stable slug. It is not Platform Super Admin and confers no cross-clinic or platform authority. Future platform privileges must use a separate authorization boundary, not an `admin` role, display name, or wildcard permission on a clinic role.

## Schema and migration

Migration `2026_10_21_000001_add_tenant_foundation.php`:

- Creates `tenants` (`id`, `name`, unique `slug`, `active`, timestamps) and the seeded `default-clinic` / Default Clinic record. Never assume its ID is 1.
- Creates unique `(tenant_id, user_id)` memberships and enrolls every existing Phase 1 user in the default clinic, preserving user IDs, credentials, verified state, and tokens.
- Adds tenant ownership to `patient`, `dentist`, `appointment`, `treatments`, `rooms`, `chairs`, `invoices`, `payments`, `messages`, `referral`, `clinical_notes`, `tasks`, `audit_logs`, and `roles`. All existing rows belong to the default clinic, including archived patients, inactive treatments, soft-deleted notes, and audit history. No clinical payload is rewritten or copied into audit metadata.
- Preserves role/permission IDs and pivot links. `role_user.tenant_id` and composite foreign keys require role ownership and user membership to agree. Permission grants remain on `permission_role`, scoped through their owning role. Role slugs, room names, and invoice numbers become clinic-unique rather than globally unique. Dentist email validation is also clinic-local; user emails remain globally unique.
- Indexes `(tenant_id, id)` on owned tables. MySQL enforces tenant foreign keys with restrictive tenant deletion. SQLite testing uses a rebuilt role pivot for composite constraints; Laravel 10 does not add owned-table foreign keys to existing SQLite tables. ORM ownership checks and API isolation apply on both drivers. SQLite rollback requires native DROP COLUMN support (SQLite 3.35+).

The column default is the migrated default-clinic ID for compatibility with legacy imports, not an authorization mechanism. Eloquent creates stamp the server-selected clinic instead. Query-builder inserts, raw SQL, bulk writes that bypass model events, and removing the tenant global scope are privileged maintenance operations, never request-path escape hatches. Future import/job code must provide and validate its clinic context explicitly.

## Request context and frontend contract

1. Authenticate normally using existing Sanctum bearer tokens or browser sessions. Authentication is global; tokens do not confer clinic membership.
2. `GET /api/tenants` returns only the authenticated user's active memberships as `data.tenants`, ordered by ID. This endpoint does not require default-clinic membership and does not enroll the caller anywhere.
3. Clinic endpoints optionally accept `X-Tenant-Id: <positive integer>`. Omitting it selects `default-clinic`, **not** the first membership and not the last clinic visited. A user without that membership gets 403 rather than falling back to another clinic.
4. The resolver checks clinic existence, active status, and current membership before route-model binding and authorization. Unknown, inactive, or nonmember clinics return 403; malformed IDs return 422. Authentication failures remain 401. A foreign clinic's resource ID returns 404. Request payload/query `tenant_id` and `tenantId` are rejected; ownership cannot be mass-assigned.
5. Successful clinic responses include `X-Tenant-Id`. `GET /api/user` additionally returns `data.current_tenant_id`, clinic-local `data.roles`, and flattened clinic-local `data.permissions`. These headers are CORS-exposed alongside `X-Request-Id`.

For example:

```http
GET /api/appointments
Accept: application/json
Authorization: Bearer <token>
X-Tenant-Id: 2
```

Every resource ID, related-record ID, filter, selector, and role assignment is interpreted in this verified clinic. Global permission IDs are the exception: permission definitions are shared, and grants can only be changed on the selected clinic's role. On switching clinics, refetch `/api/user`, replace cached roles/permissions, and clear resource selections, lists, dashboards, and captured Postman IDs. Do not merge authorization state across clinics. A clinic header or ID never substitutes for server-side membership checks.

Health, credential/token issuance and revocation, cookie login/logout, password reset, email verification, and membership discovery are account-level rather than clinic data endpoints. Existing public registration still creates an identity with default-clinic membership only; it does not accept arbitrary clinic enrollment or provision a clinic. This preserves Phase 1 behavior and is not a production SaaS invitation/onboarding design. Review public registration and the legacy controllers' existing permission policies before launching SaaS onboarding.

## Enforcement and global user identities

`BelongsToTenant` adds an ownership query scope, stamps creates, rejects cross-context model writes/deletes and ownership transfers, and checks related clinical ownership on saves. API existence/uniqueness validators use `TenantRules`; an existing record or assignee from another clinic is not a valid relationship. Overlap checks, eager-loaded clinical relationships, lists, dashboard aggregates, booking locations, audit filters, and soft-deleted history inherit the same scope. There is no implicit cross-clinic admin bypass.

`User::roles()` includes the current clinic's pivot value, and role/permission helpers verify membership and reload clinic-local grants rather than reusing another clinic's cached relations. The role-management API cannot select a foreign role, modify a foreign role's grants, assign a foreign role, or target a user outside the clinic. The shared permission catalog does not itself grant access. Existing clinical role restrictions remain: receptionist clinical writes are still denied; audit viewing requires the selected clinic's actual admin/auditor role.

User lists and bindings expose only clinic members. Since identities are global, a clinic member cannot edit another user's shared identity if that user belongs to other clinics; the identity owner may update their own account. Deleting a shared user through `/api/users/{user}` removes only this clinic's roles/membership and records `users/membership_removed`; it preserves the account, other memberships, and other clinics' data. For a single-clinic identity, the previous account-deletion behavior remains. This milestone preserves the Phase 1 user CRUD permission policy otherwise; a more restrictive staff-management policy is a separate change.

Audits are tenant-owned and append-only. Domain actions log to their selected clinic. Global identity updates, password resets, and email verification fan out metadata-only events to that user's memberships rather than logging another clinic's user in the default clinic; an identity without memberships has no clinic audit destination. No clinical notes, passwords, role snapshots, or patient payloads are copied into those events. Future platform/global audit storage remains separate work.

## Models, factories, seeders, and trusted background work

`TenantFactory` creates independent clinics. `UserFactory` gives users membership in the current trusted context for backward-compatible Phase 1 fixtures; `forTenant($tenant)` creates a user belonging only to that clinic. Clinical factories inherit ownership from the context, including their related fixtures. Global user identities created outside `UserWorkflow` must have memberships assigned explicitly.

`TenantSeeder` idempotently ensures the default clinic exists without renaming or reactivating an existing record. `DatabaseSeeder` calls it before role, permission, location, and demo-data seeding. The existing demo admin remains admin only in its clinic. Role, permission-grant, and location seeders operate in the current trusted context and do not copy grants between clinics; permission definitions remain shared.

Trusted code can explicitly enter a clinic context:

```php
app(\App\Tenancy\TenantContext::class)->run($tenant, function () {
    return \App\Models\Patient::factory()->create();
});
```

`run()` restores the prior context in `finally`, including on exceptions. The container binding is scoped, not process-global static state. Workers must establish the intended clinic before loading models or invoking workflows; never infer permission to enter a clinic from client-controlled IDs or assume that a Clinic Admin is a platform operator.

## Existing deployment

Back up the database, review this contract, and use a maintenance window with request/worker writes paused. MySQL schema changes are not transactionally rolled back and adding columns/indexes may lock large tables; rehearse on a restored staging copy before production. Run only normal additive migrations:

```shell
php artisan migrate
```

Do **not** use `migrate:fresh` or rerun the full demo-data seeder on an existing clinic. The migration creates the default clinic, backfills memberships/ownership, and preserves current role/permission grants without requiring seeding. Existing clients may continue omitting the tenant header. If explicit frontend permission grants were never installed, the existing `PermissionSeeder` can still be applied deliberately; that is a separate baseline grant change, not part of migration backfill.

Rollback preserves Phase 1 IDs and data when only the original default clinic exists. It refuses to remove the isolation boundary after additional clinics have been created; do not drop tenant columns or merge clinics to bypass that guard. Reconcile/export multi-clinic data and plan a separate recovery migration instead.

## Validation

`TenantFoundationTest` covers default behavior, membership discovery, malformed/unauthorized/inactive clinic selection, role/permission isolation on reused user objects, lists/bindings across clinical domains, dashboard/audit isolation, cross-clinic relationship validation, duplicate per-clinic slugs/locations, stale model writes, composite pivot constraints, shared identities, and account audit routing. `TenantMigrationTest` round-trips populated Phase 1 fixtures, including historical notes, and verifies preserved IDs, links, data, and guarded rollback. Phase 1 feature tests remain regression coverage.

Run database tests only with a dedicated test database. `RefreshDatabase` resets its schema; never point it at development or production data.
