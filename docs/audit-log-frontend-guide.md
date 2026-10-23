# Audit log frontend implementation guide

## Access and endpoints

Phase 2: audit history and audit-role membership are scoped to the clinic selected by `X-Tenant-Id` (default clinic when omitted). Refetch authorization and reset audit filters/pagination when changing clinics; IDs and request correlation never search across clinics. See [the multi-tenant contract](multi-tenancy.md).

The additional `users/membership_removed` event means a shared user's roles and membership were removed from this clinic while their global identity and other memberships were retained. It is not a global account deletion.

Use the existing authenticated API client. All audit endpoints require Sanctum authentication and an actual role with slug `admin` or `auditor`. Permission wildcards, `audit_logs.view`, and role display names alone do not grant access. The backend checks current role membership on every request. Frontend navigation can use `GET /api/user` and its `data.roles[].slug` values to decide whether to show the audit screen; the API remains the authority.

| Method | Path | Response data |
| --- | --- | --- |
| GET | `/api/audit-logs` | `data.auditLogs` and `meta.pagination` |
| GET | `/api/audit-logs/filters` | `data.actions`, `data.resourceTypes` |
| GET | `/api/audit-logs/{audit_log}` | `data.auditLog` |

The API is read-only. There are no create, edit, delete, bulk-delete, or export endpoints. Unsupported write methods return 405, including for admins. Reading logs does not create additional audit events. No schema migration or frontend audit-event submission is necessary.

For cookie/session authentication, reuse the application's existing CSRF/login flow and send `credentials: "include"`. Backend `FRONTEND_URL`, CORS, Sanctum stateful domains, and cookie settings must allow the frontend origin. For bearer authentication, send `Authorization: Bearer <token>`. Always send `Accept: application/json`; audit GET requests do not need a CSRF token. Never send roles or actor IDs as a substitute for authentication.

## List and filters

```http
GET /api/audit-logs?resource_type=patients&resource_id=42&per_page=25&page=1
Accept: application/json
Authorization: Bearer <token>
```

All filters are optional and combine with AND. Exact values are matched, not free-text searches.

| Query parameter | Value / behavior |
| --- | --- |
| `actor_id` | Positive integer user ID. Deleted users' IDs remain valid filters; no current-user existence check is made. |
| `actor_is_null` | `1` for records with no attributed actor; `0` for attributed records. Omit for either. Do not combine `1` with `actor_id`. Send `1`/`0`, not the strings `true`/`false`. |
| `action` | Machine action token, at most 80 characters, matching `[a-z][a-z0-9_.]*`. |
| `resource_type` | Plural machine resource token, at most 80 characters, matching `[a-z][a-z0-9_]*`. |
| `resource_id` | String key, at most 128 characters, matching `[A-Za-z0-9][A-Za-z0-9._:-]*`. Keep it as a string, including numeric IDs and UUIDs. Combine with resource type to view one resource's history. |
| `request_id` | Originating mutation's request ID, at most 100 characters, matching `[A-Za-z0-9._:-]+`. Correlates multiple audit rows from one operation. |
| `from`, `to` | Inclusive occurrence-time bounds. RFC 3339 timestamps with `T`, seconds, and explicit `Z` or numeric offset are required. Optional fractional seconds accept 1–6 digits. Bounds normalize to UTC; `to` must not precede `from`. |
| `per_page` | Integer 1–100, default 15. |
| `page` | Positive integer, default 1. |

Use `URLSearchParams` to encode offsets and other values. Omit empty filters instead of sending empty strings or null. Syntactically valid but unknown action/resource/user/request IDs produce an empty list, not 404. A resource ID does not need to refer to a currently existing entity.

For example, an entire day in a selected UTC-05:00 offset can be requested with `from=2026-10-18T00:00:00-05:00` and `to=2026-10-18T23:59:59.999999-05:00`, URL-encoded. Convert the UI's selected timezone deliberately; a `datetime-local` value alone has no timezone and is rejected. JavaScript `Date` has millisecond precision; a `.999Z` bound means `.999000Z`, not the end of that millisecond.

