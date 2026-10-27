# Patient documents and imaging

## Storage decision

Use a dedicated **private S3-compatible bucket**. This is the selected Phase 2 storage strategy. Laravel Flysystem plus the Composer S3 adapter implements `App\Documents\DocumentStorage`; workflows depend on that interface. A separate `clinical_documents` disk keeps clinical uploads independent of `FILESYSTEM_DISK`, the public disk, and `storage:link`. Tests fake this disk and also exercise the real S3 adapter against an AWS SDK mock handler.

The database stores tenant/patient ownership, optional appointment/note IDs, original filename, detected MIME, actual byte count, uploader, creation/update timestamps, archive attribution/timestamp, deletion attribution/timestamp and purge timestamp. Disk and random object key are internal and hidden from serialization. Original filename is display/download metadata only; object names use `tenants/{tenant_id}/patients/{patient_id}/{uuid}` without names, filenames or clinical text. Never use a key or prefix as an authorization credential.

## API and authorization

All routes below use `/api/patients/{patient}/documents`, Sanctum authentication and the existing tenant middleware. Supply `X-Tenant-Id` for a non-default clinic. Authentication runs before binding; inactive/revoked memberships fail before access. Foreign clinic IDs, absent documents and documents under the wrong patient return 404. No clinical files are served through web/static routes.

| Method | Suffix | Behavior |
| --- | --- | --- |
| GET | (none) | Paginated `data.documents`, newest ID first; `status=active` default, `archived` or `all`; `page`, `per_page` (1–100). |
| POST | (none) | Multipart upload: required `file`, optional nullable `appointment_id`, `clinical_note_id`; returns 201 with `data.document`. |
| GET | `/{document}` | Metadata in `data.document`. |
| GET | `/{document}/download` | Authenticated binary attachment from private storage. |
| POST | `/{document}/archive` | Idempotent archive; no body required. |
| DELETE | `/{document}` | Revokes access and deletes the current object; safe to repeat, including after a 503. |

Admin and Dentist roles can read. `patient_documents.view` also allows metadata and downloads for a delegated reviewer. Writes require Admin, or Dentist without Receptionist; permission grants/wildcards alone cannot grant clinical writes. Admin remains clinic-local. Register the permission catalog with the existing `PermissionSeeder`; the new entries are `patient_documents.view`, `.create`, `.archive`, `.delete`. The last three describe clinical actions and do not bypass the clinical role restriction. Workflow methods repeat Gate authorization and require an explicitly established tenant context for trusted callers.

Appointment/note IDs must resolve in the current clinic and belong to the routed patient. If both are supplied and the note has an appointment, it must match. A soft-deleted note cannot receive a new document; existing linked documents remain available as historical records. New uploads to archived patients are permitted, consistent with clinical-note documentation. Parent appointments/notes cannot be reassigned in ways that invalidate retained document links, including deleted document history. Workflow checks return 422, with database guards as a final integrity boundary. MySQL and SQLite guards also enforce immutable ownership, uploaded metadata and archive/deletion attribution. Staff IDs remain historical identifiers after membership/account removal.

Unknown upload fields and server-owned patient, tenant, storage, filename/type/size, attribution and timestamp fields are rejected, including explicitly null values. There is no metadata replacement, unarchive or restore API. JSON uses the standard API envelope. Successful downloads are binary; download errors use the JSON envelope. Metadata and successful download responses have `Cache-Control: private, no-store`.

## Validation and private retrieval

The default limit is **20 MiB (20,480 KiB)** per nonempty file. `PATIENT_DOCUMENT_MAX_SIZE_KB` can lower/raise it. Content detection uses PHP Fileinfo on the temporary bytes, and the original filename extension must agree with the detected type:

| Detected MIME | Extensions |
| --- | --- |
| `application/pdf` | `pdf` |
| `image/jpeg` | `jpg`, `jpeg` |
| `image/png` | `png` |
| `image/webp` | `webp` |

Filenames are limited to 255 characters with no control characters or path separators. SVG, HTML, executables, office archives and DICOM are outside this allowlist. MIME/extension checks do not scan for malware or validate a complete medical imaging format. Files are downloaded as attachments with detected `Content-Type`, `X-Content-Type-Options: nosniff` and a restrictive sandbox CSP. The workflow provides storage/download, not inline rendering or diagnostic image interpretation.

