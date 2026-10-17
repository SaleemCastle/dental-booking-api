# Clinical notes (M5)

Clinical notes use the standard API envelope and Sanctum authentication. `GET /api/clinical-notes`, `POST /api/clinical-notes`, and `GET`, `PATCH`, `PUT`, `DELETE /api/clinical-notes/{clinical_note}` provide CRUD.

## Authorization

| User | Read | Create, edit, delete |
| --- | --- | --- |
| Admin | Allowed | Allowed |
| Dentist without receptionist role | Allowed | Allowed |
| Receptionist | Requires `clinical_notes.view` | Denied |
| Other roles/users | Requires `clinical_notes.view` | Denied |

Read permissions support the existing resource/action wildcard patterns. Write access requires the clinical role even when a user holds `clinical_notes.*`, `*.update`, `*`, or direct Gate ability permissions. The clinical-note policy handles these decisions without the general permission Gate shortcut. A receptionist/dentist role combination still denies writes; admin remains an explicit override.

Dentists may collaborate on any clinic note. The original author stays unchanged when another authorized dentist edits the note, and the last editor records who made the change. Provider identity is the selected `dentist_id` record; it is separate from the authenticated author's user ID. No email-based mapping between users and provider records is assumed.

## Relationships and validation

Create requires `patient_id`, `dentist_id`, and nonblank `note_text` (at most 16,000 characters). IDs must refer to existing records. `appointment_id` is optional and nullable, allowing patient-level notes outside an appointment. When an appointment is linked, both patient and provider must match that appointment. Notes can document archived patients and appointments in any status.

```json
{
  "patient_id": 1,
  "dentist_id": 2,
  "appointment_id": 3,
  "note_text": "Patient reported sensitivity."
}
```

PATCH and PUT both support partial updates. Omitted fields are retained, including the appointment. Explicit `appointment_id: null` unlinks the note. Supplying a new appointment without updating a conflicting patient/provider returns 422; supply a consistent set of relationship IDs together. Relationship errors use the canonical `patient_id` and `dentist_id` keys. Validation failures leave the original note unchanged.

New notes capture `author_user_id` and `updated_by_user_id` from the authenticated user. Authorship is immutable. Changed notes update `updated_by_user_id`; no-op updates retain existing metadata. Responses include canonical integer IDs, `authorName`, `updatedByName`, and ISO 8601 UTC timestamps. Client-supplied attribution and timestamp fields (`author_user_id`, `updated_by_user_id`, `deleted_by_user_id`, `deleted_at`, `created_at`, `updated_at`) are rejected, including explicit null values.

List filters accept `patient_id`, `dentist_id`, and `appointment_id` together. Pagination uses `per_page` (default 15, maximum 100) and `page` (minimum 1), returning `meta.pagination` with the same fields as the patient API. Notes are ordered newest first by `created_at`, then ID. Soft-deleted notes are excluded from lists and route binding; missing/deleted note IDs return 404.

## History and audit integration

DELETE soft-deletes the note and records the authenticated `deleted_by_user_id`, last editor, and deletion timestamp. The text, author, provider, patient, and appointment references remain stored. A repeated DELETE returns 404; restoration and permanent deletion are outside this API contract.

Appointments with notes cannot be reassigned to another patient/provider or physically deleted. Providers with notes cannot be physically deleted through the provider API. These guards include soft-deleted notes. Patient archival retains notes. User foreign keys protect recorded author/editor/deleter accounts against physical deletion.

The additive attribution migration preserves existing notes and leaves unknown legacy authors/editors null. Editing a legacy note records its new editor while preserving the unknown original author. Existing relationship data is retained; an inconsistent legacy appointment link must be corrected or explicitly detached before editing its note.

Each successful API mutation dispatches `App\Events\ClinicalNoteChanged` after its database transaction commits. Consumers can subscribe to this event to implement audit persistence. Its immutable payload contains:

- `noteId`: canonical clinical note ID.
- `action`: `created`, `updated`, or `deleted`.
- `actorId`: authenticated user performing the operation.
- `before`: previous persisted note attributes; empty on creation.
- `after`: resulting persisted attributes, including attribution, timestamps, and soft-deletion state.

Events contain snapshots rather than live model references, so queued consumers can retain the exact mutation state. Failed validation, denied authorization, no-op updates, and rolled-back transactions emit no mutation event. Note mutations and relationship checks run in transactions; linked appointments and parent records are locked while validating.

## Verification

Feature tests cover CRUD for dentists/admins, unauthenticated access, receptionist and wildcard bypass attempts, read-only review, optional appointments, relationship mismatches on create/update, protected attribution, filtering/pagination, soft deletion, parent history protection, legacy attribution, and commit/rollback audit events.

Run tests against a dedicated test database. `RefreshDatabase` resets its schema; the development database should remain separate.
