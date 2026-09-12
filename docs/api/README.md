# ClassPay REST API Documentation Index

Welcome to the ClassPay Laravel API backend documentation. The ClassPay API is a versioned RESTful JSON backend designed to serve both the Flutter mobile client (students & sponsors) and the Web Admin portal (organization admins & system administrators).

---

## Architecture & Conventions

- **Base URL**: `/api/v1`
- **Authentication**: Laravel Sanctum Bearer Token (`Authorization: Bearer {token}`)
- **Response Standard**: Standardized JSON envelope (`success`, `message`, `data`, `errors`, `pagination`)
- **Authorization Engine**: Custom RBAC (roles & permissions) + Organization Scoping & Participant Ownership

---

## API Documentation Modules

| Module                           | Documentation Link                                                                     | Description                                                                              |
| -------------------------------- | -------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| **Authentication**               | [authentication.md](authentication.md)                                                 | Login, logout, current user profile, password change, token refresh                      |
| **Response Standard**            | [response-standard.md](response-standard.md)                                           | Standard JSON envelopes, HTTP status code matrix, validation format, exception shielding |
| **API Versioning**               | [versioning.md](versioning.md)                                                         | `/api/v1` URL prefix, route naming conventions, backward compatibility                   |
| **Organizations**                | [organizations.md](organizations.md)                                                   | Organization CRUD, organization admin assignments                                        |
| **Classes**                      | [classes.md](classes.md)                                                               | Class CRUD, class activation, session schedules, payment settings, QR uploads            |
| **Students**                     | [students.md](students.md)                                                             | Student user management, listing, creation, and profile updates                          |
| **Sponsors**                     | [sponsors.md](sponsors.md)                                                             | Sponsor user management and sponsor-student relationship mapping                         |
| **Class Participants**           | [participants.md](participants.md)                                                     | Class enrollment (students & sponsors), status updates, removals                         |
| **Payment Schedules & Payments** | [payments.md](payments.md)                                                             | Self-service schedule listing, payment creation, infaq rules, payment history            |
| **Payment Gateway**              | [../payment/payment-gateway.md](../payment/payment-gateway.md)                         | Merchant gateway initiation contract, provider adapter, QR display flow                  |
| **Payment Webhooks**             | [../payment/payment-webhook.md](../payment/payment-webhook.md)                         | Webhook verifier, HMAC signatures, transaction confirmation, amount verification         |
| **Payment Reminders**            | [../payment/payment-reminders.md](../payment/payment-reminders.md)                     | Reminder business rules, due date math, partial payment calculations, deduplication      |
| **Notifications & Push**         | [notifications.md](notifications.md)                                                   | In-app notifications, unread count, read-all, FCM push registration                      |
| **Push Notifications**           | [../notifications/push-notifications.md](../notifications/push-notifications.md)       | Device token registration, FCM delivery engine, Flutter deep-link contract               |
| **Admin Dashboard**              | [dashboard.md](dashboard.md)                                                           | Aggregated metrics, organization statistics, financial totals, recent activity           |
| **Payment Reports**              | [reports.md](reports.md)                                                               | Payment audit listings, summary reports, outstanding & overdue schedule reports          |
| **Security Hardening**           | [../security/security-hardening.md](../security/security-hardening.md)                 | Security posture, IDOR protection, mass assignment rules, rate limiting, logging safety  |
| **Queue & Scheduler**            | [../infrastructure/queues-and-scheduler.md](../infrastructure/queues-and-scheduler.md) | Asynchronous jobs, database queue driver, cron worker configuration                      |

---

## Quick Reference — HTTP Status Matrix

| Status Code                 | Description              | Standard Meaning                                              |
| --------------------------- | ------------------------ | ------------------------------------------------------------- |
| `200 OK`                    | Successful Request       | Standard GET, PUT, PATCH, DELETE success                      |
| `201 Created`               | Resource Created         | Successful POST creation                                      |
| `401 Unauthorized`          | Unauthenticated          | Missing or invalid Sanctum bearer token                       |
| `403 Forbidden`             | Unauthorized / Forbidden | RBAC permission failure or organization isolation block       |
| `404 Not Found`             | Resource Not Found       | Route or database record does not exist                       |
| `422 Unprocessable Entity`  | Validation Error         | Form request or business rule validation error                |
| `429 Too Many Requests`     | Rate Limited             | Throttling limit exceeded                                     |
| `500 Internal Server Error` | Server Error             | Unhandled server error (debug details shielded in production) |
