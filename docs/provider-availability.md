# Provider availability

Providers are existing `dentist` records in the selected clinic. Availability follows the existing clinic/provider architecture; rooms and chairs remain optional exclusive appointment resources. There are no separate room/chair hours or multi-location assignments.

| Method | Endpoint | Permission (or clinic admin) |
| --- | --- | --- |
| GET | `/api/dentists/{dentist}/availability` | `provider_availabilities.view` |
| PUT | `/api/dentists/{dentist}/availability` | `provider_availabilities.update` |

Both routes require Sanctum authentication, active clinic membership, and the standard clinic selection header rules. Foreign-clinic providers return 404. Permission provisioning uses `php artisan db:seed --class=PermissionSeeder`; existing non-admin roles require explicit grants. Booking permission alone does not allow schedule edits.

PUT replaces the complete configuration. All three lists are required, including empty lists. GET and PUT return the standard envelope with `data.availability` containing `dentist_id`, `configured`, `timezone`, and the three lists:

```json
{
  "weekly_hours": [
    {"day_of_week": 2, "start_time": "09:00", "end_time": "17:00"}
  ],
  "breaks": [
    {"day_of_week": 2, "start_time": "12:00", "end_time": "13:00"}
  ],
  "exceptions": [
    {"date": "2026-11-03", "working_hours": [], "breaks": []},
    {
      "date": "2026-11-04",
      "working_hours": [{"start_time": "10:00", "end_time": "15:00"}],
      "breaks": [{"start_time": "11:00", "end_time": "12:00"}]
    }
  ]
}
```

Days run from 0 (Sunday) to 6 (Saturday). Rules use `config('app.timezone')`, currently UTC, returned as `timezone`. Clients should convert display times to that timezone. Appointment timestamps with explicit offsets are normalized by the existing appointment workflow before evaluation. The timezone and ownership fields cannot be set through this API.

Intervals are half-open and must have increasing `HH:mm` endpoints. `24:00` is allowed only as an end. Split overnight hours/breaks into separate days. Adjacent or overlapping working windows form continuous coverage; gaps remain unavailable. A break blocks any intersecting portion of an appointment, including when an appointment contains the entire break. Ending at a break start or starting at its end is allowed.

Each exception replaces both the weekly hours and recurring breaks for its date. An empty `working_hours` list closes the full day. Partial time off uses that date's working windows and breaks. Exceptions can also open an otherwise closed weekday. Dates must be unique. Remove an exception from the replacement payload to restore that date's weekly rules. Unlisted weekdays are closed once a schedule is configured. Lists are bounded to 100 weekly working windows, 100 recurring breaks, 366 exceptions, and 24 windows/breaks per exception. Intervals accept only the documented fields.

A provider without a saved configuration returns `configured: false` and empty lists; bookings keep the Phase 1 overlap-only behavior. This preserves existing providers during rollout. Saving even an entirely empty configuration enables enforcement and closes all dates until hours or exceptions are added. No implicit default hours are created. Configure every bookable provider to complete rollout.

Appointment creation and active schedule changes validate the entire duration, including each date crossed at midnight. Changes to provider, start, duration, treatment, room, or chair trigger validation. A newly selected treatment's effective duration participates. Rejected requests return 422 / `VALIDATION_ERROR` with `dentist_id` and `appointment_date_time` errors, and leave the persisted appointment unchanged. Existing provider, room, and chair overlap validation remains in force. Terminal appointments retain their existing no-rescheduling rule.

Schedule edits preserve existing bookings. Notes and status-only changes (including check-in, completion, and cancellation) do not revalidate working hours, so staff can finish or cancel appointments affected by later schedule changes. Existing overlap rules for active saves still apply. Schedule PUT is replacement with the last committed edit winning; clients editing concurrently should reload before saving.

Schedule writes and bookings lock the provider row within their transactions. Availability reads during bookings are current locking reads on MySQL. Writes append minimal `provider_availabilities` / configuration ID audit records with `created` or `updated` and the performing actor. Identical configurations emit no audit. Schedule contents are not copied into audit metadata. Audit failure rolls back the schedule mutation. Clinic scopes and model/database ownership guards protect the configuration.

Deploy the schema with `php artisan migrate` and provision the permission catalog separately. The migration adds a table and does not rewrite appointments or seed working hours. Feature coverage is in `tests/Feature/Api/ProviderAvailabilityTest.php`. Run `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter ProviderAvailabilityTest` when local CLI SQLite extensions are disabled. SQLite tests verify rule behavior; they do not prove MySQL concurrency behavior.