Results sort by `occurred_at` descending, then audit `id` descending. Pagination is offset-based, not a snapshot: new entries can shift subsequent pages. Reset to page 1 after filter changes or refresh; for a bounded history view, retain an explicit `to` bound while paging. Do not implement audit filtering by downloading the entire history.

## Response contract

```json
{
  "success": true,
  "statusCode": 200,
  "message": "Audit logs retrieved.",
  "code": null,
  "data": {
    "auditLogs": [
      {
        "id": 281,
        "actor_id": 7,
        "actorName": "Dr Rivera",
        "action": "updated",
        "resource_type": "patients",
        "resource_id": "42",
        "occurred_at": "2026-10-18T14:30:12.123456Z",
        "ip_address": "203.0.113.8",
        "user_agent": "ClinicWeb/1.0",
        "request_metadata": {
          "request_id": "mutation-abc123",
          "method": "PATCH",
          "route": "api/patients/{patient}"
        }
      }
    ]
  },
  "errors": null,
  "meta": {
    "pagination": {
      "currentPage": 1,
      "perPage": 25,
      "total": 1,
      "lastPage": 1,
      "from": 1,
      "to": 1,
      "hasMorePages": false
    }
  },
  "requestId": "read-def456"
}
```

The detail endpoint returns the same row shape under `data.auditLog` with `meta: null`. Empty lists return `auditLogs: []`, total 0, and pagination `from`/`to` null. A nonexistent numeric audit ID or invalid ID path returns 404.

The top-level `requestId` identifies the current GET request. The row's `request_metadata.request_id` identifies the original mutation. They are different correlation IDs; use the latter to populate the request filter.

`actorName` is the user's **current** display name, not a historical name snapshot. It is null if the account no longer exists or actor attribution was null. Suggested rendering:

- `actor_id === null`: "System / unauthenticated"; public registration also has null attribution.
- Non-null actor ID with null name: "Deleted user #7".
- Otherwise: the escaped actor name, with its ID available in detail.

IP, user agent, and request metadata may be null, especially for console/system operations. Metadata exposes only `request_id`, `method`, and `route`; individual keys may be absent. Actor emails, passwords, tokens, roles, patient names, clinical text, cancellation reasons, and before/after snapshots are not returned. Audit access does not grant access to the underlying patient, note, user, or appointment API; gate resource links separately and handle 403/404, including deleted resources.

## Filter selectors and labels

Fetch `/api/audit-logs/filters` once when opening the screen:

```json
{
  "data": {
    "actions": ["created", "roles_changed", "updated"],
    "resourceTypes": ["patients", "roles", "users"]
  }
}
```

This abbreviated example omits the standard envelope fields. Both arrays contain sorted, unique values **present in stored history**, not all theoretically supported values, and may be empty. They do not change with the active list filters. Actor filtering accepts a numeric ID; a row can provide a convenient "show this actor's history" action without querying a staff directory.

