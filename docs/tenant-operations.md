# Controlled single-clinic onboarding

Apply migrations with `php artisan migrate`. Existing clinics retain their active status and receive UTC/USD profile defaults; contact and branding fields remain unset. Every tenant represents one clinic.

## Operator access

Use trusted deployment shell access to grant an existing identity platform access:

```sh
php artisan platform:operator operator@example.com
php artisan platform:operator operator@example.com --revoke
```

No operator is seeded. This flag is excluded from mass assignment and user responses. Clinic roles, including `admin` and wildcard clinic permissions, cannot grant platform access. Clinic admins cannot change another operator's identity or delete operators. Operators authenticate through the existing Sanctum token/session flow. The platform middleware reloads the grant on every request, so revocation applies to existing tokens. An operator needs no clinic membership and gains no clinic data access through this grant.

## API

All routes use the standard API response envelope and require an authenticated platform operator. `X-Tenant-Id` is unnecessary for these platform routes.

| Method | Route | Result |
| --- | --- | --- |
| GET | `/api/platform/tenants` | All clinic profiles, 25 per page, including suspended clinics |
| GET | `/api/platform/tenants/{id}` | `data.tenant` profile |
| POST | `/api/platform/tenants` | Create clinic, admin identity, membership, roles and audit atomically |
| PATCH | `/api/platform/tenants/{id}/status` | Activate or suspend with `{"status":"active"}` or `{"status":"suspended"}` |

Example provisioning body:

```json
{
  "idempotency_key": "onboarding-clinic-2026-001",
  "name": "Independent Dental Clinic",
  "slug": "independent-dental",
  "contact_email": "clinic@example.com",
  "contact_phone": "+571234567890",
  "address": "Clinic street address",
  "timezone": "America/Bogota",
  "currency": "COP",
  "branding": {
    "display_name": "Independent Dental",
    "primary_color": "#123abc",
    "logo_url": "https://example.com/clinic-logo.png"
  },
  "admin": {
    "name": "Initial Clinic Admin",
    "email": "owner@example.com",
    "password": "Use-a-unique-secret-123"
  }
}
```

Required fields are `idempotency_key`, `name`, `slug`, `contact_email`, `timezone`, `currency`, and all three `admin` fields. Timezones must be recognized identifiers; currency is a three-uppercase-letter code. Branding is optional and accepts only display name, HTTPS logo URL and six-digit hex color. Logo URLs are stored as references and never fetched by the backend. Passwords must contain 12–72 characters. The response contains `data.tenant` and `data.initial_admin_id`, never the password or idempotency fingerprint. A new request returns 201; an identical retry returns 200. Duplicate clinic slugs or existing admin emails return 422; simultaneous unique-constraint conflicts return 409. Onboarding never resets or claims an existing identity.

## Defaults and retries

New clinics start active, with six system roles: admin, dentist, hygienist, receptionist, billing and auditor. Only admin receives the explicit clinic permission catalog; the other roles have no grants until configured. The initial identity belongs only to the new clinic and receives its admin role. Operators receive no automatic membership. Provisioning creates no demo patients, clinical records, provider credentials, payment configuration or communication configuration. Outbound communication and hosted checkout remain unavailable until explicitly configured through their existing workflows. Timezone and currency are stored profile preferences; existing financial snapshots and timestamp semantics remain governed by their own contracts.

The idempotency key is globally unique and retained with the tenant. The database uniqueness constraint resolves competing requests, including requests from different operators. An identical validated payload (independent of object key order) returns the original tenant without recreating roles, changing credentials, emitting another audit or reactivating a suspended clinic. Different validated input with the same key returns 409. Retain the original key and payload for retries. A keyed HMAC records input equality without storing credentials; changing the application key invalidates equality checks for previous requests. Failed transactions retain no partial clinic, identity, membership, permission grants or audit record.

## Suspension and audit

`active` remains the single persisted status flag; the profile also exposes derived `status` (`active`/`suspended`). The tenant resolver queries active membership on every protected clinic request. Suspension therefore blocks existing tokens from reading or writing clinic data and excludes that clinic from membership discovery. Authentication and token revocation remain available. A suspended clinic can be reactivated through the platform endpoint. Repeating an unchanged status succeeds without an additional audit. Other active memberships continue to work.

The existing append-only audit log records `provisioned`, `suspended` and `activated` under resource type `tenants`, attributed to the platform operator and affected clinic, with existing request metadata. Audit persistence is part of the same transaction: audit failure prevents provisioning or status changes. After reactivation, authorized clinic audit viewers can see these entries; trusted operators can inspect the stored records through operational database access while suspended. Status operations lock the clinic row to serialize competing changes.

Subscription billing, self-service tenant signup, invitations, existing-identity enrollment, and profile editing are outside this workflow. Legacy public identity registration retains its existing default-clinic behavior and does not enroll users in independent clinics.

Validation: `php artisan test --filter=TenantProvisioningTest` covers privilege separation, defaults, isolation, idempotency, rollback, validation, existing-identity protection and existing-token suspension/reactivation.

The generated Postman collection includes a Platform Tenants folder. Authenticate as an operator and set `provisioning_key`, `clinic_slug`, `clinic_contact_email`, `clinic_admin_email` and `new_user_password`; domain writes require the existing `allow_writes` flag. Keep those values stable when retrying provisioning. Set `tenant_id` explicitly to the returned clinic ID before requesting its platform profile or changing its status.
