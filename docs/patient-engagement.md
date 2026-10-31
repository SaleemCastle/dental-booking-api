# Patient engagement (Phase 3 / M4)

Patient engagement uses the existing encrypted clinic communication configuration, verified patient contacts, durable outbox, provider adapters, worker, retry policy and audits. No notification provider is called from an appointment/task request. Disabled or unconfigured events produce no message and do not backfill when enabled later.

Apply `php artisan migrate` on deployed databases, then configure the clinic with the existing hidden-input `communications:configure CLINIC_ID` command. Keep the normal minute scheduler or supervised `communications:work` command running. No additional queue infrastructure is required. This migration preserves billing notifications/deliveries and their encrypted values and operation keys. Rollback requires a deliberate backup/data migration.

Add these rules to the configuration's `events`, retaining the existing `channels` credentials and any billing rules:

```json
{
  "appointment.confirmed": {"channels": ["email", "sms"]},
  "appointment.reminder": {"channels": ["email", "sms"], "minutes_before": [1440, 60]},
  "appointment.cancelled": {"channels": ["email", "sms"]},
  "appointment.rescheduled": {"channels": ["email", "sms"]},
  "task.reminder": {"channels": ["email", "sms"]}
}
```

`appointment.reminder.minutes_before` is required for that event: 1–10 distinct integer minute offsets, each from 1 to 43200 (30 days). Other events cannot set offsets. Channels must be provisioned for the tenant. Omit events/channels you do not want. Engagement events reject internal `copy_user_ids` and select only verified, unrevoked `patient` contacts authorized for the specific event. Billing-copy contacts, patient free-text contact fields, emergency contacts and global user addresses never provide engagement fallbacks. Every available authorized contact in an enabled channel receives a separate delivery; unavailable channels are skipped and duplicate destinations are collapsed. Existing contacts need explicit authorization for the new events: revoke and register/verify a replacement through the existing contact API.

Appointment creation queues `appointment.confirmed` and future reminder offsets. Updates to scheduling fields queue `appointment.rescheduled`, invalidate prior pending/retry appointment messages, and schedule new future offsets. Status changes invalidate prior appointment messages; cancellation additionally queues `appointment.cancelled`. Cancellation/no-show also suppress pending task reminders linked to the appointment. Repeating an unchanged update emits nothing. Missed offsets are skipped when booking close to the appointment; delayed workers suppress appointment reminders after the appointment start. Appointments with communication history must be cancelled instead of physically deleted.

Follow-up reminders require both the tenant `task.reminder` rule and an explicit `patient_reminder_at` on a task linked to a patient. Due dates and task descriptions alone never trigger patient messages. Example task creation:

```json
{
  "patient_id": 123,
  "assignee_id": 456,
  "due_date": "2026-11-15",
  "description": "Staff follow-up work",
  "patient_reminder_at": "2026-11-15T09:00:00-05:00"
}
```

The timestamp is normalized to the application's timezone for storage; outbox scheduling uses UTC. Supplied timestamps must be future values. Task completion, cancellation, deletion, patient/appointment reassignment, clearing the timestamp or changing reminder timing suppress old pending/retry deliveries. Reopening can schedule a new reminder only if its explicit timestamp remains in the future. Deleting a task retains its notification history and scalar task ID. A linked cancelled/no-show or missing appointment prevents task delivery. Message templates omit task descriptions, clinical notes, cancellation reasons and treatment details.

Plain-text template overrides support `{{patient_name}}`, `{{appointment_time}}` and `{{reminder_time}}` in addition to the existing billing variables. Times include their timezone offset. Variables remain encrypted and are substituted once through the existing safe renderer.

History endpoints:

| Endpoint | Permission |
| --- | --- |
| `GET /api/patients/{patient}/communications` | `patients.view` |
| `GET /api/appointments/{appointment}/communications` | `appointments.view` |

Both accept `page` and `per_page` (1–100; default 15), return newest-first appointment/task event and delivery metadata, and exclude addresses, variables, bodies, recipient hashes and operation keys. Billing history stays at the invoice endpoint with invoice read authorization. Source IDs, revision, actor, channel, attempts, status and sanitized outcomes support investigations. Tenant model scopes and database relationship triggers protect source ownership, including raw writes. Queue selection, suppression, attempts and outcomes append sanitized audits.

Before each send/retry the worker rechecks tenant activity, configuration revision, initiating staff membership/permissions, source revision/status, patient activity and verified event-specific contacts. Source revisions plus tenant event/source/revision keys prevent duplicate events, including repeated changes within one second and schedules moved back to an earlier time. Each recipient retains its stable operation key across retries. Configuration changes suppress earlier unsent revisions. Definite rejections use existing backoff; ambiguous acceptance is `unknown` and never automatically resent. Accepted/in-flight provider messages cannot be recalled after an appointment changes.

Validation uses fake providers and isolated databases:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter 'PatientEngagement|ClinicCommunicationsTest|CommunicationProviderTest|AppointmentWorkflowTest|TaskApiTest|TenantIsolationTest|CoreAuditCoverageTest'
```