The API opens and streams the private S3 object after checking current membership, clinic ownership and document permission. No public URL, S3 URL, redirect or presigned bearer link is emitted. This chooses the acceptance criterion's **private retrieval** option: authorization and auditing happen on every download, and revoking a membership or deleting a file prevents subsequent requests. An already-open download cannot be recalled. Clients should fetch the download with their normal bearer/session authentication and clinic header; do not put tokens in query strings. No Range/resumable-download contract is provided.

## Atomicity, retention and audit

Upload validates links before object I/O, then rechecks locked parent records before committing metadata and the `uploaded` audit together. A metadata/audit failure removes the just-uploaded object. If cleanup also fails, the error log records the clinic ID without PHI. A process crash between S3 write and database commit can leave an unreferenced object: periodically compare a bucket inventory with all metadata keys, including deleted rows, and reconcile unreferenced objects after a grace period. Do not use a bucket lifecycle that expires active patient objects by age.

Archive records `archived_at`/`archived_by_user_id` and an `archived` event atomically. It retains bytes and authenticated download access, and hides the record from the default active list. Repeated archive preserves the original timestamp/actor and adds no duplicate archive audit.

Delete first commits `deleted_at`/`deleted_by_user_id` with a `deleted` event, then removes the object. This order prevents a storage or audit failure from exposing an inconsistent record. If the initial audit fails, metadata and bytes remain intact. If object deletion fails, the API returns generic 503 while the document remains inaccessible. Repeating DELETE retries the purge; success records `purged_at` and one `purged` event. A crash or failure after object removal but before purge bookkeeping is safe to retry. Operations must monitor deleted rows with null `purged_at` and replay their DELETE endpoint using an authorized clinic administrator. Tombstones retain attribution and object keys for reconciliation and are never listed or downloadable. Bucket versioning, object locks, replicas and backups may retain older versions; `purged_at` means the current key was deleted, not that every historical copy was erased. Configure version/backup retention according to the clinic's retention decision.

Read audits are `listed` (resource ID is the patient ID), `viewed` and `downloaded` (document ID). A download event means the private stream opened and authorization succeeded, not that the client received all bytes. Download audit failure closes the stream before the response is created. Events use `patient_documents`, the actor ID, clinic, UTC time and the existing allowlisted request context. No filename, file bytes, note text, storage key, credentials or URL is copied into audit payloads. Denied/invalid operations emit no success events. Existing restricted audit viewing and append-only storage apply.

## Deployment

### This project's AWS setup

The clinical-document bucket is `dental-app-booking` in the selected Region, `us-east-2`. Set these values in the deployment's private environment:

```dotenv
PATIENT_DOCUMENT_S3_BUCKET=dental-app-booking
PATIENT_DOCUMENT_S3_REGION=us-east-2
PATIENT_DOCUMENT_S3_ENCRYPTION=AES256
```

Leave `PATIENT_DOCUMENT_S3_KEY` and `PATIENT_DOCUMENT_S3_SECRET` unset for the AWS SDK's default credential chain. Local development uses the `default` AWS profile and its temporary login session; renew that session with `aws login --profile default` when it expires. On this Windows machine the CLI is installed at `C:\Users\salee\AppData\Local\Programs\Amazon\AWSCLIV2\aws.exe`. A deployed service must use its own workload role restricted to the required object operations under `arn:aws:s3:::dental-app-booking/tenants/*`; the developer profile has administrative access and must not be copied into hosting configuration.

The bucket has Block Public Access enabled, Bucket owner enforced ownership, SSE-S3 encryption, and no static website hosting. The applied [bucket policy](../infrastructure/s3/bucket-policy.json) denies non-HTTPS client requests. The applied [lifecycle configuration](../infrastructure/s3/lifecycle.json) aborts unfinished multipart uploads after seven days without expiring completed patient files. These files describe this bucket's settings; read and merge any future existing policy/rules before reapplying them. Versioning is currently not enabled; choose version/backup retention with the clinic before production clinical use.

