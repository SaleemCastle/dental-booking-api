# Postman API collection

## Import and authenticate

1. Import [the collection](postman/dental-booking.postman_collection.json) and [the local environment](postman/local.postman_environment.json) into Postman.
2. Select **Dental Booking - Local** as the active environment.
3. Set `base_url` to the backend origin, such as `http://localhost:8000`, without `/api` or a trailing slash. Set `email` and `password` to an existing account's credentials. No credentials are shipped in these files.
4. Send **Authentication - Bearer > POST Create a Sanctum bearer token**. The response script saves `data.access_token` into the active environment; other requests inherit bearer authentication.
5. Send **Users > GET Get authenticated user** to inspect current roles and permissions. This also sets `current_user_id` and, if empty, `assignee_id` for task examples.

The collection contains all 110 documented method/path operations from OpenAPI 2.0.0, grouped into 21 folders, including clinic memberships, patient documents, dental charts, treatment plans, billing and audit logs. JSON body examples use minimal create payloads and partial updates; consult [OpenAPI](openapi.yaml) for optional fields and business rules.

Leave `tenant_id` blank for the default clinic. **Tenants > GET List current user's active clinic memberships** returns clinics available to the authenticated user. Set `tenant_id` to a returned ID to send `X-Tenant-Id`, then refetch `/api/user` and clear previously captured resource IDs before using another clinic. This header does not grant membership; foreign clinic/resource access is rejected. See [the tenancy contract](multi-tenancy.md).

## Safety and variables

- `allow_writes=false` skips domain POST/PUT/PATCH/DELETE requests. Set it to the literal string `true` only against a disposable development/test database when intentionally testing writes. Token creation is allowed without this flag.
- `allow_auth_cleanup=false` separately skips bearer-token revocation and session logout. Enable it only when intentionally ending authentication.
- `use_session_auth=false` skips the browser-session folder; bearer authentication is the default.
- Unset IDs and other required request variables cause the request to be skipped, with variable names shown in the Postman console. No existing clinical record is automatically selected for mutation.
- Successful create responses capture their entity ID into the environment, for example `patient_id`, `dentist_id`, `appointment_id`, `treatment_id`, `treatment_plan_id`, `item_id`, `clinical_note_id`, `task_id`, `role_id`, and `user_id`. List responses do not select domain IDs automatically. For existing records, copy the desired canonical ID from a GET response into the corresponding variable. Treatment plan items use the selected plan's `treatment_plan_id`; see [the treatment planning contract](treatment-planning.md) for lifecycle rules.
- `sample_email`, `role_slug`, and `invoice_number` have per-request generated defaults to avoid unique-value collisions. `appointment_start` defaults to tomorrow in UTC, and `due_date` to three days from now. Set explicit environment values to override these defaults.
- When using the generated create-user or registration body, set `new_user_password`; it is deliberately blank. Password templates use computed JSON-encoded local variables to support special characters. If you replace the body with literal JSON credentials, unused `email`, `password`, and `new_user_password` environment values are not required.

The guards use Postman's [pre-request `pm.execution.skipRequest()` API](https://learning.postman.com/v11/docs/tests-and-scripts/write-scripts/postman-sandbox-reference/pm-execution). Use a compatible current runtime. These scripts are convenience guards, **not a security boundary**: do not run this collection against production. Never share populated environments, passwords, bearer tokens, or patient/audit response data. Secret variable types mask values in the UI but do not make exported files safe to share.

### Create user produces no response

A skipped request never reaches Laravel and has no HTTP status or response headers. Open the Postman Console and look for a message beginning with `Skipped`. For `POST /api/users`, select the intended environment, enable `allow_writes=true` only for local/test use, and create a bearer token first. Use Body > raw > JSON with `name`, `email`, and a password of at least eight characters. The generated body needs `new_user_password`; a literal `password` does not.

Older exports incorrectly required `new_user_password` even with a literal password in the body. Reimport the updated collection to replace that pre-request script; merely editing the JSON body does not update collection-level scripts. Keep your existing local environment values rather than overwriting them with the blank environment export. When sent to Laravel, successful creation returns 201 JSON; authentication and validation failures return JSON errors instead of an empty response.

## Trying domain workflows

Use requests interactively rather than running the whole collection with writes enabled. Folder order is not a valid end-to-end CRUD scenario: deletes, historical-retention constraints, role restrictions, and appointment status transitions make an unrestricted full run inappropriate. By default, scripts test for successful responses and the standard eight-field API envelope; a deliberate negative test such as an unauthorized request will fail the success assertion as expected.

