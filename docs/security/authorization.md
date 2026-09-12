# Authorization & RBAC

## Status: reused and hardened existing implementation

Phase 7 did **not** start from scratch. An authorization audit (see below) found that RBAC scaffolding, organization-scoped policies, and ownership checks already existed from earlier phases. Rather than duplicating that work, this phase:

- Reused the existing `roles`, `permissions`, `user_roles`, `role_permissions` tables, `Role`/`Permission` models, and `RolePermissionSeeder`.
- Reused all 18 existing Policy classes and the organization-boundary design (`organization_admins`).
- **Fixed** the one real defect: every policy authorized purely on `$user->user_type` (via `isAdminType()`), while the RBAC tables existed but were never consulted. That violated the "user_type is not authorization" rule.
- Added the missing pieces: `User::hasRole()`, `User::hasPermission()`, automatic role sync from `user_type`, the full Phase 7 permission catalog, and a generic 403 error message.

No new package, migration, model, or middleware directory was introduced.

---

## Authentication vs. authorization

- **Authentication** (Phase 6) answers "who is this?" — a valid Sanctum bearer token resolved to a `User` via `auth:sanctum`.
- **Authorization** (this phase) answers "is this authenticated user allowed to do _this_ to _that specific resource_?" — enforced by Laravel Policies, never by trusting client-supplied IDs or `user_type`.

Every controller action calls `$this->authorize($ability, $resource)`, which resolves to a Policy method. Policy methods always receive the resource instance (or its parent, for `create`), so authorization is resource-scoped, not just role-scoped.

---

## RBAC structure

| Table              | Purpose                                                        |
| ------------------ | -------------------------------------------------------------- |
| `roles`            | `ADMIN`, `STUDENT`, `SPONSOR` (unique `name`)                  |
| `permissions`      | Fine-grained actions, e.g. `organization.view` (unique `name`) |
| `user_roles`       | User ↔ Role, unique `(user_id, role_id)`                       |
| `role_permissions` | Role ↔ Permission, unique `(role_id, permission_id)`           |

### Role auto-sync

`users.user_type` remains a simple classification column, but it is **not** used directly for authorization decisions. Instead, `App\Models\User` keeps a matching RBAC role in sync automatically:

```php
protected static function booted(): void
{
    static::saved(function (User $user): void {
        if ($user->wasRecentlyCreated || $user->wasChanged('user_type')) {
            $user->syncRoleFromUserType();
        }
    });
}
```

This means `user_type` decides _which role a user is assigned_, while every authorization check goes through that role's permissions — satisfying "`user_type` may be used as an additional classification, but must not be the sole authorization mechanism."

### Helper methods

```php
$user->hasRole('ADMIN');                 // role membership
$user->hasPermission('organization.view'); // permission via any assigned role
$user->isOrganizationAdmin($organizationId); // organization-scope membership (organization_admins)
```

`isAdminType()` still exists for lightweight classification (e.g. choosing a factory default) but is no longer referenced by any Policy or authorization decision.

---

## Permissions

| Group        | Permissions                                                                                                            |
| ------------ | ---------------------------------------------------------------------------------------------------------------------- |
| Organization | `organization.view`, `organization.create`, `organization.update`, `organization.delete`, `organization.manage_admins` |
| Class        | `class.view`, `class.create`, `class.update`, `class.delete`, `class.activate`, `class.deactivate`                     |
| Participant  | `participant.view`, `participant.create`, `participant.update`, `participant.delete`                                   |
| Payment      | `payment.view`, `payment.create`, `payment.update`, `payment.verify`                                                   |
| Notification | `notification.view`, `notification.send`                                                                               |
| Reports      | `report.view`                                                                                                          |

`class.activate`, `class.deactivate`, `organization.manage_admins`, and `report.view` are seeded for completeness (per the Phase 7 design) even though no dedicated endpoint exists yet for activate/deactivate or reports — those remain out of scope for this phase.

### Role → permission assignment (`RolePermissionSeeder`)

- **ADMIN** — every permission above.
- **STUDENT** / **SPONSOR** — `organization.view`, `class.view`, `participant.view`, `payment.view`, `payment.create`, `notification.view`.

The seeder is idempotent: it uses `firstOrCreate()` for roles/permissions and `sync()` for role-permission pivots, so running `php artisan db:seed` repeatedly never creates duplicates.

