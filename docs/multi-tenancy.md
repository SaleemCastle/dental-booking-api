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

Migration `2026_10_23_000001_enforce_tenant_data_integrity.php` completes the data boundary on existing installations:

- Preflights all owned rows and clinical links, including archived patients and soft-deleted notes. Orphaned ownership, foreign-clinic references, and task assignees without membership stop the migration before DDL; the migration never deletes or silently relinks inconsistent data. Correct the reported table on a restored copy and retry after reconciliation.
- Adds insert/update guards on every owned table in MySQL and SQLite. Ownership must reference an existing clinic and cannot be transferred, even through raw or bulk writes. Appointments, locations, notes, tasks, invoices, payments, messages, and referrals must reference records in the same clinic. Existing single-column foreign keys retain their original cascade and optional-link `SET NULL` behavior. Triggers avoid setting a non-null `tenant_id` to null alongside a deleted optional relationship. MySQL deployment credentials require `TRIGGER` privileges as well as schema privileges.
- Requires task assignees to remain members with a composite MySQL foreign key and equivalent SQLite guards. Reassign or delete all of a member's clinic tasks before removing their membership; `/api/users/{user}` returns 422 on field `user` without changing roles, memberships, accounts, or audits until this is resolved. Tasks in other clinics do not block removal from the selected clinic.
- Adds the missing Phase 1 patient/provider foreign keys on appointments and referrals, with restrictive deletion, and equivalent SQLite guards. Referenced parents cannot be deleted or have IDs replaced to orphan those records. Clinic deletion/key changes are also restricted while owned data exists.
- Adds tenant-leading indexes for patient archive browsing, dentist email validation, treatment catalogs, schedule/overlap queries, invoice status aggregates, note history, task worklists, and audit chronology/actor/resource filters.

The uniqueness review retains clinic-unique role slugs, room names, and invoice numbers from the foundation. Chair names remain unique within a room; room IDs already identify a single clinic. Dentist email validation is clinic-local with a non-unique `(tenant_id, email)` lookup index, preserving legacy duplicate emails rather than imposing a new uniqueness rule on existing data. Patient names/contact fields and treatment names were never unique and remain so. Global user emails, tenant slugs, permission slugs/resource-action pairs, and token identifiers retain their global meaning. Membership and role/permission pivot uniqueness already includes clinic ownership directly or through the owning role's globally unique ID.

## Request context and frontend contract

1. Authenticate normally using existing Sanctum bearer tokens or browser sessions. Authentication is global; tokens do not confer clinic membership.
2. `GET /api/tenants` returns only the authenticated user's active memberships as `data.tenants`, ordered by ID. This endpoint does not require default-clinic membership and does not enroll the caller anywhere.
3. Clinic endpoints optionally accept `X-Tenant-Id: <positive integer>`. Omitting it selects `default-clinic`, **not** the first membership and not the last clinic visited. A user without that membership gets 403 rather than falling back to another clinic.
4. `TenantResolver::resolve(User $user, ?string $requestedId)` queries active memberships directly on every request, including when the authenticated user has cached membership relations. Missing defaults, unknown/deleted clinics, inactive clinics, and missing/revoked memberships all return the same generic 403 without echoing clinic IDs, names, or membership details; malformed IDs return 422. Authentication failures remain 401, even with an invalid clinic header. Resolution runs before route-model binding and authorization. A foreign clinic's resource ID returns 404. Request payload/query `tenant_id` and `tenantId` are rejected, including null values; ownership cannot be mass-assigned.
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

New clinical-note author/editor/deleter attribution and patient archive attribution must reference current-clinic members, enforced both in models and database write guards. Unchanged historical attribution survives a member's departure. Audit actor IDs are historical global identifiers and intentionally have no membership foreign key: deletion and shared-identity events must retain the original actor even after membership/account removal.

## Models, factories, seeders, and trusted background work

`TenantFactory` creates independent clinics. `UserFactory` gives users membership in the current trusted context for backward-compatible Phase 1 fixtures; `forTenant($tenant)` creates a user belonging only to that clinic. Clinical factories inherit ownership from the context, including their related fixtures. Global user identities created outside `UserWorkflow` must have memberships assigned explicitly.

