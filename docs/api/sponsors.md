# Sponsor Management API

Base URL: `/api/v1/admin/sponsors`. Same authentication, envelope, and general design as [Student Management](students.md) — this document only covers what differs.

## Concept

Sponsors are `users` with `user_type = sponsor`. A sponsor can:

- participate directly in a class (`class_participants.participant_type = sponsor`), and/or
- sponsor one or more students via `sponsor_students` (see [Participant Management](participants.md#sponsor--student-relationships)).

A sponsor is **not** limited to a single student — `sponsor_students` supports many-to-many.

## Authorization

Identical mechanism to students: `UserPolicy` requires the matching `participant.*` permission plus `User::canManageParticipantUser()` — the sponsor must participate in a class within an organization the admin actively administers. `GET /admin/sponsors` requires the `ADMIN` role in addition to `participant.view`.

## Endpoints

### `GET /admin/sponsors`

Same query parameters as students (`page`, `per_page`, `search` on name/phone, `status`). Only returns sponsors visible through the admin's authorized organizations.

### `POST /admin/sponsors`

```json
{
    "name": "Abdullah Ahmad",
    "phone": "+60129876543",
    "email": "abdullah@example.com"
}
```

`user_type` is always forced to `sponsor` server-side (never trusts a client-supplied value); `password` is left `null`, reusing the Phase 6 initial-password/`set-password` flow exactly as for students.

### `GET /admin/sponsors/{sponsor}`

Same field set as `StudentResource`, including a `classes` array limited to organizations the admin administers.

### `PUT /admin/sponsors/{sponsor}`

Same validation shape as `UpdateStudentRequest`. `user_type` cannot be changed through this endpoint.

### `DELETE /admin/sponsors/{sponsor}`

Same rule as students: deactivate first if the sponsor has active class participation, then soft-delete. Historical `sponsor_students` and `class_participants` rows are preserved.

## Audit logging

`sponsor.created`, `sponsor.updated`, `sponsor.deleted` via the existing `AuditLog` model, with `password` stripped from logged values.