---

## Organization-level authorization

Organization is the top-level authorization boundary:

```
Organization → Classes → Participants → Payment Schedules → Payments
```

An admin does **not** automatically get access to every organization. Access requires an **active** row in `organization_admins`:

```php
public function isOrganizationAdmin(int $organizationId): bool
{
    return $this->organizationAdmins()
        ->where('organization_id', $organizationId)
        ->where('status', 'active')
        ->exists();
}
```

Every admin-facing policy method checks **both** the permission and the organization scope:

```php
// OrganizationPolicy
public function view(User $user, Organization $organization): bool
{
    return $user->hasPermission('organization.view') && $user->isOrganizationAdmin($organization->id);
}
```

Removing (deactivating) an `organization_admins` row immediately revokes access on the next request — there is no cached/session-based authorization state.

### Phase 8 refinement: `OrganizationPolicy::viewAny`

`GET /api/v1/admin/organizations` (Phase 8) is an admin-management endpoint, distinct from the `organization.view` permission that `STUDENT`/`SPONSOR` also hold for other read paths. `viewAny` therefore requires **both** `hasRole('ADMIN')` and `hasPermission('organization.view')`, so a student/sponsor with the generic view permission still receives `403` from this specific admin-listing endpoint. All other Organization abilities (`view`, `update`, `delete`) were already scoped correctly via `isOrganizationAdmin()` and needed no change.

### Phase 10 addition: `UserPolicy` and `canManageParticipantUser()`

Phase 10 introduced `App\Policies\UserPolicy` (auto-discovered for `App\Models\User`) to authorize the new `/admin/students` and `/admin/sponsors` endpoints, plus a `User::canManageParticipantUser(User $target)` helper: an admin may view/update/delete a student or sponsor account only if that account participates in a class within an organization the admin actively administers. This traverses `Admin → organization_admins → Organization → Class → class_participants → User` on every request — the same organization-boundary pattern as every other policy in this document, just applied to raw `User` records instead of a dedicated model. `SponsorStudentPolicy` was also updated to use this helper (an admin may manage a sponsor-student link if they can manage _either_ party), since the relationship itself isn't scoped to one organization.

---

## Class, participant, and payment authorization

`ClassModelPolicy`, `ClassSchedulePolicy`, `ClassPaymentSettingPolicy`, and `ClassParticipantPolicy` all resolve the owning organization through the class relationship (`$classModel->organization_id`) and apply the same permission + `isOrganizationAdmin` check for admins. Students/sponsors instead fall back to an **ownership** check (they are a participant of that class, or the record belongs to them):

```php
// ClassModelPolicy::view
public function view(User $user, ClassModel $classModel): bool
{
    if ($user->hasPermission('class.view') && $user->isOrganizationAdmin($classModel->organization_id)) {
        return true;
    }

    return $classModel->participants()->where('user_id', $user->id)->exists();
}
```

`PaymentSchedulePolicy`, `PaymentPolicy`, `PaymentTransactionPolicy`, and `PaymentProofPolicy` follow the same two-path pattern: **admin path** (permission + organization scope, resolved through `paymentSchedule.classModel.organization_id`) or **ownership path** (`payer_id`/`classParticipant.user_id` matches the authenticated user). `PaymentPolicy::update` additionally requires `payment.update` **or** `payment.verify`, matching the distinct "verify" permission in the design.

---

## Student data isolation

A student can only reach their **own** records. IDs in the URL are never trusted by themselves — every route resolves the model via route-model binding and then runs it through the resource's Policy, which re-derives ownership from the database:

```
GET /api/v1/payment-schedules/{paymentSchedule}
```

`PaymentSchedulePolicy::view` checks `$paymentSchedule->classParticipant->user_id === $user->id`. Knowing a valid schedule ID is not sufficient — see `RbacAuthorizationTest::test_student_cannot_access_another_students_payment_schedule` and `::test_student_cannot_access_another_students_payment`.

## Sponsor data isolation

Sponsors are subject to the same ownership checks as students on `Payment`/`PaymentSchedule` (via `payer_id`), and `SponsorStudentPolicy` restricts `sponsor_students` records to the sponsor or student named in that row (or an admin). A sponsor cannot view another sponsor's payment or an unrelated `sponsor_students` record — see `::test_sponsor_cannot_access_unrelated_payment` and `::test_sponsor_cannot_access_unrelated_sponsor_student_record`.