`TenantSeeder` idempotently ensures the default clinic exists without renaming or reactivating an existing record. `DatabaseSeeder` calls it before role, permission, location, and demo-data seeding. The existing demo admin remains admin only in its clinic. Role, permission-grant, and location seeders operate in the current trusted context and do not copy grants between clinics; permission definitions remain shared.

Trusted code can explicitly enter a clinic context:

```php
app(\App\Tenancy\TenantContext::class)->run($tenant, function () {
    return \App\Models\Patient::factory()->create();
});
```

`run()` requires a persisted tenant and restores the prior context in `finally`, including nested calls and exceptions. The container binding is scoped, not process-global static state. Workers must establish the intended clinic before loading models or invoking workflows; never infer permission to enter a clinic from client-controlled IDs or assume that a Clinic Admin is a platform operator.

## Service, policy, and route conventions

`App\Tenancy\TenantContext` is the single abstraction for reading the current clinic. Constructor-inject it into services and policies (or resolve it with `app(TenantContext::class)` in model hooks). Use `requireId()` when an explicitly established context is required: it throws `LogicException` outside `run()` and never selects a default. The existing `id()` method retains the default-clinic fallback for legacy seeders, factories, and account workflows; that fallback does **not** verify membership and must never authorize access or choose a job's tenant. Request middleware always resolves membership explicitly before entering `run()`.

```php
use App\Tenancy\TenantContext;

final class ClinicReportService
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function clinicId(): int
    {
        return $this->tenant->requireId();
    }
}
```

Place every new clinic route inside the `['auth:sanctum', 'tenant']` group in `routes/api.php`. `Kernel::$middlewarePriority` keeps authentication before tenant resolution, then bindings and policies. Account-level exceptions are listed above and must not return clinical data. Do not read tenant selection from headers, query strings, route parameters, user relations, or token claims inside controllers/services/policies. `ResolveTenant` delegates membership validation to `TenantResolver`; its request attribute is compatibility metadata, not a second source of authority.

Middleware clears cached user roles before use and on exit, and restores request metadata even when downstream code throws. User serialization hides loaded `tenants` relations; `/api/tenants` is the explicit account-level membership-discovery response. Clinic responses may expose their own verified clinic ID, never another clinic's membership, roles, or records. Continue using scoped queries, tenant-aware validators, and policies for every related record and resource ID.

## Queue/job propagation contract

There are currently no clinic jobs. New jobs, queued listeners, notifications, exports, and scheduled commands that access clinic data must follow this contract:

1. At dispatch, capture the tenant with `TenantContext::requireId()` inside the verified request/context, plus the acting user's ID for user-initiated work. Store only scalar tenant/actor/resource IDs in the job payload. Never copy tenant selection directly from request input, serialize the context/service/request, or serialize Eloquent clinical models with `SerializesModels`: Laravel can restore those models before job middleware runs and without the clinic scope.
2. At execution and on every retry, reload the actor and resolve the captured tenant through `TenantResolver`. Missing/deleted actors, revoked memberships, and inactive/deleted clinics must stop processing before any clinical query/write. Recheck the relevant policy/permission inside the established context; membership alone does not authorize an operation. Treat authorization failures as terminal job failures, not reasons to fall back to the default or another membership.
3. Wrap **all** clinical reads, resource loading, policy checks, workflow calls, and writes in `TenantContext::run($tenant, ...)`. Load resources using their scalar IDs only after entering that context. Capture `requireId()` again for child jobs. `run()` restores context after success or exception; do not leave a tenant in static/singleton state between jobs. Workers must reset scoped container instances at job boundaries (the normal Laravel queue worker does this).
4. Trusted system/scheduled work has no implicit user membership. Enumerate active tenants through an explicitly trusted orchestration path and enter a separate `run()` for each tenant. Do not manufacture an actor or apply a Clinic Admin bypass. Establish context separately in failure hooks/listeners that touch clinic data, since they may run after the main context has unwound.

The core of a future user-initiated job handler should look like this; its constructor must capture scalar IDs and its policy must reflect the actual operation:

```php
public function handle(TenantResolver $resolver, TenantContext $context): void
{
    $actor = User::findOrFail($this->actorId);
    $tenant = $resolver->resolve($actor, (string) $this->tenantId);

    $context->run($tenant, function () use ($actor): void {
        $patient = Patient::findOrFail($this->patientId);
        Gate::forUser($actor)->authorize('view', $patient);
        // Perform the authorized operation here.
    });
}
```

## Existing deployment

Back up the database, review this contract, and use a maintenance window with request/worker writes paused. MySQL schema changes are not transactionally rolled back and adding columns/indexes may lock large tables; rehearse on a restored staging copy before production. Run only normal additive migrations:

```shell
php artisan migrate
```

Do **not** use `migrate:fresh` or rerun the full demo-data seeder on an existing clinic. The migration creates the default clinic, backfills memberships/ownership, and preserves current role/permission grants without requiring seeding. Existing clients may continue omitting the tenant header. If explicit frontend permission grants were never installed, the existing `PermissionSeeder` can still be applied deliberately; that is a separate baseline grant change, not part of migration backfill.

Rollback preserves Phase 1 IDs and data when only the original default clinic exists. It refuses to remove the isolation boundary after additional clinics have been created; do not drop tenant columns or merge clinics to bypass that guard. Reconcile/export multi-clinic data and plan a separate recovery migration instead.

Roll back the integrity migration before the foundation migration. Its `down()` removes only the new guards, assignee constraint, and query indexes, preserving tenant columns, memberships, payloads, and IDs even in a multi-clinic database. Keep writes paused while guards are absent. Reapplying it reruns the preflight. MySQL can leave partial DDL if privileges, locks, or infrastructure fail after preflight: restore the pre-migration backup and rerun, or have an operator inventory and remove only this migration's named triggers/indexes/assignee foreign key before retrying. Do not mark a partial migration complete, blindly retry DDL, or use `migrate:fresh` for recovery. A preflight failure itself needs no schema cleanup.

## Validation

### Tenant-isolation security assumptions and evidence

The attacker is an authenticated clinic member, including a Clinic Admin with wildcard permissions, who knows another clinic's resource IDs and can manipulate headers, URLs, query parameters, JSON bodies, related IDs, role assignments, and status/archive actions. Tests create two populated clinics with matching patient/provider/treatment/location names, provider email, role slug, appointment times, invoice numbers, audit resource identifiers, and task due dates. Assertions verify selected-clinic IDs and totals rather than relying on different names or empty foreign clinics.

The server boundary has several layers: authentication and fresh active membership resolution precede route binding; `BelongsToTenant` scopes reads and stamps ownership; the central Gate guard rejects inactive/non-member contexts and foreign resource instances before permission grants or policies; `TenantRules` scopes relationship and unique validation; workflows reload mutation targets inside the context; and migration-installed database guards enforce immutable ownership and clinical links. Admin roles and permission wildcards apply only inside their owning clinic. Use Gate/`authorize()` for policy evaluation; do not invoke policy methods directly. A trusted context alone does not grant an actor permission to perform a workflow: callers must authenticate, resolve membership, and authorize the operation first.

| Attack surface | Expected behavior and regression evidence |
| --- | --- |
| Known foreign patient, appointment, treatment, clinical note, task, audit, role, or staff IDs | 404 matching an absent record, including PATCH/PUT, archive/deactivate/delete, legacy patient aliases, appointment transitions, and task status routes. Full database snapshots confirm denied attempts do not change either clinic or append success audits. |
| Unauthorized or unknown clinic header | Same generic 403 before clinical validation, bindings, reads, writes, or role assignment; no clinic discovery through error details. Existing authentication/context tests also cover malformed headers, revoked membership, and inactive clinics. |
| Related IDs in creates, updates, and filters | Foreign and missing IDs produce identical generic 422 validation responses without foreign names or clinical details. Direct workflows reject foreign instances/relationships even without route binding. |
| Submitted primary/ownership IDs | Client primary IDs cannot overwrite another clinic's records; new records use server ownership. Both ownership-field aliases are rejected. Audit creation/modification endpoints remain unavailable (405), regardless of known IDs. |
| Roles, permissions, and shared identities | Foreign role/target/grant changes are rejected and grants remain unchanged. A user with admin/wildcard grants in one clinic and membership alone in another gets no clinical or role-management authority there, including when role relations were previously loaded. |
| Lists, searches, archives, soft-deleted history, selectors, and pagination | Both clinics return only their own record IDs and pagination totals; numeric and text searches cannot expose foreign records. ORM queries, including OR predicates, remain scoped without frontend filters. |
| Dashboard and audit reporting | Appointment statuses, staff worklists, invoice amounts/collection rates, audit actors, resource/action options, resource IDs, and correlation filters exclude foreign activity. Audit resource identifiers deliberately overlap across clinics. |
| Unique validation | A role slug, provider email, or invoice number used only in another clinic does not produce a uniqueness error; duplicates inside the selected clinic still fail. |

