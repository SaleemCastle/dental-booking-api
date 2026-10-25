# Dental charting contract

Charts are clinic-owned clinical records, grouped by patient and dentition (`adult` or `primary`). Each accepted mutation appends a complete immutable snapshot in `dental_chart_revisions`. There is no destructive update/delete endpoint and no inferred healthy state for teeth not yet charted. An uncharted dentition reads as version `0`, `teeth: []`, and null attribution; GET never writes records or audits. Patients may have both dentitions recorded independently, including during mixed dentition; no age-based replacement/conversion occurs.

## Numbering and surfaces

The product uses Universal numbering by default: adult teeth 1–32 and primary teeth A–T. An explicit `numbering_system: fdi` also supports adult quadrants 1–4, positions 1–8 (32 teeth), and primary quadrants 5–8, positions 1–5 (20 teeth). A request without `numbering_system` always uses Universal; the system is never guessed from a number. Storage is canonical FDI; choosing another display system does not create a revision or reinterpret saved history. Numbering mappings follow the [ADA Universal/ISO designation reference](https://www.ada.org/-/media/project/ada-organization/ada/ada-org/files/publications/cdt/universal_tooth_designation_system_valueset_2.pdf). Supernumerary teeth are outside this initial contract.

`GET /api/dental-chart/metadata` returns all valid adult/primary positions in Universal numbering, their canonical FDI IDs and allowed surfaces, presence statuses, condition suggestions, and surface aliases. An optional `numbering_system=fdi` selects FDI labels. Surfaces are M (mesial), D (distal), B (buccal/facial), L (lingual/palatal), O (occlusal), I (incisal). F and P are accepted aliases for B and L and normalize to them. Incisors/canines accept I, premolars/molars accept O; each tooth has five distinct surfaces. Duplicate normalized aliases, invalid dentition positions, and duplicate teeth are rejected.

Tooth `status` is `present`, `missing`, `extracted`, or `unerupted`. Findings have a machine-readable `code` (lowercase letter followed by lowercase letters/digits/underscores, up to 50 characters), `status: active|resolved`, and `surfaces: []` for a whole-tooth finding or a list of affected surfaces. `caries` and `restored` are supported suggestions, alongside extensible custom codes. Conditions can coexist on different surfaces; duplicate condition/surface sets on the same tooth are rejected. A finding may reference a clinic-local treatment and include a note up to 1,000 characters. Inactive treatments may remain referenced for historical accuracy.

## API and concurrency

All endpoints require Sanctum authentication, verified active clinic membership, the normal optional `X-Tenant-Id` header, and the standard API envelope. Successful clinical responses are `Cache-Control: private, no-store`.

| Endpoint | Behavior |
| --- | --- |
| `GET /api/patients/{patient}/dental-chart` | Current snapshot. Optional `dentition=adult` and `numbering_system=universal` (defaults shown). |
| `POST /api/patients/{patient}/dental-chart/revisions` | Append a full snapshot; returns 201 and `data.chart`. |
| `GET /api/patients/{patient}/dental-chart/history` | Newest-first revision summaries without tooth payloads. Same selection parameters plus `page`, `per_page` (default 10, max 50). Standard pagination metadata. |
| `GET /api/patients/{patient}/dental-chart/revisions/{version}` | Exact immutable historical snapshot for the selected dentition, rendered in the requested numbering system. Unknown versions return 404. |

Example mutation (JSON tooth values can also be integers for numeric systems):

```json
{
  "dentition": "adult",
  "numbering_system": "universal",
  "expected_version": 0,
  "dentist_id": 3,
  "clinical_note_id": null,
  "reason": "Initial examination",
  "teeth": [
    {
      "tooth": "3",
      "status": "present",
      "findings": [
        {"code": "caries", "status": "active", "surfaces": ["M", "O"], "treatment_id": 2, "note": null},
        {"code": "restored", "status": "active", "surfaces": ["B"]}
      ]
    },
    {"tooth": "1", "status": "missing", "findings": []}
  ]
}
```

Send the complete state of all charted teeth, not a patch. Omitting a tooth from the next snapshot removes it from the current projection while preserving every prior revision. An explicit empty list is permitted for a documented correction. Each accepted submission is a new observation, even when its state matches the prior snapshot. Maximum 32 charted positions, 20 findings per tooth, five surfaces per finding, and a mandatory reason up to 1,000 characters apply.

`expected_version` is required and must match the currently selected patient/dentition version, starting at 0. The server locks the patient before checking the latest revision and appends version N+1; a unique `(tenant_id, patient_id, dentition, version)` constraint is the final race guard. A stale submission returns 409 / `CONFLICT` without writing a revision or audit. Reload and reconcile before resubmitting; never blindly overwrite a newer chart. Client timestamps, revision IDs/versions, patient IDs, author IDs, and ownership fields are not accepted as writable attribution. The server supplies tenant ownership, authenticated actor, version, and UTC timestamp. Providers are clinic-local dentist records and are distinct from the authenticated user identity.

## Clinical authorization, history, and audit

Clinic Admins and Dentists may read and append. A Dentist who also has Receptionist is denied clinical writes unless they are also Admin, matching clinical-note policy. Non-clinical permission wildcards and `dental_charts.create` alone cannot bypass that restriction. Explicit `dental_charts.view` permits read-only access. Permission seeding adds `dental_charts.view` and `dental_charts.create` to the shared vocabulary and the selected clinic's admin role; it does not grant other roles clinical authority. Existing admins/dentists work without reseeding through their clinical roles.

Foreign patients/routes return 404 and unauthorized clinic selection returns 403. Provider, clinical-note, and treatment references must belong to the selected clinic. A linked clinical note must be active and match both patient and provider. The workflow repeats authorization/validation and reloads patient/provider/note under the trusted tenant context, including for direct service callers. Archived patient charts remain readable but cannot receive new revisions (422).

Each revision stores patient, dentition, version, provider, authenticated author, optional note link, canonical tooth snapshot, reason, and UTC recording timestamp. Models/builders reject update/delete and database triggers reject raw UPDATE/DELETE. Insert guards also reject reused revision IDs or versions before SQL REPLACE can replace history. Foreign keys prevent physical deletion of referenced clinical identities; provider deletion reports 422, patient archival preserves history, and removing the final clinic membership of an author retains their global account so attribution remains valid. Historical note links remain available after soft deletion. Database insert guards enforce clinic-local patient/provider/author and matching note links; treatment references inside JSON are validated by the application. Raw administrative SQL and TRUNCATE remain privileged maintenance operations, never request paths.

The transaction appends exactly one metadata-only `dental_charts/revision_created` audit, with the new revision ID as resource ID and the authenticated actor. It never copies the snapshot, condition codes, reason, or clinical notes into the audit metadata. Revision and audit succeed or roll back together. Reads, rejected writes, and stale submissions append no audit. Existing audit endpoints remain the only audit read API and retain their own role restrictions.

## Deployment and recovery

Run normal `php artisan migrate` with request/worker writes paused under the existing multi-tenancy deployment procedure. The additive migration creates only the revision table, restrictive foreign keys, query/version indexes, and insert/immutability triggers; it does not rewrite Phase 1 data. MySQL deployment credentials need TRIGGER privileges. Rehearse MySQL schema changes on a restored copy because DDL can commit partially; restore the backup or inventory this migration's named table/triggers before retrying a partial deployment.

Empty-table rollback is supported. `down()` refuses to drop a table containing chart history. Export and back up history and plan an explicit recovery before removing the domain; do not bypass the guard with destructive resets. Undo the chart migration before older tenant/foundation migrations. Tests validate populated history preservation, migration ordering, stale-version conflicts, attribution, authorization, tenant boundaries, audit atomicity, input validation, and model/raw-SQL immutability. Run `vendor/bin/phpunit --filter DentalChart`; the repository CI also exercises MySQL 8.