The local PHP installation (`C:\tools\php82\php.ini`) is configured with `upload_max_filesize=20M`, `post_max_size=24M`, and `curl.cainfo`/`openssl.cafile` pointing to the trusted CA bundle at `C:\tools\php82\extras\ssl\cacert.pem`, downloaded from [curl's CA bundle distribution](https://curl.se/docs/caextract.html). Restart an already-running PHP server or worker to pick up PHP configuration changes. Configure equivalent upload and TLS trust settings independently on the deployment host.

Run `php tools/check-document-storage.php` from the project root with an authenticated development profile to check the live application adapter. It uploads a small nonclinical PDF to a random `tenants/_storage-check/` key, verifies the downloaded bytes, encryption, content type and anonymous access denial, then deletes the test object. It does not create patient records or exercise the HTTP authorization workflow; those checks are in `PatientDocumentApiTest`. The live check incurs a few normal S3 requests; storage, requests and transfer are billed according to [S3 pricing](https://aws.amazon.com/s3/pricing/).

Before production use, enable S3 server access logging to an encrypted dedicated destination, CloudTrail S3 data events with an encrypted log destination, and CloudWatch request metrics for the clinical bucket. Scope service log-delivery policies to their source bucket/project with `aws:SourceArn` and `aws:SourceAccount` where supported. These observability features and their log storage can add charges; they have not been provisioned by the development bucket setup. See [AWS monitoring guidance](https://docs.aws.amazon.com/AmazonS3/latest/userguide/monitoring-overview.html). Review credits and spend status in AWS Settings > Billing.

### Deployment checklist

1. Run `composer install` and `php artisan migrate` using the normal deployment process. The current lock file/CI uses PHP 8.2. Enable Fileinfo and the existing database extensions. This migration requires MySQL trigger privileges, like the tenant integrity migrations. Rollback refuses a populated document table; recover/export metadata and objects explicitly before removing the schema.
2. Set `PATIENT_DOCUMENT_S3_BUCKET`, `PATIENT_DOCUMENT_S3_REGION`, and either `PATIENT_DOCUMENT_S3_KEY`/`PATIENT_DOCUMENT_S3_SECRET` or the SDK's workload-role credentials. For another S3-compatible provider, also set the HTTPS `PATIENT_DOCUMENT_S3_ENDPOINT` and `PATIENT_DOCUMENT_S3_PATH_STYLE` as required. Empty credentials permit the AWS default provider chain. Never commit credentials. Keep the disk name stable for retained rows.
3. Provision a dedicated bucket with public access blocked, website hosting disabled, no public/CDN policy, TLS required and encryption at rest. The adapter requests `AES256` server-side encryption by default (`PATIENT_DOCUMENT_S3_ENCRYPTION`); verify provider support. Workload IAM should permit only the required object read/write/delete actions under `tenants/*`, including multipart upload/abort permissions for files crossing the SDK's multipart threshold. Configure a lifecycle rule to abort incomplete multipart uploads. The app never lists the bucket during requests. Use separate privileged credentials for inventory reconciliation and bucket administration.
4. Upload ACL is `bucket-owner-full-control`, compatible with AWS Bucket owner enforced ownership and without anonymous grants. For other providers, verify this ACL and encryption settings with a disposable private bucket before enabling clinical traffic. Bucket policy remains the privacy boundary: private visibility cannot repair an already-public bucket policy.
5. Set PHP `upload_max_filesize` to at least `20M` and `post_max_size` above the file limit (for example `24M`), and align reverse-proxy body/time limits. Larger requests rejected by PHP/proxy may produce 413 before file validation. Ensure proxy/access logs exclude request bodies and authorization headers, do not cache these routes, and restrict application logs/backups because the existing exception handler can log operational exception details.
6. Run a staging upload/download/archive/delete smoke test with the selected provider. Confirm anonymous bucket/object access is denied, content/filenames survive download, revoked membership prevents access, and pending purges are monitored. No live bucket is contacted by the automated tests.

Storage behavior follows [Laravel's filesystem documentation](https://laravel.com/docs/10.x/filesystem). AWS's accepted ACL behavior is documented in [S3 Object Ownership](https://docs.aws.amazon.com/AmazonS3/latest/userguide/about-object-ownership.html).

## Verification

`php vendor/phpunit/phpunit/phpunit --filter PatientDocumentApiTest` covers authentication on all routes, role/permission denials including mixed roles and wildcard holders, private metadata/attachment headers, actual content validation and size limits, relationship/clinic boundaries, archive/delete idempotence, direct service authorization, audit rollback, storage failure and retry, raw metadata guards and the real S3 adapter with mocked commands. The suite defaults to an isolated in-memory SQLite database; CI also runs MySQL. On this Windows PHP installation use `php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit ...`.

OpenAPI and the generated Postman collection include all six operations. `node --test tests/Postman/collection.test.cjs` checks multipart write guards, file selection, ID capture and binary response handling.