Users, user email uniqueness, credential/token endpoints, and permission definitions are deliberately global account/catalog concepts, not isolated clinic records. `/api/tenants` reveals only the authenticated user's own active memberships. Historical audit actor and clinical attribution IDs can identify a former member; this is preserved history, not a grant or an arbitrary tenant lookup. Account-level identity events follow the membership audit-routing contract above. Legacy public registration still grants default-clinic membership: this proof assumes that enrollment rule is intentional and verifies isolation relative to assigned memberships, not signup/invitation eligibility. Existing Phase 1 permission choices within an authorized clinic are unchanged.

This boundary assumes normal application authentication and the tenant/integrity migrations are installed. Code with database-administrator privileges, disabled middleware/scopes/model events, or explicitly privileged raw queries can read outside ORM scopes and must never be driven by untrusted request input. Database guards protect write integrity, not read authorization. Jobs, exports, future caches, and reports must follow the context propagation contract and include the tenant in any cache key; there is no shared clinical response cache today. These tests verify functional isolation, not resistance to timing analysis or a compromised server/database administrator.

Run the dedicated adversarial suite with `vendor/bin/phpunit --filter TenantIsolationTest`. Run it together with foundation, context, migration, and raw-write coverage using `vendor/bin/phpunit --filter 'Tenant(Isolation|Foundation|Context|Migration|DataIntegrity)Test'`. SQLite tests use an isolated in-memory database; the existing MySQL CI matrix must also pass before deploying. `TenantIsolationTest` keeps authentication middleware, tenant resolution, binding, policies, validation, and workflows enabled; the context suite additionally exercises actual bearer tokens and sessions.

`TenantFoundationTest` covers default behavior, membership discovery, malformed/unauthorized/inactive clinic selection, role/permission isolation on reused user objects, lists/bindings across clinical domains, dashboard/audit isolation, cross-clinic relationship validation, duplicate per-clinic slugs/locations, stale model writes, composite pivot constraints, shared identities, and account audit routing. `TenantMigrationTest` round-trips populated Phase 1 fixtures, including historical notes, and verifies preserved IDs, links, data, and guarded rollback. Phase 1 feature tests remain regression coverage.

`TenantContextTest` covers bearer/session authentication, middleware ordering with real route bindings/policies, indistinguishable membership failure responses, missing defaults, clinic deactivation, query/body ownership overrides, loaded membership/role serialization, consecutive requests, nested contexts, downstream exceptions, and refusal to enter an unpersisted context or capture an implicit default for a job.

Run database tests only with a dedicated test database. `RefreshDatabase` resets its schema; never point it at development or production data.

`phpunit.xml` defaults to SQLite `:memory:`. Enable PHP's PDO SQLite extension before running `vendor/bin/phpunit`. CI also runs the complete suite against a dedicated MySQL 8 database so both guard implementations and rollback paths are exercised. `TenantDataIntegrityTest` covers raw insert/update isolation for every clinical relationship, ownership transfer, membership removal, preserved nullable deletion behavior, historical attribution, and full Phase 1 API workflows in a non-default clinic, including search, worklist filters, invoice dashboard totals, and audits. Migration tests cover both migration round-trips, preflight failure/recovery, and multi-clinic integrity rollback.
