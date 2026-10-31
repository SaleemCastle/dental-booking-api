# Transactional clinic communications

Patient engagement reuses this outbox, worker and verified contacts; see [patient engagement](patient-engagement.md) for appointment and follow-up events.

`ClinicNotificationService` queues `payment.recorded` receipts, `payment_link.created` checkout notices, and optional `payment_link.failed` / `payment_link.expired` notices. Manual payments, authoritative provider settlement (webhooks and reconciliation), and hosted checkout state changes call the same service. Provider calls run only in `communications:work`, never in a billing request. Clinics without an enabled configuration retain their existing billing behavior.

Success receipts require a recorded backend payment: browser redirects, client status parameters, unpaid sessions, invalid signatures and mismatched provider amounts cannot trigger them. Provider settlement uses `trigger=payment_provider` even when staff initiated a status lookup; ledger audits still attribute that lookup to staff. Receipts include the amount received and the invoice balance at settlement, including partial payments, and link directly to the invoice, payment and hosted link when present. Repeated webhook event IDs and distinct success events for the same provider payment produce one receipt event.

Failed/expired notices require both an event rule and an explicitly authorized verified patient contact (or an eligible configured internal copy). They are emitted for signed terminal webhooks, authoritative status lookups, terminal creation responses and definite checkout-creation failures. Ambiguous creation timeouts remain `unknown` and never produce failure notices. An expiry timestamp alone suppresses a stale checkout invitation; it does not establish a provider outcome or generate an expiry notice. Reconcile with the provider or receive its signed webhook to establish expiry. Pending/cancelled links do not generate these notices. A later confirmed payment or replacement checkout suppresses an unsent terminal notice.

## Provisioning and operation

Run the additive migration with `php artisan migrate`. No cloud resources or external messages are created by the migration. Configure each active clinic with `php artisan communications:configure CLINIC_ID`. The command asks for JSON through a hidden prompt, so credentials do not enter shell history or process arguments. This is a trusted operator command, not an HTTP administration endpoint. Example configuration (replace every example credential/address):

```json
{
  "channels": {
    "email": {
      "provider": "smtp",
      "sender": "billing@clinic.example",
      "sender_name": "Clinic billing",
      "host": "smtp.clinic.example",
      "port": 465,
      "username": "clinic-billing",
      "password": "REPLACE_IN_HIDDEN_PROMPT"
    },
    "sms": {
      "provider": "twilio",
      "sender": "+15555550100",
      "account_sid": "AC00000000000000000000000000000000",
      "auth_token": "REPLACE_IN_HIDDEN_PROMPT"
    }
  },
  "events": {
    "payment.recorded": {"channels": ["email", "sms"], "copy_user_ids": []},
    "payment_link.created": {"channels": ["email"], "copy_user_ids": []},
    "payment_link.failed": {"channels": ["email", "sms"], "copy_user_ids": []},
    "payment_link.expired": {"channels": ["email"], "copy_user_ids": []}
  }
}
```

