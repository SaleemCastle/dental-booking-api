# Follow-ups and tasks (M6)

Tasks support patient follow-ups and general staff work. All endpoints require Sanctum authentication and use the standard API envelope.

| Endpoint | Permission |
| --- | --- |
| `GET /api/tasks` | `tasks.view` |
| `GET /api/tasks/{task}` | `tasks.view` |
| `POST /api/tasks` | `tasks.create` |
| `PATCH /api/tasks/{task}`, `PUT /api/tasks/{task}` | `tasks.update` |
| `PATCH /api/tasks/{task}/status` | `tasks.update` |
| `DELETE /api/tasks/{task}` | `tasks.delete` |

The admin role can perform all operations. Resource/action wildcard permissions follow M1 conventions. Assignment and other staff roles grant no additional task access. Read permission covers clinic tasks; `assigned_to_me` narrows that authorized list to the current user.

## Fields and relationships

Create requires an existing `assignee_id`, `due_date`, and nonblank `description` (maximum 16,000 characters). `due_date` is an exact `YYYY-MM-DD` calendar date, including past dates for overdue work. Date-time strings are rejected. Assignees reference users independently of their role or task permissions.

`priority` accepts `low`, `normal`, `high`, or `urgent`, defaulting to `normal`. `status` accepts `pending`, `in_progress`, `completed`, or `cancelled`, defaulting to `pending`.

`patient_id` and `appointment_id` are optional and nullable. A patient-only task and a general task with neither link are valid. When selecting an appointment, omitting `patient_id` derives its patient. Explicitly supplying a different patient or null while an appointment is linked returns 422 with a `patient_id` error.

```json
{
  "patient_id": 1,
  "appointment_id": 2,
  "assignee_id": 3,
  "due_date": "2026-10-20",
  "priority": "high",
  "description": "Call patient after treatment."
}
```

PATCH and PUT both accept partial updates and preserve omitted fields. Selecting a different appointment without supplying a patient derives the new patient. Clearing only the appointment retains the patient; clear both IDs to make a general task. Updating the patient while retaining a conflicting appointment is rejected. Relationship checks and writes are transactional, including current-record and parent locks. Invalid updates leave the stored task unchanged.

Responses use `data.task` for a single task and `data.tasks` for lists. IDs are canonical integers, with null for absent links. Display fields include `patientName`, `assigneeName`, `appointmentStartsAt`, and `is_overdue`. Response timestamps use ISO 8601 UTC; `due_date` remains date-only.

## Status changes

Both general updates and the dedicated status endpoint accept every declared status, including reopening completed or cancelled work. The dedicated endpoint requires `status` and rejects other task fields or server-managed timestamps.

```json
{"status":"completed"}
```

Entering `completed` sets `completed_at` on the server. Repeating completion or editing other fields while completed preserves the timestamp. Leaving completed clears it; completing the reopened task records a new completion time. Creation with an explicit valid status is supported, including completed tasks. Client-supplied `completed_at`, `created_at`, and `updated_at` are rejected, including explicit null values.

DELETE physically removes the task; subsequent requests return 404. Setting status to `cancelled` retains the task.

## Filters

| Query | Behavior |
| --- | --- |
| `assigned_to_me=1` | Only tasks assigned to the authenticated user. |
| `overdue=1` | Pending/in-progress tasks whose due date is before today. |
| `due_today=1` | Pending/in-progress tasks whose due date is today. |
| `patient_id=1` | Only tasks linked to that patient. |
| `status=completed` | Only tasks with the declared status. |

Boolean toggles accept `1` or `0`; `0` disables the toggle. Other strings, including `true` and `false`, are rejected. Date filters use the application's calendar date (UTC); today is not a rolling 24-hour window. Completed and cancelled tasks are excluded from overdue/due-today results and always have `is_overdue: false`.

Filters combine with AND before pagination. Combining overdue and due-today yields an empty list. Without date filters, the list includes all statuses. Example:

`GET /api/tasks?assigned_to_me=1&overdue=1&patient_id=1&per_page=15&page=1`

Results are ordered by due date ascending, then ID ascending. Pagination uses `per_page` (default 15, maximum 100) and `page` (minimum 1), returning `meta.pagination.currentPage`, `perPage`, `total`, `lastPage`, `from`, `to`, and `hasMorePages`.

## Parent lifecycle

Patient archival retains tasks. An appointment linked to tasks cannot be reassigned to another patient; detach or relink those tasks first. Appointment deletion clears each task's optional appointment link while preserving its patient and other fields. Foreign keys protect referenced patients and assignees against physical deletion.

## Verification

Feature tests cover CRUD, authorization, independent and combined filters, pagination, midnight boundaries, validation, relationship consistency, status changes/reopening, completion timestamp retries, and parent lifecycle behavior. Run tests against a dedicated test database; `RefreshDatabase` resets its schema.

## Patient reminders

`patient_reminder_at` is an optional nullable timestamp on creation and general updates. A supplied timestamp must be in the future. Set it explicitly to schedule a patient follow-up; a due date alone never sends a notification. A linked patient, an enabled tenant `task.reminder` event and a verified patient contact authorized for that event are required for delivery. Null clears the reminder. Status-only updates cannot set this field. Completion, cancellation, deletion, reassignment and reminder timing changes suppress the prior pending/retry messages. See [patient engagement](patient-engagement.md) for configuration and history.
