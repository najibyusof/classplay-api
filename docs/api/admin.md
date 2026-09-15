# Admin API Reference for Mobile Apps

This document is the endpoint checklist for an admin-capable mobile client. It describes every authenticated management endpoint currently registered by the API. Detailed resource examples remain in the linked domain documents.

## Connection

- **Base URL:** `/api/v1`
- **Authentication:** `Authorization: Bearer {token}` from `POST /api/v1/auth/login`
- **Account registration:** `POST /api/v1/auth/register/admin`, `/register/student`, or `/register/sponsor`; see [authentication.md](authentication.md)
- **Password recovery:** `POST /api/v1/auth/forgot-password` followed by `POST /api/v1/auth/reset-password`; see [authentication.md](authentication.md)
- **Content type:** `application/json`, except file-upload endpoints, which use `multipart/form-data`
- **Date format:** `YYYY-MM-DD` for date-only filters and fields; timestamps are ISO 8601 strings
- **Money:** send decimal numbers; display values returned by the API as decimal strings with two places
- **Responses:** all responses use the standard envelope documented in [response-standard.md](response-standard.md)
- **Pagination:** list responses place the collection and `pagination` metadata inside `data`; default `per_page` is normally `20` and the maximum is `100`

A successful response has this shape:

```json
{
    "success": true,
    "message": "...",
    "data": {}
}
```

Do not infer access from an ID. Every organization, class, participant, payment, and user is checked against the authenticated admin's active organization assignments and permissions. A valid token without the required scope returns `403`.

## Admin Access Model

| Client account             | Typical access                                                                                     |
| -------------------------- | -------------------------------------------------------------------------------------------------- |
| System administrator       | Global admin resources allowed by assigned RBAC permissions                                        |
| Organization administrator | Resources belonging to organizations where the user has an active `organization_admins` assignment |
| Student or sponsor         | Mobile self-service endpoints only; admin endpoints return `403`                                   |

The exact permission is enforced by the policy for each resource. Common permissions are `organization.*`, `class.*`, `participant.*`, `payment.*`, `report.view`, and `organization.manage_admins`.

## Endpoint Inventory

The following tables are the complete admin management surface. `PUT|PATCH` means either HTTP method is accepted.

### Dashboard and Reports

Student and sponsor mobile clients also have self-service endpoints: `GET /{student\|sponsor}/classes` (their classes), `GET /{student\|sponsor}/organizations` (organizations of those classes), and the payment-schedule/payment endpoints — see [payments.md](payments.md).

