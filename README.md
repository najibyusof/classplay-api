# ClassPay API Backend

[![Build & Test Status](https://img.shields.io/badge/tests-306%20passed-brightgreen.svg)](docs/backend-readiness.md)
[![Framework](https://img.shields.io/badge/Laravel-13.x-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-lightgrey.svg)](LICENSE)

**ClassPay** is a tuition and class payment management platform for Malaysia (MYR currency). This repository contains the backend RESTful API built with **Laravel 13** and **PHP 8.3**.

The backend serves both the **Flutter Mobile Client** (for Students and Sponsors) and the **Web Admin Portal** (for Organization Admins and System Administrators).

---

## System Overview & Architecture

```
Flutter Mobile App / Web Admin
             │
             ├── REST API (/api/v1/...)
             │      │
             │      ▼
       Laravel Sanctum (auth:sanctum)
             │
             ├── RBAC & Organization Isolation Policies
             │
             ├── Controllers & Form Requests
             │      │
             │      ▼
       Domain Services (Thin Controllers, Rich Services)
             │   ├── OrganizationService / StudentService / SponsorService
             │   ├── PaymentScheduleService / PaymentService
             │   ├── PaymentGatewayInterface / MerchantPaymentGateway
             │   ├── PaymentWebhookService / PaymentWebhookVerifier
             │   ├── NotificationService / FcmPushNotificationService
             │   └── PaymentReminderService / DashboardService / PaymentReportService
             │
             ├── Relational Database Schema (MySQL / SQLite for tests)
             │
             └── Asynchronous Infrastructure
                 ├── Database Queue (`jobs`, `failed_jobs`)
                 └── Laravel Scheduler (`routes/console.php` in `Asia/Kuala_Lumpur`)
```

---

## Core Features & Modules

- **Authentication & Security**: Sanctum bearer tokens, phone/password login, account enumeration shielding, rate limiting (`throttle:login`), token revocation, and HTTPS CORS handling.
- **RBAC & Multi-Tenant Organization Isolation**: Roles, permissions, organization admin scoping, and strict participant IDOR protection.
- **Organization & Class Management**: Multi-org support, classes, recurring session slots, payment settings, DuitNow/Touch 'n Go QR code uploads, and atomic class activation.
- **Student & Sponsor Management**: Student profiles, sponsor profiles, active sponsorship linking, and class enrollments.
- **Payment Schedule Engine**: Automated recurring schedules, period math, due dates, and server-side status tracking.
- **Payment & Gateway Integration**: Server-calculated authoritative amounts (`required_amount + additional_infaq`), swappable `PaymentGatewayInterface` adapter, merchant initiation, and QR display flows.
- **Payment Webhooks**: HMAC signature verification, provider resolution, amount/currency validation, state machine transition safety, and duplicate/replay idempotency.
- **Notifications & FCM Push Engine**: In-app notifications, unread counts, template variable substitution, FCM token management, push notification delivery, and Flutter deep-link contract routing.
- **Payment Reminders**: Automated upcoming and overdue reminder evaluation (`Asia/Kuala_Lumpur` timezone), partial payment outstanding balance math, and deduplication.
- **Background Queues & Scheduler**: Asynchronous jobs (`GeneratePaymentRemindersJob`, `SendNotificationJob`), database queue driver, and crontab scheduling.
- **Admin Dashboard & Reporting APIs**: Global and org-level metrics, SQL aggregations, financial summaries, outstanding/overdue schedule reports, date filtering, and N+1 query avoidance.
- **API Standardization**: Standardized JSON envelopes (`success`, `message`, `data`, `errors`), pagination metadata, health check endpoint (`GET /api/v1/health`), and `/api/v1` versioning.

---

## API Documentation

Detailed documentation is available in the [`docs/`](docs/) directory:

- [API Documentation Index](docs/api/README.md)
- [Backend Readiness Report](docs/backend-readiness.md)
- [API Response Standard](docs/api/response-standard.md)
- [API Versioning & Routes](docs/api/versioning.md)
- [Authentication API](docs/api/authentication.md)
- [Organizations API](docs/api/organizations.md)
- [Classes API](docs/api/classes.md)
- [Students API](docs/api/students.md)
- [Sponsors API](docs/api/sponsors.md)
- [Participants API](docs/api/participants.md)
- [Payments API](docs/api/payments.md)
- [Payment Gateway Integration](docs/payment/payment-gateway.md)
- [Payment Webhooks Specification](docs/payment/payment-webhook.md)
- [Payment Reminders Engine](docs/payment/payment-reminders.md)
- [In-App Notifications API](docs/api/notifications.md)
- [Push Notifications & Devices](docs/notifications/push-notifications.md)
- [Admin Dashboard API](docs/api/dashboard.md)
- [Payment Reporting API](docs/api/reports.md)
- [Security Hardening Specification](docs/security/security-hardening.md)
- [Queues & Scheduler Infrastructure](docs/infrastructure/queues-and-scheduler.md)
- [Production Deployment Guide](docs/deployment/production.md)

---

## Local Development Setup

### Prerequisites

- PHP `^8.3`
- Composer `^2.x`
- MySQL `^8.0` or SQLite (for local testing)

### Installation Steps

1. **Clone the repository**:

    ```bash
    git clone https://github.com/najibyusof/classpay-api.git
    cd classpay-api
    ```

2. **Install PHP dependencies**:

    ```bash
    composer install
    ```

3. **Configure Environment File**:

    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

4. **Configure Database & Run Migrations**:
   Update `.env` with your database credentials, then run:

    ```bash
    php artisan migrate --seed
    ```

5. **Start Local Development Server**:

    ```bash
    php artisan serve
    ```

    The API will be available at `http://localhost:8000/api/v1/health`.

6. **Start Queue Worker & Scheduler (Optional for background processing)**:
    ```bash
    php artisan queue:work
    php artisan schedule:work
    ```

---

## Automated Test Suite

Run the full automated test suite (306 feature and unit tests):

```bash
php artisan test
```

Or run test suites directly with PHPUnit:

```bash
vendor/bin/phpunit
```

To format code according to project style guidelines:

```bash
vendor/bin/pint
```

---

## Production Deployment

Refer to the [Production Deployment Guide](docs/deployment/production.md) for detailed deployment steps, Supervisor worker configuration, crontab setup, and health check monitoring.

```bash
# Production Deployment Summary
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan storage:link
php artisan queue:restart
```

---

## License

The ClassPay API is proprietary software. All rights reserved.
