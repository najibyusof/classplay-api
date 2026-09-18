# Organization Management API

Base URL: `/api/v1/admin/organizations`

All endpoints require `Authorization: Bearer {token}` (`auth:sanctum`) and follow the ClassPay standard response envelope (see `docs/api/authentication.md`).

## Concept

Organization is the top-level business boundary:

```
Organization → Classes → Participants → Payment Schedules → Payments
```

An organization can have multiple administrators, and an administrator can manage multiple organizations. **An admin never gets implicit access to every organization** — access is only granted through an _active_ row in `organization_admins` (established in Phase 7). Every endpoint below re-checks this on every request; nothing is cached.

## Authorization

Every endpoint uses `OrganizationPolicy` or `OrganizationAdminPolicy`, both of which check **permission + organization scope**, never `user_type` alone:

| Endpoint                                     | Ability            | Permission required                      | Organization scope                                  |
| -------------------------------------------- | ------------------ | ---------------------------------------- | --------------------------------------------------- |
| `GET /admin/organizations`                   | `viewAny`          | `organization.view` **and** `ADMIN` role | — (query is pre-filtered to assigned organizations) |
| `POST /admin/organizations`                  | `create`           | `organization.create`                    | —                                                   |
| `GET /admin/organizations/{organization}`    | `view`             | `organization.view`                      | `isOrganizationAdmin($organization)`                |
| `PUT /admin/organizations/{organization}`    | `update`           | `organization.update`                    | `isOrganizationAdmin($organization)`                |
| `DELETE /admin/organizations/{organization}` | `delete`           | `organization.delete`                    | `isOrganizationAdmin($organization)`                |
| `GET/POST .../admins`                        | `viewAny`/`create` | `organization.manage_admins`             | `isOrganizationAdmin($organization)`                |
| `PUT/DELETE .../admins/{user}`               | `update`/`delete`  | `organization.manage_admins`             | `isOrganizationAdmin($organization)`                |

`GET /admin/organizations` additionally requires the `ADMIN` role itself (not just the `organization.view` permission), because `organization.view` is also granted to `STUDENT`/`SPONSOR` for other, unrelated future read paths — this endpoint is admin-management-only.

Unauthenticated → `401`. Authenticated but not authorized → `403` with `{"message": "You are not authorized to perform this action."}`. Non-existent organization → `404` (standard route-model-binding behavior).

## Endpoints

### `GET /admin/organizations`

Returns only organizations the authenticated admin actively administers.

Query parameters:

| Param      | Default | Notes                                    |
| ---------- | ------- | ---------------------------------------- |
| `page`     | 1       | standard Laravel pagination              |
| `per_page` | 20      | clamped to a max of 100                  |
| `status`   | —       | `active` or `inactive`                   |
| `search`   | —       | matches `name` or `code` (`LIKE %term%`) |

Search/filter/pagination only ever operate on the admin's own accessible organizations — an unassigned organization can never be discovered through `search`, regardless of how specific the query is.

```json
{
    "success": true,
    "message": "Organizations retrieved successfully.",
    "data": {
        "organizations": [
            {
                "id": 1,
                "name": "...",
                "code": "...",
                "description": "...",
                "logo_path": null,
                "status": "active",
                "created_by": 3,
                "created_at": "...",
                "updated_at": "..."
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "total": 1,
            "last_page": 1
        }
    }
}
```

### `POST /admin/organizations`

Requires `organization.create`. `created_by` is never accepted from the client — it is always the authenticated user.

```json
{
    "name": "Al-Huda Learning Centre",
    "code": "ALHUDA",
    "description": "Islamic learning centre",
    "status": "active"
}
```

| Field         | Rules                            |
| ------------- | -------------------------------- |
| `name`        | required, string, max 200        |
| `code`        | nullable, string, max 50, unique |
| `description` | nullable, string                 |
| `status`      | nullable, `active`\|`inactive`   |

On success (**201**), inside a single database transaction:

1. The organization is created with `created_by` = the authenticated user.
2. An `organization_admins` row is created for that same user with `is_primary = true`, `status = active`.
3. An `organization.created` audit log entry is recorded.

If step 2 fails for any reason, step 1 is rolled back — an organization is never left without its creator as admin.

### `GET /admin/organizations/{organization}`

Returns `id, name, code, description, logo_path, status, created_by, created_at, updated_at` only — no classes, participants, payments, or reports (those belong to later phases).

### `PUT /admin/organizations/{organization}`

Same validation as create (fields `sometimes` instead of `required`). Clients can never set `id`, `created_by`, `created_at`, or `updated_at` — they aren't in the request's validated field list, so they're silently ignored even if sent. Records an `organization.updated` audit log entry with before/after values.