Known Phase 1 action labels include `created`, `updated`, `archived`, `deleted`, `status_changed`, `roles_changed`, `permissions_changed`, `password_reset`, and `email_verified`. Use a label dictionary but retain a safe fallback for new tokens. Send the original machine token to the API, not its translated label. One appointment update that changes both status and other fields emits one `status_changed` record, not duplicate update records. No-op writes and failed/rolled-back domain mutations do not emit success records. See [logging coverage](audit-logging.md#core-phase-1-coverage) for exact semantics.

## TypeScript client example

Adapt this to the existing API client rather than building a second authentication/token store. This example uses browser `fetch` and no additional library.

```ts
export interface AuditLog {
  id: number;
  actor_id: number | null;
  actorName: string | null;
  action: string;
  resource_type: string;
  resource_id: string;
  occurred_at: string;
  ip_address: string | null;
  user_agent: string | null;
  request_metadata: {
    request_id?: string;
    method?: string;
    route?: string;
  } | null;
}

export interface Pagination {
  currentPage: number;
  perPage: number;
  total: number;
  lastPage: number;
  from: number | null;
  to: number | null;
  hasMorePages: boolean;
}

export interface Envelope<Data> {
  success: boolean;
  statusCode: number;
  message: string;
  code: string | null;
  data: Data | null;
  errors: Record<string, string[]> | null;
  meta: { pagination?: Pagination } | null;
  requestId: string | null;
}

export interface AuditFilters {
  actor_id?: number;
  actor_is_null?: boolean;
  action?: string;
  resource_type?: string;
  resource_id?: string;
  request_id?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public payload: Envelope<unknown>,
  ) {
    super(payload.message);
  }
}

export function createAuditClient(apiBaseUrl: string, getToken: () => string | undefined) {
  const baseUrl = apiBaseUrl.replace(/\/$/, "");

  async function read<Data>(path: string, signal?: AbortSignal): Promise<Envelope<Data>> {
    const token = getToken();
    const headers: Record<string, string> = { Accept: "application/json" };
    if (token) headers.Authorization = `Bearer ${token}`;

    const response = await fetch(`${baseUrl}${path}`, {
      headers,
      credentials: "include",
      cache: "no-store",
      signal,
    });
    const payload = (await response.json()) as Envelope<Data>;
    if (!response.ok || !payload.success) throw new ApiError(response.status, payload);
    return payload;
  }

  return {
    list(filters: AuditFilters, signal?: AbortSignal) {
      const query = new URLSearchParams();
      for (const [key, value] of Object.entries(filters)) {
        if (value === undefined || value === "") continue;
        query.set(key, typeof value === "boolean" ? (value ? "1" : "0") : String(value));
      }
      return read<{ auditLogs: AuditLog[] }>(`/audit-logs?${query}`, signal);
    },
    detail(id: number, signal?: AbortSignal) {
      return read<{ auditLog: AuditLog }>(`/audit-logs/${encodeURIComponent(String(id))}`, signal);
    },
    filters(signal?: AbortSignal) {
      return read<{ actions: string[]; resourceTypes: string[] }>("/audit-logs/filters", signal);
    },
  };
}
```

For example, initialize with the backend API base URL `http://localhost:8000/api`. Supply the existing in-memory token accessor, or `() => undefined` for cookie authentication. Catch network failures and invalid/non-JSON gateway responses separately from `ApiError`. TypeScript interfaces describe the expected contract; they are not runtime response validation.

## Screen wiring and errors

1. Check access, then load filter options and the first page. Render timestamp, actor, action, resource type, resource ID, and a detail button; keep IP/user agent in the detail panel if desired.
2. Debounce text/ID filters, omit blanks, reset `page` to 1 when filters change, and use server pagination metadata for controls. Clear `actor_id` when selecting the unattributed-only actor filter.
3. Cancel obsolete requests with `AbortController` and ignore `AbortError` so slower old responses cannot replace newer results. Set loading state before each fetch and clear it only for the active request. Explicit refresh is sufficient; avoid per-keystroke fetches or aggressive polling because the existing API rate limiter applies.
4. Open detail with the audit ID, not the resource ID. Format UTC timestamps for the selected display timezone with `Intl.DateTimeFormat`, retaining the raw timestamp for precise inspection.
5. On 401, clear protected state and use the normal sign-in flow. On 403, clear results and show access denied. On 404, close/mark the missing detail. On 422, display `errors` under the corresponding filters. On 429, back off. On network/5xx errors, offer retry and display the top-level request ID when available.

All successful audit responses send `Cache-Control: private, no-store`. Do not persist audit payloads in local storage, service-worker caches, shared query caches after logout, analytics, or frontend error-reporting context. Clear page/detail state when the user changes or loses access. Render names, user agents, action tokens, and route templates as plain escaped text, never HTML. Audit history contains sensitive operational information even though clinical content and credentials are excluded.

## Frontend verification checklist

- Admin/auditor can view list, filter options, and details; other roles see 403 even with wildcard permissions.
- Pagination, empty results, ID filters, encoded timezone offsets, and reversed-range validation work.
- Null actors, deleted actors/resources, unknown action labels, and null/missing request metadata render safely.
- Originating mutation request IDs are distinguished from the GET request ID.
- Filter changes reset pagination; obsolete requests cannot overwrite current results.
- Read requests never change the audit count; no mutation controls appear.
- Audit state is cleared on logout/access loss and is not sent to analytics or persistent browser storage.
