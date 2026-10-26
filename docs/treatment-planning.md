# Treatment planning API contract

Treatment plans belong to a patient in the selected clinic. All endpoints require Sanctum authentication and active clinic membership, accept the standard optional `X-Tenant-Id` header, and use the standard API envelope. Success responses are private/no-store. Foreign patients/plans/items and items under the wrong plan return 404; unauthorized clinic selection returns 403.

| Endpoint | Result |
| --- | --- |
| `GET /api/patients/{patient}/treatment-plans` | Newest-first plans with items; `data` is an array. Optional `status`, `page`, `per_page` (default 15, maximum 100); standard `meta.pagination`. |
| `POST /api/patients/{patient}/treatment-plans` | Create a draft, optionally with up to 100 initial items, atomically. Returns 201, `data.treatmentPlan`. |
| `GET /api/treatment-plans/{treatment_plan}` | Plan with all items in ascending `sequence`, then ID order; `data.treatmentPlan`. |
| `PATCH` or `PUT /api/treatment-plans/{treatment_plan}` | Partial update of title, notes or workflow status; `data.treatmentPlan`. |
| `POST /api/treatment-plans/{treatment_plan}/items` | Add one proposed item; returns 201, `data.treatmentPlanItem`. |
| `PATCH` or `PUT /api/treatment-plans/{treatment_plan}/items/{item}` | Partial item update; `data.treatmentPlanItem`. |

Create example:

```json
{
  "title": "Initial restorative care",
  "notes": "Discuss treatment options at next visit.",
  "items": [
    {
      "treatment_id": 2,
      "dentist_id": 3,
      "tooth_context": {
        "dentition": "adult",
        "numbering_system": "universal",
        "tooth": "3",
        "surfaces": ["M", "O"]
      },
      "estimated_price": "125.50",
      "sequence": 1,
      "priority": "high",
      "notes": null
    },
    {"treatment_id": 4, "dentist_id": 3}
  ]
}
```

Plan titles are required, nonblank and at most 255 characters; plan/item notes are nullable strings up to 10,000 characters. Empty drafts are allowed. Items always reference a clinic-local active catalog treatment and provider (`dentist_id`, distinct from the authenticated user). Multiple instances of a procedure are supported, including procedures on different teeth. Treatment and patient/plan ownership cannot be reassigned. All plans and items expose IDs, workflow status and creation/update timestamps. Plan responses include `patient_id`, `title`, `notes` and `items`; item responses include the fields below and `treatment_plan_id`.

At addition, the server captures immutable `catalog_price` and `treatment_name`. `estimated_price` defaults to that captured catalog price and may be changed independently while the item remains open. Prices are decimal strings with two fractional digits in responses. Estimates accept zero through 99,999,999.99 and at most two decimal places. Catalog price/name changes and deactivation never change existing item snapshots. Clients cannot supply snapshot fields or change an item's `treatment_id`; cancel the proposal and add a replacement. Ownership and snapshot fields are server-controlled; requests attempting to set them return 422.

`sequence` is an integer from 1 through 1,000,000; omission appends after the current maximum (initially 1). Ties are allowed and resolve by ID. `priority` is `low`, `normal` (default), `high`, or `urgent`. Priority is independent of presentation order. Clients may update sequence/priority on open items.

`tooth_context` may be omitted or null for general procedures. A non-null context requires `dentition: adult|primary` and `tooth`; `numbering_system` defaults to `universal`. Universal adult 1–32/primary A–T and explicit FDI use the existing [odontogram vocabulary](dental-charting.md). Responses always return canonical FDI with `numbering_system: fdi`; clients must retain that marker when reusing a response as input. `surfaces` defaults to `[]` for whole-tooth procedures. M/D/B/L and tooth-appropriate O or I are accepted; F/P aliases normalize to B/L. Duplicate normalized surfaces and invalid tooth/surface combinations return 422. A context update replaces the entire context; null clears it. Planning does not modify or require an existing chart revision and does not infer clinical findings or tooth presence.

Workflow transitions:

| Record | Allowed transitions |
| --- | --- |
| Plan | `draft → active`, `draft → cancelled`, `active → completed`, `active → cancelled` |
| Item | `proposed → in_progress|completed|cancelled`, `in_progress → completed|cancelled` |

Creation always produces `draft` plans and `proposed` items; creation payloads must omit `status`. Activation requires at least one proposed item. An item may enter in_progress/completed only within an active plan. Completed plans require at least one completed item and no unfinished items; cancelled items do not prevent completion. Cancelling a plan cancels each proposed/in_progress item in the same transaction, leaving completed items intact. Cancelled/completed plans and items are read-only. Invalid transitions return 422 / `VALIDATION_ERROR`. Repeating the current status on an open record is a no-op. The server never automatically completes the plan; clients explicitly submit the final status. There are no destructive delete endpoints: cancellation retains planning history. Archived patient plans remain readable but all mutations return 422.

Clinic Admins and Dentists can read and write. A Dentist with Receptionist is denied clinical writes unless also Admin, following clinical-note/odontogram policy. Explicit `treatment_plans.view` (including normal read wildcards) permits read-only access. `treatment_plans.create`, `treatment_plans.update`, or global permission wildcards do not grant clinical write authority without the clinical role. Permission seeding adds the three concrete slugs to the vocabulary and the selected clinic's Admin role without granting other roles new privileges. Existing clinical roles work without reseeding. Every write rechecks authorization in the workflow service and reloads records under the trusted tenant scope.

Each changed plan/item writes one metadata-only `created` or `updated` audit with resource type `treatment_plans` or `treatment_plan_items`, resource ID, authenticated actor and standard request context. Cancellation audits every affected record. Audit and domain writes commit or roll back together; reads, no-op edits and rejected requests produce no audits. Clinical context and notes are never copied to audit metadata. Existing audit endpoints retain their own permissions. Mutations lock the patient, then plan, serializing lifecycle and item changes; concurrent edits to the same open field use the last accepted value. There is no optimistic version contract in this milestone.

Deploy with `php artisan migrate` under the existing tenant migration procedure. The additive migration supports SQLite/MySQL and creates restrictive foreign keys, tenant query indexes, and database guards for clinic-local relationships, stable ownership and immutable price/name snapshots. MySQL deployment credentials need TRIGGER privileges; rehearse against a restored copy as MySQL DDL may commit partially. Provider deletion with planning history returns 422. Populated rollback is refused to preserve history; export/back up and plan explicit recovery before removing this schema. Empty-table rollback is supported. Do not roll back dependency migrations while this schema exists.

Validation: `php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --filter TreatmentPlanApiTest` when the local CLI has SQLite disabled; normal configured environments can run `vendor/bin/phpunit`. The repository CI also runs the suite on MySQL 8.
