# Patient communication consent

Patient consent is a separate append-only ledger, not a field on a message, an address verification, or a staff send confirmation. Run `php artisan migrate` to install it. No new permission seed is required: staff must hold the exact existing `messaging.preferences.manage` permission to record or review consent. Wildcard CRUD grants do not substitute for it. Admin and patient roles cannot use the staff endpoints; patient portal access remains deferred.

All endpoints require Sanctum authentication, an active tenant membership and the existing clinic context. Patient IDs are tenant-scoped, including current/history reads. Attribution is server-controlled: `captured_by` is the authenticated staff user and `captured_at` is the server capture time in UTC. New records require the policy text/version acknowledged and evidence of the patient's choice.

Attribution also retains `capture_actor_type`. Staff endpoints can only create staff-attributed records. The schema separately supports a future authenticated patient actor through `captured_by_patient_id` matching the subject patient and `source=patient_portal`; no staff endpoint can set these fields or impersonate that actor. Portal authentication and patient update routes remain deferred.

| Purpose | Scope | Examples |
| --- | --- | --- |
| `transactional` | `appointments` | Confirmations, reminders, reschedules and cancellations |
| `transactional` | `billing` | Billing messages and payment-link notices |
| `transactional` | `payment_receipts` | Receipts for authoritative recorded payments |
| `clinical` | `follow_up` | Care discussions and patient follow-up reminders |
| `general` | `non_essential` | General and non-essential messages |

Each scope is independent for `in_app`, `email` and `sms`. There is no blanket grant, purpose fallback or cross-channel fallback. In-app threads use `care` → clinical/follow_up, `billing` → transactional/billing, `appointments` → transactional/appointments, and `general` → general/non_essential. Only supported provider events dispatch email/SMS; recording a preference does not create a new delivery provider or enable campaigns.

## Record and review

`POST /api/patients/{patient}/communication-consents` appends evidence and returns 201 with `data.consent`:

```json
{
  "purpose": "clinical",
  "scope": "follow_up",
  "channel": "in_app",
  "status": "granted",
  "source": "written_form",
  "text_version": "care-policy-v2",
  "acknowledged_text": "I agree to receive clinical follow-up messages through the patient portal.",
  "evidence": "Signed patient form REF-23, reviewed in person."
}
```

Statuses are `granted`, `declined`, `withdrawn` and `revoked`. Only granted permits sending. Each change creates the next integer `version` for that patient/purpose/scope/channel tuple under a tenant database lock and unique constraint. The policy `text_version` identifies the acknowledged wording separately from that sequence number. Sources are `in_person`, `written_form`, `phone` and `patient_request`. Staff must record the patient's actual choice; the endpoint does not independently authenticate a patient signature or callback. Evidence and policy wording are retained and never overwritten or deleted.

`GET /api/patients/{patient}/communication-consents` returns newest-first history as an array in `data`, with `meta.pagination`. Optional `purpose`, `channel`, `page` and `per_page` (1–100, default 25) filter/paginate it. Each entry includes source, capture attribution/time, policy text/version, evidence and status/version. Authenticated portal code can later expose these patient-scoped records with its own patient authorization boundary.

`GET /api/patients/{patient}/communication-consents/current?purpose=clinical&scope=follow_up&channel=in_app` returns `data.consent`, the effective decision. It includes `allowed`, `reason`, patient/purpose/scope/channel, consent ID, status/version, text version, source and capture attribution/time. Latest server sequence wins, not a caller-supplied timestamp. Missing consent defaults to denial. Successful resolution returns 200 even for a denied choice; this endpoint does not send.

## Send-time enforcement

In-app sends resolve current patient consent independently of the user's explicit send confirmation. A message prepared while consent is granted is blocked if consent is withdrawn before sending. A later grant is evaluated at send time and its exact version is captured. `send_confirmed=true`, `consent_acknowledged=true`, a valid confirmation token, or a verified contact cannot create or replace patient consent.

Blocked in-app operations return HTTP 403 with `code=COMMUNICATION_CONSENT_BLOCKED`. `errors.consent` contains the decision and a stable reason: `consent_missing`, `consent_declined`, `consent_withdrawn` or `consent_revoked`. Thread authorization and tenant checks still run before exposing consent details. Patient message audit metadata stores `consent_decision_id` and a `patient_consent` decision snapshot separately from staff confirmation ID/time and acknowledgement flags.

Email/SMS deliveries still need enabled configuration, eligible source, authorized event/contact and verified destination. Immediately before claiming a provider attempt, the worker resolves the event's purpose/scope/channel and stores an immutable decision. A denied decision suppresses the queued delivery without calling a provider; `failure_reason` contains the consent reason. Each retry resolves consent again. `notification_deliveries.consent_decision_id` points to the latest attempt's decision; the independent decision ledger retains prior attempt snapshots. Accepted messages are never resent merely because consent changes. Consent is evaluated at dispatch claim time; withdrawal cannot recall an already claimed or accepted provider request.

Configured internal billing-copy users receive internal operational copies under the existing staff/configuration controls. Those are not patient-directed deliveries. Patient and authorized billing-contact deliveries require patient consent. Contact verification and event-level disclosure restrictions remain additional checks; revoking a contact still prevents delivery even when patient consent remains granted.

Consent evidence changes, operational-basis records, and captured provider/in-app decisions produce tenant-local audit entries. Audit logs contain IDs and machine-readable actions, not patient evidence or policy text. SQL guards protect consent evidence and decision snapshots from mutation and cross-tenant relationships.

Removing a clinic member retains their global identity when consent capture, dispatch decisions or operational-basis history references it, preserving attribution after membership removal.

## Operational notices

Emergency/legal notices use separate append-only `operational_notice_bases`, never synthetic patient grants. Authorized staff can record them with `POST /api/patients/{patient}/operational-notice-bases`:

```json
{"channel":"sms","basis":"legal_obligation","reason":"Specific required notice","authority_reference":"Reviewed authority or policy reference"}
```

`basis` is `emergency` or `legal_obligation`. The response explicitly reports `dispatch_authorized=false`. GET on the same path provides paginated basis history. These records document a claimed operational basis; no current send path reads them as a bypass. Actual exceptional dispatch requires a separately authorized workflow and policy review. Ordinary appointment/billing notices continue to use transactional consent even when staff describe them as urgent.

## Existing data and compatibility

The migration preserves existing in-app preference evidence as version 1 with `source=legacy_import`, the original recorder/time and `legacy_preference_id`. Original acknowledged wording/version were unavailable, so `acknowledged_text` is null and `text_version=legacy_unversioned`; no historical wording is invented. Previously overwritten evidence cannot be reconstructed. The old preference table is frozen against all writes after import.

The existing GET messaging-preferences endpoint now reads the latest in-app ledger records. Its PUT adapter appends care/billing consent and additionally requires `source`, `text_version` and `acknowledged_text`; `allowed=true` maps to granted and false to withdrawn. Use the canonical POST API to distinguish all four states and other channels/scopes.

Verified email/SMS contacts are not automatically converted into patient consent. Existing queued deliveries without explicit matching consent are suppressed at dispatch with `consent_missing`. Record actual patient consent through the authorized endpoint before enabling those sends. A schema rollback refuses to discard any retained consent, decision or operational-basis evidence.
