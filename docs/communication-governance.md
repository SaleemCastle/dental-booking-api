# Communication governance

Deploy with `php artisan migrate` and `php artisan db:seed --class=PermissionSeeder`. Existing message bodies, participant identities, consent evidence and timestamps remain intact. Existing patient-send grants do **not** automatically grant the new patient-view permission; deliberately assign both where staff need to send. The legacy internal-receive permission remains a read alias. No permission grants access to all patient conversations.

## Permissions and scope

Every endpoint requires authentication, an active clinic membership and the existing tenant context. Read/search/detail/export/history access requires an active thread participant plus its exact view permission. Missing and inaccessible IDs return 404. Admin and patient roles remain excluded from patient threads even when granted every catalog permission or wildcard. Neither send permission implies view permission; senders need both. Per-user false overrides take precedence over role grants.

| Exact role permission | Per-user override | Purpose |
| --- | --- | --- |
| `messaging.internal.view` | `internal_view` | Read invited internal threads |
| `messaging.internal.send` | `internal_send` | Create/send internal threads, also requiring view |
| `messaging.patient.view` | `patient_view` | Read invited patient threads |
| `messaging.patient.send` | `patient_send` | Create/send patient threads, also requiring view and patient consent |
| `messaging.settings.manage` | `settings_manage` | Configure clinic policy; admin role additionally required for user grants/deactivation |
| `messaging.history.view` | `history_view` | Review authorized thread governance history and authorized source delivery history |
| `messaging.assign` | `assign` | Hand off an accessible patient thread |
| `messaging.escalate` | `escalate` | Invite one permitted supervisor when enabled by clinic policy |
| `messaging.supervise` | `supervise` | Review an explicitly escalated thread and resolve its escalation |
| `messaging.archive` | `archive` | Archive/reopen an accessible thread |
| `messaging.redact` | `redact` | Apply a controlled visibility redaction to any message in an accessible thread |
| `messaging.export` | `export` | Export authorized history with a recorded purpose |

`messaging.internal.receive` and `internal_receive` remain aliases for internal viewing. `internal_view` overrides take precedence if supplied. Consent capture still requires the separate exact `messaging.preferences.manage` permission.

`PUT /api/users/{user}/messaging-permissions` now requires both admin role and settings-manage permission. Supply one or more of the override fields above (or legacy fields) as nullable booleans. Null inherits the role; false denies; true explicitly grants. Only provided fields change. Non-admin settings managers cannot grant themselves permissions. Admin patient-view/send grant attempts are rejected, and the hard role boundary remains enforced even against stale grants.

## Handoff and escalation

| Endpoint | Body / behavior |
| --- | --- |
| `POST /api/conversation-threads/{thread}/handoff` | `user_id`, `reason`; target must be current clinic staff with patient view and send permissions |
| `POST /api/conversation-threads/{thread}/escalate` | `user_id`, `reason`; target must have patient view and supervise permissions |
| `POST /api/conversation-threads/{thread}/resolve` | `reason`; only the selected supervisor can resolve |
| `GET /api/conversation-threads/{thread}/history` | Requires view, participation and history permission; paginated governance events |

Handoff changes the assignee and revokes the previous assignee's access unless that person is the current escalation reviewer. Historical participant and message records stay immutable. Only current active participants receive new sends. A target can read history after handoff; other clinic staff cannot. Handoff itself does not send a patient message or supply patient consent.

Escalation is disabled by default. When enabled, it grants the selected supervisor access to this thread only. Read-only supervisors cannot send without an explicit send grant. On resolution, temporary escalation access is revoked; pre-existing participation is preserved. Handoff to the supervisor preserves their newly assigned access. An unresolved escalation cannot be archived. Membership changes invalidate outstanding send-confirmation tokens, requiring a fresh preview of the changed recipients.

## Deactivation and authorship

`POST /api/users/{user}/communication-access/deactivate` requires admin role and settings-manage permission. It revokes that staff member's current thread access, clears their assignment/escalation state and denies all messaging overrides. It retains global identity and historical authorship. Other clinic memberships remain unaffected. This endpoint deactivates messaging access; it does not remove the clinic membership or disable unrelated clinical workflows.