---

## Policies

All 18 policies live in `app/Policies` and are auto-discovered by Laravel's naming convention (`App\Models\X` → `App\Policies\XPolicy`), so no manual registration in a service provider is required (verified working).

| Policy                                                                                                        | Admin check                                          | Non-admin path              |
| ------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- | --------------------------- |
| `OrganizationPolicy`                                                                                          | permission + org scope                               | —                           |
| `OrganizationAdminPolicy`                                                                                     | `organization.manage_admins` + org scope             | —                           |
| `ClassModelPolicy` / `ClassSchedulePolicy` / `ClassPaymentSettingPolicy`                                      | permission + org scope                               | participant membership      |
| `ClassParticipantPolicy`                                                                                      | permission + org scope                               | `user_id` ownership         |
| `SponsorStudentPolicy`                                                                                        | `hasRole('ADMIN')` (view) / permission (manage)      | sponsor/student ownership   |
| `PaymentSchedulePolicy` / `PaymentPolicy` / `PaymentTransactionPolicy` / `PaymentProofPolicy`                 | permission + org scope                               | payer/participant ownership |
| `NotificationPolicy` / `UserDevicePolicy`                                                                     | —                                                    | strict ownership only       |
| `NotificationLogPolicy` / `NotificationTemplatePolicy` / `AuditLogPolicy` / `RolePolicy` / `PermissionPolicy` | `hasRole('ADMIN')` only (system/RBAC administration) | —                           |

None of these check `$user->role === 'admin'` or `$user->user_type` directly.

---

## Middleware

No new authorization middleware (e.g. `role:`/`permission:`) was added. All protected routes already use `auth:sanctum` for authentication; per-resource authorization is deliberately kept in Policies (as instructed — "avoid putting organization-specific authorization entirely inside middleware"), since almost every check here needs the specific resource instance (organization scope, ownership) rather than a route-level role gate. The two purely role-gated endpoint groups (`roles`, `permissions`, `audit-logs`, `notification-logs`, `notification-templates`) are protected via each Policy's `hasRole('ADMIN')` check rather than middleware, which keeps a single authorization mechanism (Policies) for the whole API instead of splitting logic between middleware and policies.

---

## HTTP 401 vs 403

- **401 Unauthenticated** — no/invalid Sanctum token. Handled by Laravel's `auth:sanctum` middleware.
- **403 Forbidden** — a valid, authenticated user who lacks permission/organization scope/ownership for that specific resource. All Policy denials render as:

    ```json
    { "message": "You are not authorized to perform this action." }
    ```

    configured in `bootstrap/app.php` by rendering `Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException` (the exception Laravel converts `AuthorizationException` into) with a generic message on any `api/*` route, so internal policy/permission logic is never leaked to the client.

- **404 Not found** — resource does not exist at all (standard Eloquent route-model-binding behavior, unchanged by this phase).

---

## Security rules enforced

- Every authorization decision re-derives ownership/scope from the database on every request; nothing is cached or trusted from the client.
- `user_type` supplied by a client is irrelevant — mass assignment on `user_type` is guarded by `$fillable`, and even the trusted server-side value only selects a _role_, never grants a permission directly.
- Admin access is always mediated by an **active** `organization_admins` row, never implied by being an admin `user_type`.
- Generic 403 responses avoid leaking why access was denied (missing permission vs. wrong organization vs. not a participant all look identical to the client).

---

## Tests

- `tests/Feature/Authorization/RbacAuthorizationTest.php` — 21 tests covering the full Phase 7 checklist: organization access (assigned/unassigned), class access across organizations, student/sponsor data isolation (own vs. others' payments and schedules), student/sponsor forbidden from admin actions (create organization/class, verify payment), 401 vs 403, permission-level enforcement independent of role/org membership, role-gated endpoints, multiple admins on one organization, and revocation of access when an `organization_admins` row is deactivated.
- `tests/TestCase.php` now seeds `RolePermissionSeeder` automatically (`protected $seed = true;`) for every test using `RefreshDatabase`, since policies depend on seeded permissions rather than `user_type`.
- Full suite: 67/67 passing after these changes.