Omit unused channels and events. Event channel lists must name provisioned channels. SMTP uses implicit TLS, including certificate verification; use an implicit TLS SMTP endpoint (usually port 465). SMTP credentials, Twilio credentials, sender identity, event rules and template overrides belong to this clinic. SMTP creates a fresh transport for each delivery and does not inherit application-wide mail redirects, CC/BCC or credentials. SMS uses Twilio's Messages API with that clinic's credentials and E.164 sender. See the [Twilio Messages resource](https://www.twilio.com/docs/messaging/api/message-resource) for provider setup and status semantics.

Install the normal Laravel scheduler cron (`php artisan schedule:run` every minute), or run `php artisan communications:work --limit=100` from a supervised scheduler. The database outbox **is** the durable queue; it does not depend on `QUEUE_CONNECTION`, and `sync` cannot cause inline sends. The limit applies per clinic and per delivery/status batch. Scheduler overlap prevention and locked row claims prevent concurrent sends of the same delivery. Workers enumerate active clinics through a trusted orchestration path and restore context after every clinic, send and failure. They carry scalar IDs, never serialized clinical models. Keep scheduler intervals and batch limits appropriate to provider latency and clinic volume.

Disable a clinic with `php artisan communications:configure CLINIC_ID --disable`. Disabling works even if configured copy staff have since left. Reconfiguration increments a revision; unsent messages from earlier revisions are suppressed rather than being silently sent through another sender or credential set. Previously accepted SMS messages retain their acceptance timestamp/reference, but become `unknown` with reason `configuration_changed` when further confirmation would require another credential revision. No automatic backfill or historical resend occurs when a clinic is enabled or contacts change.

## Contacts and recipient authorization

Contact records are separate from free-text patient profiles, emergency contacts and global user emails. Use the authenticated, tenant-scoped endpoints:

| Endpoint | Purpose and permission |
| --- | --- |
| `GET /api/patients/{patient}/contacts` | List patient contacts, including addresses, with patient view permission. |
| `POST /api/patients/{patient}/contacts` | Create an unverified contact with patient update permission. |
| `POST /api/patients/{patient}/contacts/{contact}/verify` | Record staff verification with patient update permission. |
| `DELETE /api/patients/{patient}/contacts/{contact}` | Revoke the contact with patient update permission; preserve history. |
| `GET /api/invoices/{invoice}/communications` | Paginated event and delivery metadata with invoice view permission; no addresses, template variables, rendered bodies or checkout links. |

Creation accepts only `channel` (`email`/`sms`), `address`, `role` (`patient`/`billing_copy`) and `authorized_events` (one to nine supported events). SMS addresses must use E.164. Creation cannot set verification or ownership fields. Verification accepts `verification_method`: `in_person` or `verified_callback`. It is an **authorized staff attestation**, not an automated OTP or email verification service: staff must verify both control of the address and the patient's authorization to disclose billing details to it. Register a related payer/guardian as `billing_copy` only after confirming this relationship and event-specific authorization. Verification stores who, when and how, and appends an audit. Never verify a contact solely because it was submitted in a request.

Addresses, patient ownership, channel, role and event authorization cannot be edited in place. Revoke and create/verify a replacement. Revoked records cannot be reverified. Every eligible verified `patient` contact in an enabled event channel receives its own message. Verified `billing_copy` contacts receive only the events explicitly authorized for that patient. A contact attached to another patient is never selected, including inside the same clinic. Emergency contacts and referral addresses are never fallbacks.

Internal copies are configured by **user ID**, per event, rather than arbitrary destination strings. Configuration rejects foreign/nonmember users, unverified email addresses and users without current-clinic invoice read access. Selection and delivery recheck membership, verified email and billing permission. Changing a user's email clears its verification; the replacement cannot receive copies until verified. A configured user with another clinic's role does not inherit that role here. Internal copies use email only, and only when email is enabled for the event. Each recipient is a separate delivery; addresses are never exposed to other recipients through CC/BCC. Duplicate addresses within an event/channel are collapsed, with patient recipients taking precedence. Copy templates contain billing summary variables but never bearer checkout links.

Before each staff-triggered attempt, the worker reloads the initiating user, resolves active membership, and rechecks payment creation or checkout creation/refresh permission (terminal notices also allow checkout cancellation permission). Deleted users remain distinguishable from trusted system triggers and cannot fall back to system authority. Revoked contacts, changed addresses/permissions, archived patients, configuration changes and inactive clinics stop delivery. Inactive clinics' queue remains paused until reactivation. Checkout invitations for cancelled/completed/expired links are suppressed before sending. The trusted authoritative settlement path queues a receipt with a null actor; the event records `trigger=payment_provider` and references its payment/link history.

## Templates, persistence and idempotency

An event can override its plain-text `template`. Only `{{patient_name}}`, `{{invoice_number}}`, `{{amount}}`, `{{balance}}`, `{{payment_link}}` and `{{payment_status}}` are supported, with optional spaces inside braces. Unknown variables, malformed braces and HTML/PHP/Blade templates are rejected. Values are calculated from scoped billing records, substituted once and sent as text; values never execute as template code. Amounts/balances include invoice currency and exact decimal amounts. Copies use a fixed safe summary. No request can supply a destination list, rendered message, amount or checkout URL to this service.

Notification variables, contact addresses, delivery addresses and configuration are encrypted at rest with the application key. Recipient deduplication hashes use HMAC. Responses and audit entries exclude credentials, message bodies and contact addresses except the explicit patient contact API. Keep application keys and encrypted backups recoverable under the existing deployment policy.

Events and deliveries are inserted in the **same transaction** as the source payment or checkout state change. Rollback removes both. A unique tenant/event/source key and unique event/channel/recipient key prevent repeated manual requests, webhooks or reconciliations from generating another notification. Invoice locking serializes concurrent emission; delivery row locking and committed `processing` claims serialize workers. Adding a verified contact later does not replay an earlier event, including `no_eligible_recipients` events.

| Status | Meaning |
| --- | --- |
| `pending` | Durable outbox delivery, awaiting worker. |
| `processing` | Attempt claimed and committed before contacting provider. |
| `retry` | Provider definitively did not accept; retry after backoff. |
| `accepted` | SMTP accepted the message or Twilio created a message. This does not prove inbox/handset delivery. |
| `delivered` | Twilio subsequently confirmed delivery. SMTP remains `accepted`. |
| `failed` | Terminal provider rejection, downstream delivery failure or retry exhaustion. |
| `suppressed` | Authorization, recipient, configuration or checkout validity no longer permits delivery. |
| `unknown` | Acceptance/delivery could not be established safely. Never automatically resend. |

Definite SMTP connection/authentication failures and Twilio 429 rejections retry up to five attempts, after 60, 300, 900 and 3600 seconds. Every attempt rechecks recipient rules. SMTP send failures, HTTP timeouts/ambiguous responses and interrupted processing claims become `unknown`; SMTP/Twilio submission does not provide an exactly-once guarantee across a remote acceptance/local commit failure. A stable operation key and SMTP Message-ID aid investigation, but are not claimed to provide provider-side deduplication. Stale processing claims older than five minutes become `unknown`.

The worker polls accepted SMS messages every five minutes, using the original unchanged configuration revision and stored provider reference. Twilio delivery failures are recorded as `provider_delivery_failed`; provider error bodies are not stored or logged. Poll failures never resend a message. After seven days without a terminal confirmation, the status becomes `unknown`. SMTP has no inbox/bounce tracking in this implementation.

Events record trigger type, actor, source IDs and selection outcome. Deliveries record attempt count, timestamps, stable operation key, provider acceptance reference, status and sanitized failure reason. Append-only audit entries record queueing, every attempt, retry and outcome without patient/payment contents. Investigation of `unknown` messages must establish whether the provider accepted them before any deliberate new communication; there is no automatic resend endpoint.

## Validation and rollout

Run `vendor/bin/phpunit --filter 'ClinicCommunicationsTest|CommunicationProviderTest'` and the billing, checkout, reconciliation and tenant suites. On the supplied Windows PHP installation, enable the installed SQLite extension only for the test process:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter 'ClinicCommunicationsTest|CommunicationProviderTest'
```

Tests use an isolated in-memory database and fake provider boundaries. CI also runs MySQL 8. Validate production SMTP/Twilio credentials and test destinations deliberately before enabling clinic events; the test suite does not send live messages. Back up data before migration. Rollback refuses populated communication history. Apply ordinary `migrate` on deployed clinics; never `migrate:fresh`.