### `DELETE /admin/organizations/{organization}`

Soft-deletes the organization (never a hard delete). Records an `organization.deleted` audit log entry.

```json
{
    "success": true,
    "message": "Organization deleted successfully.",
    "data": null
}
```

**Current dependency rule:** deletion is rejected (`422`) if the organization has _any_ class record at all, since `ClassModel` already exists in this codebase even though Phase 8 doesn't manage classes. **Future rule (Phase 9+):** once classes carry their own lifecycle/status, this should be refined to reject deletion only when _active_ (non-completed/non-archived) classes exist, rather than any class row.

### `GET /admin/organizations/{organization}/admins`

```json
{
    "success": true,
    "message": "Organization administrators retrieved successfully.",
    "data": [
        {
            "id": 1,
            "name": "Admin One",
            "phone": "+60123456789",
            "email": "admin@example.com",
            "is_primary": true,
            "status": "active"
        }
    ]
}
```

`id` here is the administrator's **user id**. Passwords, password hashes, and Sanctum tokens are never included (`OrganizationAdminResource` only exposes the fields above).

### `GET /admin/organizations/{organization}/logo`

Returns a ready-to-display absolute URL for the organization's logo, or `null` when none is uploaded. `logo_path` (the raw storage path) is also included on the organization resource in every other response; this endpoint exists for clients that only need the displayable URL.

```json
{
    "success": true,
    "message": "Organization logo URL retrieved successfully.",
    "data": {
        "logo_url": "http://localhost/storage/organization-logos/abc123.png"
    }
}
```

### `POST /admin/organizations/{organization}/logo`

Uploads and replaces the organization's logo. The request must use `multipart/form-data` with a required `logo` image field. Accepted formats are JPG, JPEG, PNG, and WEBP; maximum size is 2 MB.

The file is stored on the configured `public` disk under `organization-logos/`, and the generated storage path is saved to `organizations.logo_path`. If an existing logo is present, it is deleted after the new file is stored.

```text
POST /api/v1/admin/organizations/1/logo
Content-Type: multipart/form-data
Authorization: Bearer {token}

logo: organization-logo.png
```

Success response (`200 OK`):

```json
{
    "success": true,
    "message": "Organization logo uploaded successfully.",
    "data": {
        "id": 1,
        "name": "Pusat Tahfiz Al-Amin",
        "logo_path": "organization-logos/abc123.png"
    }
}
```

The operation requires `organization.update` permission and an active organization-admin assignment. Missing or invalid files return `422`; unauthorized users return `403`.

### `POST /admin/organizations/{organization}/admins`

```json
{ "user_id": 5, "is_primary": false }
```

Validation and business rules (`StoreOrganizationAdminRequest`, enforced before the controller/service ever runs):

- `user_id` must reference an existing user whose `user_type` is `admin` — students and sponsors are rejected with a validation error, not silently ignored.
- `user_id` must not already be an administrator of this organization (checked scoped to `organization_id`, so the same user _can_ administer a different organization).

If `is_primary` is `true`, every other administrator of the organization is demoted (`is_primary = false`) in the same transaction — there is only ever one primary administrator per organization. Records `organization.admin_added`.

### `PUT /admin/organizations/{organization}/admins/{user}`

```json
{ "is_primary": true, "status": "active" }
```

`{user}` is the target user's ID (not the `organization_admins` row ID) — the controller resolves the pivot row from `(organization_id, user_id)` and returns `404` if that user isn't assigned to the organization. Promoting a user to primary demotes any existing primary administrator in the same transaction. Records `organization.admin_updated`.

### `DELETE /admin/organizations/{organization}/admins/{user}`

Rejected with `422` if:

- the target is the organization's _only active_ administrator, or
- the target is the primary administrator and no other active administrator exists to take over.

Otherwise the assignment is removed and `organization.admin_removed` is recorded.

## Audit logging

All six actions (`organization.created`, `organization.updated`, `organization.deleted`, `organization.admin_added`, `organization.admin_updated`, `organization.admin_removed`) are written via the existing `AuditLog` model/table (`app/Models/AuditLog.php`, migrated in an earlier phase) through `OrganizationService`. No passwords, tokens, or other credentials are ever included in `old_values`/`new_values`.

## Security summary

- Every list/search endpoint is pre-filtered to the authenticated admin's own `organization_admins` rows — an organization the admin doesn't administer is never returned, listed, or discoverable via `search`.
- `{organization}` and `{user}` are always resolved via Eloquent route-model binding and then re-validated by a Policy; a valid-looking ID alone never grants access (no insecure direct object references).
- `created_by` and admin assignment are always derived server-side from the authenticated user — never trusted from the request body.