- **Treatments:** enable the list request's `active=1` query parameter for the booking selector. Delete deactivates a treatment, retaining history. Optional create/update fields include `description` and `active`.
- **Appointments:** set `patient_id` and `dentist_id` before creation. Obtain treatment IDs from active treatments and room/chair IDs from **GET List appointment locations**. Add `treatment_id`, `room_id`, `chair_id`, or `duration_minutes` to the JSON body when needed; the template omits optional relationships rather than guessing IDs. Reschedule by editing a PATCH body with `appointment_date_time` and a valid duration. Overlaps are rejected. Check-in, complete, and cancel requests are separate; cancellation includes a reason. Follow the [appointment contract](appointment-workflow.md) for valid transitions.
- **Clinical notes:** use an account authorized to write clinical notes. `dentist_id` identifies the provider, not the authenticated author. An optional `appointment_id` must match the patient and provider. Attribution fields are server-controlled. See [clinical notes](clinical-notes.md).
- **Patient documents:** set `patient_id`, enable writes, and select a local file under Body > form-data; requests without a file are skipped. The upload uses multipart, with optional appointment/note fields disabled initially. Successful uploads capture `document_id`. Download returns a binary attachment and bypasses JSON envelope checks on success; errors retain JSON checks. Archive retains private download access. Delete removes access and purges the current object; retry DELETE after a storage failure. See [patient documents](patient-documents.md).
- **Tasks:** set `assignee_id`; optionally add consistent `patient_id` and `appointment_id`, plus `priority` and `status`. Enable assigned-to-me, overdue, due-today, patient, and status filters in Params as needed. The separate status request sets `in_progress`; edit it for other valid values. See [tasks](tasks.md).
- **Billing:** create a draft invoice with immutable line snapshots, then use its PATCH example to issue it before recording payments. Payment examples require `invoice_id`, explicit currency, method and an invoice-scoped idempotency key. Use a new key for each distinct settled payment and the same key for a retry. Invoice/payment lists are paginated arrays. Delete and payment edit operations are unavailable. See [billing](billing.md).
- **Reporting:** the four Reporting requests are read-only. Set the enabled `date_from`/`date_to` parameters (at most 366 inclusive dates); optionally enable `dentist_id`. Provider/treatment pages default to 25 rows, maximum 100. Each report requires its source read permissions; billing requires both invoice and payment reads. See [metric definitions and export strategy](reporting.md).
- **Roles:** use an admin account. Obtain `permission_id` from the permission catalog and deliberately select `user_id` and `role_id` before changing assignments. PUT permission/role assignment requests replace the full set, rather than appending it. See [roles](roles.md).
- **Messages:** both `patient_id` (sender) and `receiver_patient_id` identify patients, not authenticated users.

## Audit logs

Use an account with the actual `admin` or `auditor` role. The **Audit Logs** folder includes:

| Request | Purpose |
| --- | --- |
| `GET /api/audit-logs` | Paginated history with all supported filters included in Params. |
| `GET /api/audit-logs/filters` | Action and resource-type options for selectors. |
| `GET /api/audit-logs/{{audit_log_id}}` | One audit record. |

Only `page` and `per_page` are enabled initially. For other filters, set the corresponding `audit_*` environment value and enable its Params checkbox. `actor_is_null` is a direct `0`/`1` query value; use `1` for unattributed actors and do not combine that with `actor_id`. For a resource history, set both `audit_resource_type` (for example `patients`) and `audit_resource_id`. Time bounds `audit_from` and `audit_to` require RFC 3339 timestamps with seconds and an explicit timezone, such as `2026-10-18T00:00:00Z`.

A nonempty audit list captures the first `audit_log_id` for the detail request, and its originating `audit_request_id` when available. Every outgoing request receives a generated `X-Request-Id`; the audit correlation filter refers to the originating mutation ID stored in the audit record, not the current list request's ID. No audit write requests exist.

The [frontend audit implementation guide](audit-log-frontend-guide.md) covers authorization, rendering, pagination, filtering, timestamps, and error handling.

## Optional browser-session authentication

Set `use_session_auth=true`, change the collection Authorization to **No Auth**, and set `frontend_url` to the configured stateful frontend origin. Keep Postman's cookie jar enabled. Send **Auth > GET Initialize CSRF and session cookies**, then **POST Log in with a browser session**. The pre-request script supplies `Origin` and, when present, a URL-decoded `X-XSRF-TOKEN` from the cookie jar. Backend Sanctum, CORS, and session configuration must allow that origin. Registration additionally requires `allow_writes=true` and either `new_user_password` for the generated template or literal credentials in the body; logout requires `allow_auth_cleanup=true`.

Avoid mixing session and bearer identities. Before switching back to bearer testing, log out or clear backend session cookies, restore inherited bearer Authorization, and set `use_session_auth=false`.

## Regeneration and validation

The exports are deterministic and generated from `docs/openapi.yaml`. The generator adds legacy user/message/referral bodies from their controller contracts and selects safe partial-update examples. No application bootstrap, database access, network calls, or `.env` reads are needed.

```shell
php tools/postman.php --write
php tools/postman.php --check
php vendor/bin/phpunit tests/Unit/PostmanExportTest.php
node --test tests/Postman/collection.test.cjs
```

Regenerate after changing the OpenAPI contract, then reimport the updated collection. Keep personal environment values local rather than committing them.
