# Audit logging (M7)

Phase 2 M0: records belong to the selected clinic, with existing history backfilled to the default clinic. Clinic isolation also applies to audit reads, filters and role checks. Global identity events are routed to the identity's memberships. See the [multi-tenant contract](multi-tenancy.md).

`App\Services\AuditLogService` appends records to `audit_logs`. Authenticated admins and auditors can read history through `GET /api/audit-logs`, `GET /api/audit-logs/filters`, and `GET /api/audit-logs/{audit_log}`. No audit create, edit, delete, or retention API is exposed. See the [frontend implementation guide](audit-log-frontend-guide.md) for authorization, filters, pagination, response types, examples, and UI integration. Core Phase 1 patient, appointment, clinical-note, user, and role workflows are integrated as listed below. Factories, seeders, direct model/pivot writes, and workflows not listed here do not generate domain audit events.

## Core Phase 1 coverage

| Workflow | Resource type / ID | Actions |
| --- | --- | --- |
| Patient create, general/medical edit, archive (including legacy API aliases) | `patients` / patient ID | `created`, `updated`, `archived` |
| Appointment create, edit/reschedule, status transition (PATCH/PUT and check-in/complete/cancel endpoints) | `appointments` / appointment ID | `created`, `updated`, `status_changed` |
| Clinical-note create, edit, soft-delete | `clinical_notes` / note ID | `created`, `updated`, `deleted` |
| User API create, edit (including password), delete | `users` / target user ID | `created`, `updated`, `deleted` |
| Public registration | `users` / newly created user ID | `created`, with null actor because the request is unauthenticated |
| Successful password reset / signed email verification | `users` / verified target user ID | `password_reset`, `email_verified`; actor is the account verified by the reset token or authenticated signed request |
| Role create, edit, delete | `roles` / role ID | `created`, `updated`, `deleted` |
| Replace a user's assigned roles | `users` / target user ID | `roles_changed` |
| Replace a role's permissions | `roles` / role ID | `permissions_changed` |

Actors on authenticated operations come from the performing user, never the resource owner, assignee, or provider. A user changing another account is the actor; the edited account is the resource. Related IDs, profile/clinical values, password hashes, and permission snapshots are not copied into audit storage.

Each appointment write emits one record. If status and other fields change together, `status_changed` takes precedence over `updated`. Repeating the current status without another change emits nothing; editing a cancellation reason without changing status emits `updated`, without copying the reason. Reads, denied/invalid actions, no-op edits, identical role/permission assignments, and repeated patient archival emit no mutation record. Deleting an assigned custom role also emits `roles_changed` for each affected user as the memberships are removed. Physical appointment deletion is outside the required Phase 1 coverage; cancellation is covered.

Domain mutations and audit inserts share a transaction, including role/permission pivot changes and cascades. A rolled-back mutation leaves no success record. Audit storage failures retain the existing fail-closed baseline contract: the entire database mutation rolls back and the API returns its standard server error rather than committing a partial write. Logging introduces no after-commit listener dependency or external delivery step. Audit events describe persisted database mutations; subsequent notification/session failures are not evidence that a committed mutation did not occur.

Application role changes use the admin-only `RoleWorkflow` and the [role-management API](roles.md). Raw model/pivot changes in maintenance scripts are deliberately not observed; use the workflow with the explicit performing actor to audit them. Existing patient, appointment, clinical, and user CRUD authorization rules remain unchanged.

## Record contract

| Field | Convention |
| --- | --- |
| `id` | Server-generated audit record ID. |
| `tenant_id` | Server-selected owning clinic. Never accepted from payloads; existing Phase 1 records retain their IDs and belong to the migrated default clinic. |
| `actor_id` | Authenticated user's canonical integer ID; explicitly pass null for system/background work without a user. Never accept attribution from client input. |
| `action` | Stable lowercase machine-readable verb, such as `created`, `updated`, or `deleted`; maximum 80 characters, matching `[a-z][a-z0-9_.]*`. |
| `resource_type` | Stable plural snake-case domain identifier, such as `clinical_notes` or `tasks`; maximum 80 characters, matching `[a-z][a-z0-9_]*`. Avoid PHP class names so refactors preserve the convention. |
| `resource_id` | Canonical resource key stored as a string; accepts integer IDs, UUIDs, and machine keys matching `[A-Za-z0-9][A-Za-z0-9._:-]*`, maximum 128 characters. Never substitute a patient name or descriptive text. |
| `occurred_at` | Server-generated UTC timestamp at append time, with microsecond precision; exposed by the model as an immutable datetime. No `updated_at` exists. |
| `ip_address` | Valid IPv4/IPv6 address from Laravel's request IP resolution, nullable. Configure trusted proxies correctly; this is contextual information, not proof of identity. |
| `user_agent` | Nullable client user agent, with control characters removed and truncated to 512 characters. Treat this client-supplied value as untrusted. |
| `request_metadata` | Nullable JSON allowlist: validated `request_id` from middleware, HTTP `method` (up to 16 characters), and route URI template (up to 255 characters). Route parameters are not expanded. |

