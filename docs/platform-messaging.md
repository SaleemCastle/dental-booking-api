# Platform messaging

See [communication governance](communication-governance.md) for the separate view/send permissions, handoff, escalation, deactivation, retention, controlled redaction and export/access logging added after this foundation.

Run `php artisan migrate` and `php artisan db:seed --class=PermissionSeeder` to install storage and the permission catalog. No provider or AWS resources are provisioned. The old `/api/messages` API returns 410 for every operation. Its records remain preserved; they are never exposed as authorized platform conversations.

All new endpoints require Sanctum authentication, active tenant membership and the existing `X-Tenant-Id` context. Responses use the standard API envelope. Every read requires current participation and permissions; knowing a thread ID, an admin role, or wildcard CRUD permissions grants no access. Foreign and inaccessible threads return 404. Search matches authorized thread subjects only. Lists paginate with `per_page` (1–100, default 25) and `page`, returning `meta.pagination`.

## Permissions and directions

Assign exact catalog permissions through the existing role permission API:

| Permission | Purpose |
| --- | --- |
| `messaging.internal.send` | Start/send an internal thread |
| `messaging.internal.receive` | Receive/read internal messages as an invited participant |
| `messaging.patient.view` | Read actively assigned/invited patient threads |
| `messaging.patient.send` | Create/send patient threads; also requires patient view |
| `messaging.preferences.manage` | Review/record patient in-app consent evidence |

`PUT /api/users/{user}/messaging-permissions` requires admin role plus settings-manage permission. It accepts nullable messaging overrides, including separate view/send grants; see the governance contract. `null` inherits the role grant; false denies; true grants. Patient send/view permissions never override the admin/patient role prohibition. Users need a clinic role; portal patient identities cannot use these endpoints.

Admins may initiate internal threads targeting eligible staff. Staff may exchange messages with eligible staff. Staff-to-admin sends are outside the permitted directions and return 403, including replies to admin broadcasts. Patient participation now supports explicit handoff and supervisor escalation through governance endpoints. Patient threads reject every admin participant and recheck active staff participants at send time, including subsequent role changes. Admins cannot review patient messaging preferences through this module.

## Threads and sends

| Endpoint | Input / result |
| --- | --- |
| `GET /api/conversation-threads` | Optional `search`, `page`, `per_page`; returns `data.threads` |
| `POST /api/conversation-threads` | `subject` (max 200), optional `user_ids` (max 20); optional `patient_id` with required `use_case=care` or `billing`; returns `data.thread` with participants |
| `GET /api/conversation-threads/{thread}` | Authorized thread and participants |
| `GET /api/conversation-threads/{thread}/messages` | Paginated `data.messages`, oldest first |
| `POST /api/conversation-threads/{thread}/confirmations` | Patient message payload; returns a 5-minute `confirmation_token`, expiry, patient, use case and body for review |
| `POST /api/conversation-threads/{thread}/messages` | Message payload plus patient confirmation when applicable |

A message payload is `{ "body": "Instructions", "idempotency_key": "unique-send-key" }`. The sender comes from the authenticated identity. Recipients are the other thread participants; callers cannot override them. Only `in_app` is supported. Attachments and document references are disabled. Email/SMS provider queues and delivery services do not store or dispatch these conversation bodies.

For patient sends, first prepare a confirmation using the exact payload. After the user reviews the destination and message and explicitly confirms sending and acknowledges consent, POST the same payload with `confirmation_token`, `send_confirmed=true` and `consent_acknowledged=true`. Do not automatically set these flags as part of token preparation. Tokens are bound to tenant, sender, thread, body, operation kind, correction target, reason and idempotency key. They are single-use. Consent and participant permissions are rechecked at send time. Preparing a token does not send a message.

Successful first sends return 201. An identical retry with the same tenant/user key returns 200 with the same message; changed input returns 409. Retrying an accepted operation does not consume another token or rate-limit slot. A new operation needs a new key and, for patients, a new confirmation. Limits are 20 new messages per sender/minute and 50 per patient/hour across threads, serialized by a tenant database lock. Thread creation is limited to 10/minute per actor. A shared HTTP throttle additionally bounds thread creation, confirmations and sends to 30/minute per authenticated caller; the existing API throttle also applies.

Internal messages have `delivery_state=available`. Patient messages have `delivery_state=awaiting_portal`; this means recorded for future access, not delivered or read by a patient. A patient participant stores the clinic patient ID independently from any future authenticated portal identity. Portal enrollment, patient-origin sends and delivery/read receipts remain deferred. A future delivery adapter can reference message IDs without changing conversation history.

## Consent

Patient consent now uses the separate [append-only communication consent ledger](communication-consent.md). Authorized staff can use the compatibility `PUT /api/patients/{patient}/messaging-preferences` adapter with acknowledged policy evidence:

```json
{"channel":"in_app","use_case":"care","allowed":true,"source":"written_form","text_version":"care-v1","acknowledged_text":"I agree to in-app care messages.","evidence":"Signed patient consent form."}
```

Missing consent defaults to denial. A grant for care does not authorize billing; verified email/SMS contacts do not imply patient consent. Staff must record actual patient authorization, never infer it from a channel's availability or the sender's confirmation. Each change retains prior evidence. Sends capture an immutable patient-consent decision/version separately from the staff confirmation. The canonical consent API exposes current decisions and full history for future portal integration.

## Corrections, redaction and retention

Messages, thread metadata and participants are append-only at the database level. There is no PATCH/DELETE message route. A sender may append `kind=correction` with `supersedes_id`, required `reason`, replacement `body` and a new idempotency key. A sender may append `kind=redaction` with `supersedes_id`, required `reason`, a new key and no body. Targets must belong to that thread and sender. Patient corrections/redactions use the same confirmation flow. Redaction hides the target body/reason from every API response, including idempotent retries; original records remain in protected storage. Corrections remain visible as linked history. Other participants cannot correct/redact another sender's message. Redactions cannot themselves be corrected or redacted.

Stored messages include sender participant and authenticated actor, recipient participant IDs, thread, channel/state, timestamp and confirmation metadata. Content is excluded from general audit logs. Database guards reject cross-tenant relationships and silent mutation, including direct SQL updates/deletes. Rollback refuses to discard existing threads; export and back up retained history before a controlled migration.
