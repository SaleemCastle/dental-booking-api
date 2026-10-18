# Audit logging (M7)

`App\Services\AuditLogService` appends records to `audit_logs`. This is internal infrastructure; no audit read, edit, delete, or retention API is exposed. Clinical-note create, changed update, and soft-delete workflows are the first integrated consumers. Other workflows must explicitly call the service; they are not automatically audited by this milestone. Factories, seeders, and direct model writes do not generate domain audit events.

## Record contract

| Field | Convention |
| --- | --- |
| `id` | Server-generated audit record ID. |
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

`tests/Feature/AuditLogTest.php` covers attribution, UTC precision, system actors, request-context allowlisting/bounds, identifier validation, model/bulk/quiet immutability, history after account/resource deletion, clinical CRUD integration, no-op/denied/invalid exclusions, transaction rollback, and audit-storage failure rollback for all clinical write operations. Existing clinical-note feature tests remain applicable.

Run these tests against a dedicated disposable test database: `RefreshDatabase` resets its schema. Never point the test suite at the development database.
