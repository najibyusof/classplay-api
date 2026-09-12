# Class Participant & Sponsor/Student Relationship API

All endpoints require `Authorization: Bearer {token}` (`auth:sanctum`) and follow the ClassPay response envelope.

## Concept

```
Organization → Class → Class Participant (student or sponsor) → User
Sponsor (User) → sponsor_students → Student (User)
```

`class_participants` is the only source of truth for class membership — `participant_type` (`student`|`sponsor`) must always match the underlying `users.user_type`; the API never trusts a client-supplied `participant_type` that contradicts the account's real type. `sponsor_students` is a separate many-to-many relationship: a sponsor may sponsor multiple students, and (the schema does not prevent) a student may have multiple sponsors.

## Class participants

Base URL: `/api/v1/admin/classes/{class}/participants`

### Authorization

`ClassParticipantPolicy` (existing, reused from Phase 7) — every ability requires the matching `participant.*` permission **and** `isOrganizationAdmin($class->organization_id)`. A class in an organization the admin doesn't administer returns `403` for every action, including `index`.

### `GET /admin/classes/{class}/participants`

Returns every participant (student and sponsor) of the class, each including basic user info (never password/tokens).

### `POST /admin/classes/{class}/participants`

```json
{ "user_id": 15, "participant_type": "student" }
```

Rules enforced before the record is ever created:

1. `user_id` must reference an existing user.
2. `participant_type` must equal that user's actual `user_type` — `StoreClassParticipantRequest` validates this with a `withValidator` cross-check; a mismatch (e.g. a sponsor account submitted with `participant_type: student`) is rejected with `422` on the `participant_type` field, not silently corrected.
3. The class must belong to an organization the admin administers (`ClassParticipantPolicy::create`).
4. `status` is always set to `active` and `joined_at` to now — never accepted from the client.

**Duplicate handling:** `class_participants` has `unique(class_id, user_id)`, so a user can only ever have one row per class. If the user already has a `removed`/`inactive` row for this class, adding them again **reactivates that row** (`status = active`, `joined_at = now()`, `left_at = null`) instead of attempting a second insert. If the existing row is already `active`, the request is rejected with `422`.

### `PUT /admin/classes/{class}/participants/{participant}`

```json
{ "status": "inactive" }
```

- `status → removed` automatically sets `left_at = now()`.
- `status → active` (from a non-active state) resets `joined_at = now()` and clears `left_at`, so re-activation always reflects the new join date rather than the original one.
- `left_at` is never accepted directly from the client — it's always derived from the status transition.

### `DELETE /admin/classes/{class}/participants/{participant}`

**Logical removal, not a hard delete:** sets `status = removed` and `left_at = now()`. The row is preserved (a future payment schedule/payment can still reference it).

```json
{
    "success": true,
    "message": "Participant removed successfully.",
    "data": null
}
```

## Sponsor → student relationships

Base URL: `/api/v1/admin/sponsors/{sponsor}/students`

### Authorization

`SponsorStudentPolicy` requires `participant.*` permission **and** that the admin can manage (via `canManageParticipantUser()`) _either_ the sponsor _or_ the student — since a sponsor-student link isn't itself scoped to one organization, the admin needs shared authorized visibility of at least one side of the relationship. `{sponsor}` that isn't actually `user_type = sponsor` → `404`.

### `GET /admin/sponsors/{sponsor}/students`

Lists the sponsor's linked students (sponsor + student summaries, relationship_type, status, dates).

### `POST /admin/sponsors/{sponsor}/students`

```json
{ "student_id": 15, "relationship_type": "parent" }
```

- `student_id` must reference an existing user whose `user_type` is `student` — a sponsor or admin ID is rejected with `422`, never silently linked (blocks `student→student`, `sponsor→sponsor`, and `admin→student` relationships).
- `student_id` must not already be linked to this sponsor (`unique(sponsor_id, student_id)` enforced at the validation layer, not just the DB constraint, so failures return a clean `422` instead of a database error).
- `status` is always `active` and `start_date` is always today — never accepted from the client.

### `DELETE /admin/sponsors/{sponsor}/students/{student}`

**Logical removal:** sets `status = inactive` and `end_date = now()`. The relationship row is never deleted, preserving history for future payment reporting.

## Security & data isolation

- Every ability re-derives organization scope from the database on each request (`isOrganizationAdmin()` / `canManageParticipantUser()`) — nothing is cached, and a valid-looking ID is never sufficient on its own (no insecure direct object references).
- A student who also participates in an _unauthorized_ organization's class is still visible to the admin (via the authorized class), but that visibility never extends to the unauthorized organization or its other classes/participants — verified explicitly in `tests/Feature/Api/ParticipantManagementTest::test_admin_sees_student_via_authorized_class_but_not_the_unauthorized_organization`.
- `403` responses always use the generic `"You are not authorized to perform this action."` message (configured in `bootstrap/app.php`), regardless of which specific check failed.

## Audit logging

`participant.added`, `participant.updated`, `participant.removed`, `sponsor_student.added`, `sponsor_student.removed` are recorded via the existing `AuditLog` model. No passwords or tokens are ever logged.
