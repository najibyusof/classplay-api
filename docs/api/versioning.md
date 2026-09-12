# ClassPay API Versioning & Route Organization

## Overview

All ClassPay REST API routes are strictly versioned under the `/api/v1` URL path prefix and named using the `v1.` route name prefix.

No unversioned API routes exist in the application.

---

## 1. Route Hierarchy & Grouping

Routes are logically organized in `routes/api.php` under the `v1` prefix group:

```
/api/v1
 ├── auth/                            (Authentication & Account Management)
 ├── payment/webhook/{provider}        (Public Payment Webhooks)
 ├── admin/                           (Administrative Management & Reports)
 │   ├── dashboard                    (Global Admin Dashboard)
 │   ├── organizations/               (Organization CRUD & Admin Users)
 │   ├── students/                    (Student User Management)
 │   ├── sponsors/                    (Sponsor User Management & Sponsorships)
 │   ├── classes/                     (Class Management & Activation)
 │   ├── payments                     (Payment Audit Listing)
 │   └── reports/                     (Payment Summary, Outstanding & Overdue Reports)
 ├── student/                         (Self-Service Student Client Endpoints)
 │   ├── payment-schedules/           (Student's Payment Schedules)
 │   └── payments/                    (Student's Payment History & Gateway Initiation)
 ├── sponsor/                         (Self-Service Sponsor Client Endpoints)
 │   ├── payment-schedules/           (Sponsored Students' Schedules)
 │   └── payments/                    (Sponsor's Payment History & Gateway Initiation)
 ├── classes/                         (Class Schedules & Payment Settings)
 ├── payment-schedules/               (Schedule Details & Manual Reminders)
 ├── payments/                        (Payment Details, Transactions, & Proof Uploads)
 ├── notifications/                   (In-App User Notifications & Unread Counts)
 ├── devices/                         (Mobile FCM Token Registration)
 └── notification-templates/          (Admin Notification Templates)
```

---

## 2. Route Naming Conventions

All route names strictly follow dot-notation prefixed with `v1.`:

### Authentication (`v1.auth.*`)

- `v1.auth.login` — `POST /api/v1/auth/login`
- `v1.auth.logout` — `POST /api/v1/auth/logout`
- `v1.auth.me` — `GET /api/v1/auth/me`
- `v1.auth.change-password` — `POST /api/v1/auth/change-password`
- `v1.auth.set-password` — `POST /api/v1/auth/set-password`
- `v1.auth.refresh-token` — `POST /api/v1/auth/refresh-token`

### Admin Management (`v1.admin.*`)

- `v1.admin.dashboard` — `GET /api/v1/admin/dashboard`
- `v1.admin.organizations.index` — `GET /api/v1/admin/organizations`
- `v1.admin.organizations.dashboard` — `GET /api/v1/admin/organizations/{organization}/dashboard`
- `v1.admin.students.index` — `GET /api/v1/admin/students`
- `v1.admin.sponsors.index` — `GET /api/v1/admin/sponsors`
- `v1.admin.classes.activate` — `POST /api/v1/admin/classes/{class}/activate`
- `v1.admin.payments.index` — `GET /api/v1/admin/payments`
- `v1.admin.reports.payment-summary` — `GET /api/v1/admin/reports/payment-summary`
- `v1.admin.reports.outstanding` — `GET /api/v1/admin/reports/outstanding`
- `v1.admin.reports.overdue` — `GET /api/v1/admin/reports/overdue`

### Participant Self-Service (`v1.student.*` & `v1.sponsor.*`)

- `v1.student.payment-schedules.index` — `GET /api/v1/student/payment-schedules`
- `v1.student.payment-schedules.current` — `GET /api/v1/student/payment-schedules/current`
- `v1.student.payments.index` — `GET /api/v1/student/payments`
- `v1.student.payments.initiate` — `POST /api/v1/student/payments/{payment}/initiate`
- `v1.sponsor.payment-schedules.index` — `GET /api/v1/sponsor/payment-schedules`
- `v1.sponsor.payments.initiate` — `POST /api/v1/sponsor/payments/{payment}/initiate`

---

## 3. Controller Architecture

All API controllers are located under `App\Http\Controllers\Api\`:

- Auth Controller: `App\Http\Controllers\Api\V1\Auth\AuthController`
- Core Controllers: `App\Http\Controllers\Api\*Controller`

Controllers remain thin, delegating domain logic to service classes (`OrganizationService`, `StudentService`, `PaymentScheduleService`, `PaymentService`, `PaymentWebhookService`, `NotificationService`, `FcmPushNotificationService`, `PaymentReminderService`, `DashboardService`, `PaymentReportService`).

---

## 4. Middleware & Authorization

- **Public Routes**:
    - `POST /api/v1/auth/login`
    - `POST /api/v1/payment/webhook/{provider}` (Protected via provider signature verification in `PaymentWebhookVerifier`)
- **Authenticated Routes**:
    - Protected via `auth:sanctum` middleware (`Laravel\Sanctum\Http\Middleware\AuthenticateSession` / Bearer token validation).
- **Authorization Policies**:
    - Model access and actions are strictly governed by Laravel Policies (`OrganizationPolicy`, `ClassModelPolicy`, `PaymentSchedulePolicy`, `PaymentPolicy`, `NotificationPolicy`, `UserDevicePolicy`) enforcing RBAC permissions and organization/ownership boundaries.

---

## 5. Backward Compatibility & Future Versioning Strategy

- **Current Version**: `v1`
- **Deprecation Policy**: If breaking changes are required in future phases, a new `/api/v2` route group will be introduced while maintaining `/api/v1` routes during a migration window.
- **Flutter Client Integration**: The Flutter mobile app communicates exclusively with `/api/v1/...` routes.