Full clinic-member removal through `DELETE /api/users/{user}` now requires admin role and also revokes messaging access before removing roles/membership. Referenced identities remain retained for messages, consent and governance evidence. Re-enrollment does not restore prior thread participation automatically. Perform patient handoff before deactivating the last eligible participant: deactivation deliberately does not expose an orphaned thread to other staff or administrators. Recovery of orphaned records requires a separately authorized operational process; no tenant-wide discovery endpoint is provided.

Staff may update their own profile/password. Editing another staff identity requires admin role, preventing a password-change route from bypassing communication permissions. Existing protections for shared identities and platform operators still apply.

## Retention, archive and legal hold

`GET /api/communication-governance/policy` returns the current clinic policy. `PUT` appends an immutable policy version:

```json
{
  "retention_days": 365,
  "archive_after_days": 30,
  "escalation_enabled": true,
  "policy_reference": "Clinic-approved retention decision REF-23"
}
```

These numbers are examples, not legal recommendations. The clinic supplies its compliance decision/reference. Both day fields accept null or 1–36500. Default retention is indefinite and automatic archive is disabled. `retention_days` is a **minimum**, calculated from the latest operational/message activity; it is not an automatic deletion deadline. Existing append-only storage retains originals beyond that minimum. Policy versions, recorder and timestamps remain retained.

`POST /api/conversation-threads/{thread}/archive` accepts `archived` and `reason`. Archived threads remain readable to authorized participants but reject sends and new confirmations with 409. Default lists omit archived threads; `archived=1` includes them. `POST .../{thread}/hold` accepts `enabled` and `reason`, requires settings permission plus thread access, and records a legal-hold event. A hold makes retention indefinite and exempts the thread from automatic archive. Manual archive remains a visibility/workflow action, not deletion.

The scheduled `communications:archive` command runs daily and archives up to 1,000 inactive open/resolved threads per active clinic per invocation. It skips held and escalated threads, records the applied policy version and restores tenant context. Run it again to process a larger backlog. Reopening resets inactivity. Detail and export responses include `data.retention` with the minimum retention boundary, hold status, policy version, `originals_retained=true` and `deletion_authorized=false`.

Automatic purge and physical removal of redacted originals remain disabled. A maximum retention/deletion mandate needs its own clinic-approved destruction workflow; configuring a minimum here never silently destroys immutable evidence.

## Controlled redaction and export

`POST /api/conversation-threads/{thread}/redact` accepts `message_id` and a required `reason`. It requires active participation, view permission and redaction permission. It records actor, time, reason, message ID, policy version and retention/hold context as an immutable governance event. Authorized staff can redact another sender's message through this endpoint. Bodies and original reasons are hidden from normal list, retry and export responses; original database records remain protected and unchanged. Redaction details/reason can be reviewed through permission-controlled governance history. This local visibility control does not dispatch a patient communication and does not create patient consent. Sender correction/redaction messages retain their existing separate send-confirmation and consent requirements.

`POST /api/conversation-threads/{thread}/export` accepts a required `reason`, optional `page` and `per_page` (1–100, default 100). Each response is an authorized JSON attachment page with current redactions applied, thread state, policy/retention information and `export_event_id`. Each page has an audit event identifying actor, time, purpose and exact message IDs. Export permission never substitutes for current thread access. Exports do not expose unredacted originals or bypass archive/view rules.

Sensitive thread lists, detail reads, message-history reads, governance-history reads, exports and source delivery-history reads are access-logged. Delivery histories also require their existing patient/appointment/invoice policy plus the new history permission. Audit logs contain IDs/actions/request correlation, not bodies, search terms, consent text or free-form reasons. Governance reasons stay in the protected governance ledger. Sensitive responses use private/no-store caching where history/content is returned.

Message/consent request fields are excluded from session flashing. Unexpected communication exceptions are reported using their class and request correlation only; SQL bindings, raw exception messages, bodies and tokens are excluded from application error logs. No message analytics/event payload stream is added.
