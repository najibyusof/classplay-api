# Student Management API

Base URL: `/api/v1/admin/students`. All endpoints require `Authorization: Bearer {token}` (`auth:sanctum`) and follow the ClassPay response envelope (`docs/api/authentication.md`).

> **Student self-service:** a student can also list their own classes and organizations via `GET /api/v1/student/classes` and `GET /api/v1/student/organizations` — see [payments.md](payments.md) and [classes.md](classes.md).

## Concept

Students are `users` with `user_type = student`. Class membership is never stored on the user — it lives in `class_participants`, so the same student can belong to multiple classes across multiple organizations:

```
Organization → Class → Class Participant → Student (User)
```

## Authorization

Gated by `UserPolicy` (new in Phase 10, auto-discovered for `App\Models\User`), which never trusts `user_type` alone:

| Endpoint                                   | Ability                  | Rule                                                                                                                                                                  |
| ------------------------------------------ | ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /admin/students`                      | `viewAny`                | `ADMIN` role **and** `participant.view` permission                                                                                                                    |
| `POST /admin/students`                     | `create`                 | `participant.create` permission (account creation itself isn't organization-scoped yet)                                                                               |
| `GET/PUT/DELETE /admin/students/{student}` | `view`/`update`/`delete` | matching permission **and** the student participates in at least one class within an organization the admin actively administers (`User::canManageParticipantUser()`) |

A student ID alone never grants access — `canManageParticipantUser()` always re-traverses `Admin → organization_admins → Organization → Class → class_participants → Student` on every request. Unauthenticated → `401`; authenticated but unauthorized → `403` with the generic message; a `{student}` whose `user_type` isn't `student` → `404` (not a 403, to avoid confirming the ID belongs to a different resource type).

## `GET /admin/students`

Query parameters: `page`, `per_page` (max 100), `search` (matches `name`/`phone`), `status`.

Only returns students who participate in at least one class within an organization the authenticated admin administers — a student visible only through another admin's organization is never listed, searched, or filtered into view.

```json
{
    "success": true,
    "message": "Students retrieved successfully.",
    "data": {
        "students": [
            {
                "id": 1,
                "name": "Ahmad Ali",
                "phone": "+60123456789",
                "email": null,
                "status": "active",
                "phone_verified_at": null,
                "last_login_at": null
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

## `POST /admin/students`

```json
{ "name": "Ahmad Ali", "phone": "+60123456789", "email": "ahmad@example.com" }
```

| Field   | Rules                                                                                                     |
| ------- | --------------------------------------------------------------------------------------------------------- |
| `name`  | required, string, max 150                                                                                 |
| `phone` | required, normalized to `+60XXXXXXXXX` (reuses `App\Services\PhoneNumberNormalizer` from Phase 6), unique |
| `email` | nullable, valid email                                                                                     |

`user_type` is always forced to `student` and `status` to `active` server-side — any `user_type`/`status` sent by the client is ignored (not in the validated field list). **No password is set** (`password = null`): the account reuses the exact Phase 6 "no usable password yet" state, so the student later calls `POST /api/v1/auth/set-password` themselves. No second password-activation mechanism was created.

## `GET /admin/students/{student}`

Returns `id, name, phone, email, status, phone_verified_at, last_login_at`, plus a `classes` array — but **only** the classes that fall within an organization the requesting admin administers, even though the student may belong to other classes elsewhere:

```json
{
    "data": {
        "id": 1,
        "name": "Ahmad Ali",
        "phone": "+60123456789",
        "email": null,
        "status": "active",
        "phone_verified_at": null,
        "last_login_at": null,
        "classes": [
            {
                "class_id": 4,
                "class_name": "Quran Class",
                "participant_status": "active"
            }
        ]
    }
}
```

Never includes `password`, password hash, or Sanctum tokens.

## `PUT /admin/students/{student}`

Same field set as create (all `sometimes`), plus `status` (`active`/`inactive`/`suspended`). Phone is re-normalized and re-checked for uniqueness (excluding the student's own row). `user_type` cannot be changed through this endpoint — it isn't part of the validated fields, so any client-supplied value is silently ignored, not applied.

## `DELETE /admin/students/{student}`

**Business rule:** if the student still has _active_ class participation, the account is first deactivated (`status = inactive`) and then soft-deleted; class_participants/sponsor_students/future payment records are never touched or removed by this action — only `users.deleted_at` is set. If the student has no active participation, only the soft-delete happens. A soft-deleted student is excluded from normal queries (Eloquent's default `SoftDeletes` scope) but remains in the database for historical integrity.

```json
{ "success": true, "message": "Student deleted successfully.", "data": null }
```

## Audit logging

`student.created`, `student.updated`, `student.deleted` are recorded via the existing `AuditLog` model, with `password` always stripped from the logged before/after values.
