# Appointment workflow (M4)

All appointment endpoints require Sanctum authentication. The admin role has access to all operations; other users need the corresponding permission:

| Operation | Permission |
| --- | --- |
| List, show, location selector | `appointments.view` |
| Create | `appointments.create` |
| Update, reschedule, check-in, complete, cancel | `appointments.update` |
| Delete | `appointments.delete` |

## Identifiers and display fields

`id`, `patient_id`, `dentist_id`, `treatment_id`, `room_id`, and `chair_id` are canonical integer database identifiers. `dentist_id` identifies the provider. Optional treatment and location identifiers may be null. Responses also include equivalent `patientId`, `dentistId`, `treatmentId`, `roomId`, and `chairId` aliases.

List, show, create, update, and action responses use the same appointment resource, including `patientName`, `dentistName`, `treatmentName`, `roomName`, `chairName`, `startsAt`, `endsAt`, `duration`, `appointmentStatus`, and `paymentStatus`. All response timestamps are ISO 8601 UTC. Offset input timestamps are converted to UTC before storage; inputs without an offset and the list's `date` filter use the application's timezone (UTC).

Create and update accept camelCase aliases: `patientId`, `dentistId`, `treatmentId`, `roomId`, `chairId`, `startsAt`, `appointmentType`, `durationMinutes`, and `appointmentStatus`. Canonical fields take precedence when both forms are supplied, including explicit nulls. PATCH and PUT both preserve omitted fields. Required fields cannot be cleared with null.

## Booking and rescheduling

Create requires a patient, provider, start, appointment type, and description. Status defaults to `scheduled`; other initial statuses are rejected. Duration must be 1–1440 whole minutes. When omitted, duration comes from the selected treatment or defaults to 30 minutes. Selecting a different treatment during an update also supplies its duration unless an explicit duration is provided. An unchanged treatment preserves the existing duration.

Archived patients and inactive treatments cannot be newly selected. Existing references remain readable and can be retained when updating notes, status, or schedule. Completed, cancelled, and no-show appointments cannot be rescheduled, although descriptive notes may be updated. Appointments with clinical notes (including soft-deleted notes) cannot be reassigned to a different patient/provider or physically deleted; these requests return 422 with an appointment_id error.

`GET /api/appointment-locations` returns rooms with nested chairs, ordered by name then ID. It lists inventory; availability is checked when saving. Room/chair records are stored locally. `php artisan db:seed --class=AppointmentLocationSeeder` creates two example rooms, each with a chair, idempotently; the main database seeder also calls it.

Rooms and chairs are optional for backward compatibility. A selected chair reserves its parent room. Supplying `chair_id` without `room_id` derives the room, including when changing chairs. Explicit room/chair combinations must match. Changing only the room while retaining a chair from another room is rejected; supply the new chair or clear `chair_id` as well. Clear both IDs to remove the location reservation.

Active statuses (`scheduled`, `checked_in`, `in_progress`) reserve the provider, room, and chair. Overlaps on any shared resource return HTTP 422 with `code: VALIDATION_ERROR`, errors under the conflicting `dentist_id`, `room_id`, or `chair_id`, and an `appointment_date_time` error. Intervals are half-open: one appointment may begin exactly when another ends. Cross-midnight and equivalent-offset overlaps are checked. Cancelled, completed, and no-show appointments do not reserve resources.

The same checks apply on create and update, including changes to duration, treatment, provider, room, or chair. Updates exclude their own appointment from the overlap query. Validation failure leaves the persisted appointment unchanged. Writes run inside database transactions; provider/location row locks serialize competing bookings on MySQL, and overlap reads use current locking reads. Deadlocked transactions retry up to three times. SQLite feature tests exercise the scheduling rules but do not establish MySQL concurrency behavior.

## Status transitions

| Current status | Allowed next statuses |
| --- | --- |
| `scheduled` | `checked_in`, `cancelled`, `no_show` |
| `checked_in` | `in_progress`, `cancelled` |
| `in_progress` | `completed`, `cancelled` |
| `completed` | None |
| `cancelled` | None |
| `no_show` | None |

Repeating the current status succeeds and preserves workflow timestamps. Other invalid transitions return HTTP 422 with a `status` validation error, including attempts to reopen terminal appointments through PUT/PATCH.

- `POST /api/appointments/{id}/check-in` transitions `scheduled` to `checked_in`, setting `checked_in_at`.
- `PATCH /api/appointments/{id}` with `{"status":"in_progress"}` starts a checked-in appointment.
- `POST /api/appointments/{id}/complete` transitions `in_progress` to `completed`, setting `completed_at`.
- `POST /api/appointments/{id}/cancel` cancels any active appointment and sets `cancelled_at`.
- `PATCH /api/appointments/{id}` with `{"status":"no_show"}` marks a scheduled appointment as a no-show.

Cancellation accepts optional nullable `cancellation_reason` or its alias `reason` (maximum 2000 characters). The same fields work with a generic update whose resulting status is `cancelled`. Repeating cancellation without a reason retains the existing reason; explicit null clears it. A reason on any non-cancelled appointment is rejected. Cancellation retains the appointment and releases its slot. DELETE remains a separately authorized physical deletion endpoint.

## Validation

Feature tests cover permissions, canonical identifiers and aliases, partial updates, provider and location overlaps, interval boundaries and midnight spillover, treatment-derived duration, cancellation, and every pair of workflow statuses. Run tests against a dedicated test database; `RefreshDatabase` resets its schema. Do not use the development database for test execution.