Actor and resource identifiers deliberately have no cascading foreign keys. Deleting a user or resource neither deletes nor rewrites historical audit entries. `AuditLog::actor()` optionally resolves the current user; a deleted account resolves to null while its original `actor_id` remains recorded. User names/emails are not copied into audit storage.

## Reusing the service

Inject `AuditLogService` into a domain workflow and append only after the validated mutation succeeds:

```php
DB::transaction(function () use ($task, $actor, $auditLogs) {
    if ($task->isDirty()) {
        $task->save();
        $auditLogs->record('updated', 'tasks', $task->id, $actor->id);
    }
});
```

Use the same database connection and transaction for domain changes and audit inserts. The service inserts synchronously and propagates failures, allowing the enclosing transaction to roll back both writes. Do not swallow storage failures or move required audit persistence into an after-commit listener. Transaction retries roll back unsuccessful audit inserts along with the domain write. Avoid recording no-op updates as mutations.

The actor argument is required even when null; the service does not infer attribution from ambient authentication. The active routed HTTP request supplies context automatically. CLI work without a routed request leaves context null. Callers may pass an explicit `Illuminate\Http\Request` as the fifth argument when context is otherwise unavailable. For background work initiated by a user, carry the initiating user ID explicitly; do not manufacture an HTTP request or infer the actor from the assignee/provider.

Clinical-note writes append `created`, `updated`, and `deleted` records in their existing transaction. Failed validation, denied authorization, reads, no-op updates, and rolled-back writes append no mutation record. If audit storage fails, the clinical mutation rolls back. Existing `ClinicalNoteChanged` events still dispatch after commit for other consumers; audit persistence does not depend on event listeners.

## Privacy and immutability

The service accepts identifiers and optional request context, not arbitrary metadata or before/after snapshots. Do not log clinical text, patient demographics, task descriptions, passwords, tokens, cookies, authorization headers, request/response bodies, query strings, concrete URLs, or referrers. Clinical-note event snapshots contain sensitive clinical attributes and must not be copied into the audit table or generic application logs. Correlate investigations using resource IDs and request IDs under appropriate access controls.

Existing audit models reject saves, updates, deletes, and quiet mutations. The audit Eloquent builder rejects bulk updates/deletes, upserts, increments/decrements, and truncation. Rows remain queryable and additional entries can be appended. Correct mistakes by appending a separate explanatory domain event with a stable machine-readable action, never by rewriting an existing record.

This is application-level append-only enforcement, not tamper-proof storage. Raw SQL, `DB::table`, explicit lower-level query builder access (`toBase()`/`getQuery()`), migrations, and privileged database operators bypass model guards. Application code must use this service for writes. Production hardening should restrict audit-table UPDATE/DELETE privileges and separate migration credentials; a retention/access policy and external immutable storage are separate work. IP addresses and user agents are sensitive operational data and should follow that policy.

## Verification

`tests/Feature/Api/AuditLogApiTest.php` verifies read authorization (including wildcard bypass attempts and role revocation), list/detail/selector contracts, pagination, filters and UTC microsecond bounds, historical references, safe serialization, empty results, input validation, and unavailable write methods. Audit reads do not create audit events.

`tests/Feature/AuditLogTest.php` covers attribution, UTC precision, system actors, request-context allowlisting/bounds, identifier validation, model/bulk/quiet immutability, history after account/resource deletion, clinical CRUD integration, no-op/denied/invalid exclusions, transaction rollback, and audit-storage failure rollback for all clinical write operations. `tests/Feature/CoreAuditCoverageTest.php` adds patient, appointment, user, registration, credential, rollback, and storage-failure coverage. `tests/Feature/Api/RoleApiTest.php` verifies admin-only access, role CRUD, assignment/permission changes, protected system roles, and no-op/error exclusions. Existing domain feature tests remain applicable.

Run these tests against a dedicated disposable test database: `RefreshDatabase` resets its schema. Never point the test suite at the development database.
