# Role management (M7 audit coverage)

All endpoints require Sanctum authentication and the actual `admin` role. A global permission wildcard or role-management permissions without the admin role do not grant access. Mutations also check the admin role inside `RoleWorkflow`; membership is freshly loaded so removing one's own admin role takes effect on the next request. Existing user CRUD authorization is unchanged.

| Method | Endpoint | Result |
| --- | --- | --- |
| GET | `/api/roles` | `data.roles`, ordered by ID, with nested permissions. |
| POST | `/api/roles` | Create a custom role; 201 with `data.role`. |
| GET | `/api/roles/{role}` | `data.role` with permissions. |
| PATCH / PUT | `/api/roles/{role}` | Partial role update; `data.role`. |
| DELETE | `/api/roles/{role}` | Delete a custom role and its memberships/permission links; standard success envelope. |
| GET | `/api/permissions` | Read-only permission selector, `data.permissions`, ordered by slug. |
| PUT | `/api/roles/{role}/permissions` | Replace permissions with `{"permission_ids": [1, 2]}`; `data.role`. |
| PUT | `/api/users/{user}/roles` | Replace assigned roles with `{"role_ids": [1, 2]}`; `data.user`, including roles and flattened permission slugs. |

Role creation requires `name` (nonblank, at most 255 characters) and `slug` (unique, at most 255 characters, matching `[a-z][a-z0-9_-]*`). `description` is optional/nullable, at most 1,000 characters. Updates preserve omitted fields. `is_system` is server-controlled and prohibited in input; new roles default to false. System roles can be renamed/described and have their permissions managed, but their stable slugs cannot change and they cannot be deleted. This preserves seeded authorization identities.

Assignment arrays must be present, contain distinct integer IDs, and reference existing records. Empty arrays explicitly remove all assignments; omitted/null/non-array values fail validation. Replacing assignments with the same IDs is a successful no-op. These endpoints do not create new permission definitions. Missing role/user IDs return 404; invalid requests return 422; unauthenticated/non-admin access returns 401/403 using the standard API envelope.

Role changes are audited in the same transaction as their primary and pivot writes. See [audit logging conventions](audit-logging.md) for the action/resource mapping and failure behavior. No role descriptions, credential/profile data, or permission snapshots are copied into audit records. Deleting an assigned custom role records both its deletion and each affected user's role change. There is no audit-history API in this milestone.

The API allows an admin to remove their own role; ensure another administrator remains available before doing so. It does not add last-admin recovery or automatic protection against deliberate administrative access removal.