| Method | Path                                            | Purpose                                         | Query parameters                                                                                                                                                |
| ------ | ----------------------------------------------- | ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET`  | `/admin/dashboard`                              | Aggregated metrics for accessible organizations | `from`/`date_from`, `to`/`date_to`, `recent_limit` (`1`-`50`, default `10`)                                                                                     |
| `GET`  | `/admin/organizations/{organization}/dashboard` | Metrics for one accessible organization         | Same date and `recent_limit` parameters                                                                                                                         |
| `GET`  | `/admin/payments`                               | Paginated payment listing                       | `organization_id`, `class_id`, `participant_id`, `student_id`, `sponsor_id`, `status`, `payment_method`, `from`/`date_from`, `to`/`date_to`, `page`, `per_page` |
| `GET`  | `/admin/reports/payment-summary`                | Payment counts and financial totals             | `organization_id`, `class_id`, `from`/`date_from`, `to`/`date_to`                                                                                               |
| `GET`  | `/admin/reports/outstanding`                    | Unpaid and partially paid schedules             | `organization_id`, `class_id`, `participant_id`, `student_id`, `from`/`date_from`, `to`/`date_to`, `page`, `per_page`                                           |
| `GET`  | `/admin/reports/overdue`                        | Overdue schedules and days overdue              | Same filters as outstanding                                                                                                                                     |

See [dashboard.md](dashboard.md) and [reports.md](reports.md) for response fields and financial definitions. Report date filters use payment timestamps for payment reports and schedule `due_date` for outstanding/overdue reports.

### Organizations and Administrators

| Method   | Path                                                | Body                                                                      |
| -------- | --------------------------------------------------- | ------------------------------------------------------------------------- | -------------------------------------------------------- |
| `GET`    | `/admin/organizations`                              | Query: `page`, `per_page`, `status`, `search`                             |
| `POST`   | `/admin/organizations`                              | `name`, optional `code`, `description`, `status` (`active` or `inactive`) |
| `GET`    | `/admin/organizations/{organization}`               | None                                                                      |
| `PUT     | PATCH`                                              | `/admin/organizations/{organization}`                                     | Any create field; all fields optional                    |
| `DELETE` | `/admin/organizations/{organization}`               | None                                                                      |
| `GET`    | `/admin/organizations/{organization}/admins`        | None                                                                      |
| `POST`   | `/admin/organizations/{organization}/admins`        | `user_id`, optional `is_primary`                                          |
| `PUT     | PATCH`                                              | `/admin/organizations/{organization}/admins/{user}`                       | Optional `is_primary`, `status` (`active` or `inactive`) |
| `DELETE` | `/admin/organizations/{organization}/admins/{user}` | None                                                                      |

Creating an organization assigns the creator as its primary active administrator. The `{user}` administrator path parameter is a **user ID**, not an `organization_admins` pivot ID. See [organizations.md](organizations.md).

### Students, Sponsors, and Relationships

| Method   | Path                                                | Body or query                                          |
| -------- | --------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------- |
| `GET`    | `/admin/students`                                   | Query: `page`, `per_page`, `search`, `status`          |
| `POST`   | `/admin/students`                                   | `name`, `phone`, optional `email`                      |
| `GET`    | `/admin/students/{student}`                         | None                                                   |
| `PUT     | PATCH`                                              | `/admin/students/{student}`                            | Optional `name`, `phone`, `email`, `status` (`active`, `inactive`, `suspended`) |
| `DELETE` | `/admin/students/{student}`                         | None                                                   |
| `GET`    | `/admin/sponsors`                                   | Query: `page`, `per_page`, `search`, `status`          |
| `POST`   | `/admin/sponsors`                                   | `name`, `phone`, optional `email`                      |
| `GET`    | `/admin/sponsors/{sponsor}`                         | None                                                   |
| `PUT     | PATCH`                                              | `/admin/sponsors/{sponsor}`                            | Optional `name`, `phone`, `email`, `status` (`active`, `inactive`, `suspended`) |
| `DELETE` | `/admin/sponsors/{sponsor}`                         | None                                                   |
| `GET`    | `/admin/sponsors/{sponsor}/students`                | None                                                   |
| `POST`   | `/admin/sponsors/{sponsor}/students`                | `student_id`, optional `relationship_type`             |
| `DELETE` | `/admin/sponsors/{sponsor}/students/{student}`      | None                                                   |
| `GET`    | `/admin/classes/{class}/participants`               | None                                                   |
| `POST`   | `/admin/classes/{class}/participants`               | `user_id`, `participant_type` (`student` or `sponsor`) |
| `PUT     | PATCH`                                              | `/admin/classes/{class}/participants/{participant}`    | `status` (`active`, `inactive`, or `removed`)                                   |
| `DELETE` | `/admin/classes/{class}/participants/{participant}` | None; logical removal                                  |

A client must not send `user_type`, `payer_id`, or ownership fields to create or reassign these records. See [students.md](students.md), [sponsors.md](sponsors.md), and [participants.md](participants.md).

### Classes, Sessions, and Payment Settings

Use the organization-scoped class detail routes for new mobile clients. The legacy class-only routes remain available for backward compatibility.

| Method       | Path                                                     | Body or query                                                                                                                                                                                                                                                                                      |
| ------------ | -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| `GET`        | `/organizations/{organization}/classes`                  | Query supported by class listing                                                                                                                                                                                                                                                                   |
| `POST`       | `/organizations/{organization}/classes`                  | `name`, `teacher_name`, `day_of_week` (0-6), `start_time` (`HH:MM`), `recurrence_type` (`weekly`/`fortnightly`/`monthly`), `payment_amount`; optional `description`, `status`, `start_date`, `end_date`. Creates class + first schedule + payment setting atomically; see [classes.md](classes.md) |
| `GET`        | `/organizations/{organization}/classes/{class}`          | None                                                                                                                                                                                                                                                                                               |
| `PUT\|PATCH` | `/organizations/{organization}/classes/{class}`          | Class fields; all update fields optional                                                                                                                                                                                                                                                           |
| `DELETE`     | `/organizations/{organization}/classes/{class}`          | None                                                                                                                                                                                                                                                                                               |
| `POST`       | `/organizations/{organization}/classes/{class}/activate` | None; activates and generates schedules                                                                                                                                                                                                                                                            |
| `GET`        | `/classes/{class}/schedules`                             | None                                                                                                                                                                                                                                                                                               |
| `POST`       | `/classes/{class}/schedules`                             | Schedule day/time fields; see [classes.md](classes.md)                                                                                                                                                                                                                                             |
| `PUT         | PATCH`                                                   | `/class-schedules/{classSchedule}`                                                                                                                                                                                                                                                                 | Schedule day/time fields                           |
| `DELETE`     | `/class-schedules/{classSchedule}`                       | None                                                                                                                                                                                                                                                                                               |
| `GET`        | `/classes/{class}/payment-setting`                       | None                                                                                                                                                                                                                                                                                               |
| `POST`       | `/classes/{class}/payment-setting`                       | Amount, currency, frequency, bank, infaq, and reminder settings                                                                                                                                                                                                                                    |
| `PUT         | PATCH`                                                   | `/classes/{class}/payment-setting`                                                                                                                                                                                                                                                                 | Payment-setting fields; all update fields optional |
| `DELETE`     | `/classes/{class}/payment-setting`                       | None                                                                                                                                                                                                                                                                                               |
| `POST`       | `/classes/{class}/payment-setting/qr-code`               | Multipart field `file`; image `jpg`, `jpeg`, `png`, or `webp`, max 5 MB                                                                                                                                                                                                                            |

Class activation is an atomic operation. The class must be ready for activation and its active participants receive generated payment schedules. See [classes.md](classes.md).

The nested class routes use scoped model binding. If `{class}` does not belong to `{organization}`, the API returns `404`.

### Payment Schedules, Payments, Proofs, and Transactions

| Method   | Path                                            | Body or query                                                                                          |
| -------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| `GET`    | `/payment-schedules/reminders/preview`          | Optional reminder/date filters supported by the reminder service                                       |
| `GET`    | `/classes/{class}/payment-schedules`            | None                                                                                                   |
| `POST`   | `/classes/{class}/payment-schedules`            | `class_participant_id`, `period_start`, `period_end`, `due_date`, `required_amount`, optional `status` |
| `GET`    | `/payment-schedules/{paymentSchedule}`          | None                                                                                                   |
| `PUT     | PATCH`                                          | `/payment-schedules/{paymentSchedule}`                                                                 | Optional `due_date`, `status` (`upcoming`, `pending`, `partially_paid`, `paid`, `overdue`, `cancelled`) |
| `DELETE` | `/payment-schedules/{paymentSchedule}`          | None                                                                                                   |
| `POST`   | `/payment-schedules/{paymentSchedule}/reminder` | None; sends a reminder                                                                                 |
| `GET`    | `/payment-schedules/{paymentSchedule}/payments` | None                                                                                                   |
| `POST`   | `/payment-schedules/{paymentSchedule}/payments` | `additional_infaq`, `payment_method`; server calculates amounts and payer                              |
| `GET`    | `/payments/{payment}`                           | None                                                                                                   |
| `PUT     | PATCH`                                          | `/payments/{payment}`                                                                                  | Optional `status`, `notes`                                                                              |
| `DELETE` | `/payments/{payment}`                           | None                                                                                                   |
| `GET`    | `/payments/{payment}/transactions`              | None                                                                                                   |
| `POST`   | `/payments/{payment}/transactions`              | `gateway_name`, `request_amount`, optional transaction and response fields                             |
| `GET`    | `/payments/{payment}/proofs`                    | None                                                                                                   |
| `POST`   | `/payments/{payment}/proofs`                    | Multipart field `file`; image `jpg`, `jpeg`, `png`, or `webp`, max 5 MB                                |
| `PUT     | PATCH`                                          | `/payment-proofs/{paymentProof}`                                                                       | `status` (`approved` or `rejected`), required `rejection_reason` when rejected                          |

Admin payment updates are for review and operational workflows. The API remains authoritative for schedule ownership, payer identity, calculated required amount, total amount, and payment state transitions. See [payments.md](payments.md) and [reports.md](reports.md).

### Notifications and Templates

The notification list and read actions are available to every authenticated user, including admins. The template and log actions are management endpoints.

| Method   | Path                                              | Body or query                                                                                              |
| -------- | ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| `GET`    | `/notifications`                                  | Query: `page`, `per_page`, `unread`, `type`; returns the current user's notifications                      |
| `GET`    | `/notifications/unread-count`                     | None                                                                                                       |
| `POST`   | `/notifications/read-all`                         | None                                                                                                       |
| `GET`    | `/notifications/{notification}`                   | None                                                                                                       |
| `POST`   | `/notifications/{notification}/read`              | None                                                                                                       |
| `POST`   | `/notifications/{notification}/unread`            | None                                                                                                       |
| `PUT     | PATCH`                                            | `/notifications/{notification}`                                                                            | Notification update fields, when allowed by policy |
| `DELETE` | `/notifications/{notification}`                   | None                                                                                                       |
| `GET`    | `/notifications/{notification}/logs`              | None                                                                                                       |
| `GET`    | `/notification-templates`                         | Query supported by template listing                                                                        |
| `POST`   | `/notification-templates`                         | `name`, `notification_type`, `channel` (`email`, `push`, `telegram`), `body`, optional `subject`, `status` |
| `GET`    | `/notification-templates/{notification_template}` | None                                                                                                       |
| `PUT     | PATCH`                                            | `/notification-templates/{notification_template}`                                                          | Optional template fields from create               |
| `DELETE` | `/notification-templates/{notification_template}` | None                                                                                                       |

Notification templates use `{{variable_name}}` placeholders. See [notifications.md](notifications.md).

### RBAC, Audit Logs, and Devices

| Method   | Path                          | Body or query                                 | Purpose                                |
| -------- | ----------------------------- | --------------------------------------------- | -------------------------------------- | ------------------------------ |
| `GET`    | `/roles`                      | Query supported by role listing               | List roles                             |
| `POST`   | `/roles`                      | `name`, optional `description`                | Create role                            |
| `GET`    | `/roles/{role}`               | None                                          | View role                              |
| `PUT     | PATCH`                        | `/roles/{role}`                               | Optional `name`, `description`         | Update role                    |
| `DELETE` | `/roles/{role}`               | None                                          | Delete role                            |
| `GET`    | `/permissions`                | Query supported by permission listing         | List permissions                       |
| `POST`   | `/permissions`                | `name`, optional `description`                | Create permission                      |
| `GET`    | `/permissions/{permission}`   | None                                          | View permission                        |
| `PUT     | PATCH`                        | `/permissions/{permission}`                   | Optional `name`, `description`         | Update permission              |
| `DELETE` | `/permissions/{permission}`   | None                                          | Delete permission                      |
| `GET`    | `/audit-logs`                 | Query: pagination and available audit filters | List scoped audit events               |
| `GET`    | `/audit-logs/{audit_log}`     | None                                          | View one scoped audit event            |
| `GET`    | `/devices`                    | Query: current user's devices                 | List current user's registered devices |
| `POST`   | `/devices`                    | Device token/platform fields                  | Register current user's device         |
| `GET`    | `/devices/{device}`           | None                                          | View current user's device             |
| `PUT     | PATCH`                        | `/devices/{device}`                           | Device token/platform fields           | Update current user's device   |
| `DELETE` | `/devices/{device}`           | None                                          | Remove current user's device           |
| `GET`    | `/user-devices`               | Query: current user's devices                 | Legacy alias for device listing        |
| `POST`   | `/user-devices`               | Device token/platform fields                  | Legacy alias for device registration   |
| `PUT     | PATCH`                        | `/user-devices/{user_device}`                 | Device token/platform fields           | Legacy alias for device update |
| `DELETE` | `/user-devices/{user_device}` | None                                          | Legacy alias for device removal        |

The `/user-devices` routes are retained for compatibility; new mobile code should use `/devices`. RBAC operations are normally reserved for system administrators. Audit logs are always organization-scoped.

## Error Handling for Mobile Clients

| Status | Meaning                                                           | Client action                                                             |
| ------ | ----------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `401`  | Missing, invalid, or expired bearer token                         | Re-authenticate; do not retry unchanged                                   |
| `403`  | Authenticated but missing role, permission, or organization scope | Hide or disable the operation; do not treat as missing data               |
| `404`  | Route-bound resource does not exist                               | Refresh stale IDs or return a not-found state                             |
| `422`  | Validation or business rule failure                               | Display `errors` by field and preserve user input                         |
| `429`  | Rate limit exceeded                                               | Apply backoff before retrying                                             |
| `500`  | Unexpected server failure                                         | Show a retryable error and report the request context without credentials |

For the complete envelope and validation examples, see [response-standard.md](response-standard.md). For login, token lifecycle, and password operations, see [authentication.md](authentication.md).

## Implementation Checklist

Before shipping an admin mobile screen, verify that it:

1. Sends the bearer token and handles `401` globally.
2. Uses the exact `/api/v1` prefix and path parameter names shown above.
3. Uses server response IDs for subsequent requests and never assumes sequential IDs.
4. Sends `multipart/form-data` for QR code and payment-proof uploads.
5. Reads pagination from `data.pagination` rather than calculating it locally.
6. Treats `403` as an authorization state and `422` as a validation/business-rule state.
7. Never sends server-owned fields such as payer, total amount, ownership, timestamps, or password values.
