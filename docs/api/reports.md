# Payment Reporting API (Phase 20)

## Overview

The **Payment Reporting API** provides backend endpoints for listing, filtering, and summarizing payment attempts and payment schedule obligations for ClassPay administrators.

Reports use server-side database aggregations and strict organization-level authorization scoping.

---

## Endpoints

All reporting endpoints require `Authorization: Bearer {token}` (`auth:sanctum`) and are restricted to system administrators and organization admins (`report.view`, `payment.view`, or `organization.view` permissions). Students and Sponsors calling these endpoints receive `403 Forbidden`.

| Method | Endpoint                                | Description                                                  |
| ------ | --------------------------------------- | ------------------------------------------------------------ |
| `GET`  | `/api/v1/admin/payments`                | Paginated listing of payments with multi-criteria filtering  |
| `GET`  | `/api/v1/admin/reports/payment-summary` | Aggregated summary counts and financial metrics              |
| `GET`  | `/api/v1/admin/reports/outstanding`     | Report on unpaid and partially paid payment schedules        |
| `GET`  | `/api/v1/admin/reports/overdue`         | Report on past-due payment schedules with overdue day counts |

---

## Date Semantics

To ensure consistent business logic across reports:

- **Collection / Payment Metrics** (`paid_at` / `COALESCE(paid_at, created_at)`):
    - Used when filtering payments or total collected revenue (`from` and `to` filters).
    - Reflects when money was actually received or recorded.
- **Outstanding / Overdue Schedule Metrics** (`due_date`):
    - Used when filtering payment schedules in outstanding and overdue reports.
    - Reflects the business deadline when payment obligation was due.

---

## Authorization & Security

1. **Role & User Type Scoping**:
    - Access is restricted to `admin` user types. Requests from `student` or `sponsor` user types return `403 Forbidden`.
2. **Organization Isolation**:
    - Reports automatically scope data to organizations administered by the user (`organization_admins.status = 'active'`).
    - If an admin passes `?organization_id=X`, the API verifies `X` is within the user's authorized organization list. Passing an unauthorized organization ID returns `403 Forbidden`.
    - Admin users with no assigned organizations receive valid empty/zero report responses.

---

## Available Query Filters

### 1. Payment Listing (`GET /api/v1/admin/payments`)

- `organization_id` (int) — Scope report to a specific organization.
- `class_id` (int) — Filter by class ID.
- `participant_id` (int) — Filter by class participant ID.
- `student_id` (int) — Filter by student user ID.
- `sponsor_id` (int) — Filter by sponsor user ID (payer).
- `status` (string) — Filter by status (e.g. `paid`, `pending`, `failed`, `refunded`).
- `payment_method` (string) — Filter by method (`merchant`, `qr`, `bank_transfer`, `manual`).
- `from` or `date_from` (date `YYYY-MM-DD`) — Start date filter on payment date.
- `to` or `date_to` (date `YYYY-MM-DD`) — End date filter on payment date.
- `page` (int, default `1`) — Page number.
- `per_page` (int, default `20`, max `100`) — Records per page.

### 2. Payment Summary (`GET /api/v1/admin/reports/payment-summary`)

- `organization_id` (int)
- `class_id` (int)
- `from` or `date_from` (date `YYYY-MM-DD`)
- `to` or `date_to` (date `YYYY-MM-DD`)

### 3. Outstanding Report (`GET /api/v1/admin/reports/outstanding`) & Overdue Report (`GET /api/v1/admin/reports/overdue`)

- `organization_id` (int)
- `class_id` (int)
- `participant_id` (int)
- `student_id` (int)
- `from` or `date_from` (date `YYYY-MM-DD`, filters `due_date`)
- `to` or `date_to` (date `YYYY-MM-DD`, filters `due_date`)
- `page` (int, default `1`)
- `per_page` (int, default `20`, max `100`)

---

## Financial Definitions

All monetary values are calculated server-side using database aggregations and formatted with two decimal places (e.g. `"110.00"`):

- **Total Collected** (`total_collected`):
    - Sum of `payments.total_amount` where `status = 'paid'`.
- **Total Outstanding** (`total_outstanding` / `outstanding_amount`):
    - Sum of remaining unpaid obligation for active payment schedules (`status` NOT IN `['paid', 'cancelled']`).
    - Calculated as `max(0, required_amount - total_paid_amount)`.
- **Total Overdue** (`total_overdue`):
    - Sum of remaining unpaid obligation for schedules past their due date (`due_date < today` or `status = 'overdue'`).

---

## Response Examples

### 1. Payment Listing (`GET /api/v1/admin/payments`)

```json
{
    "success": true,
    "message": "Payments retrieved successfully.",
    "data": {
        "payments": [
            {
                "id": 1,
                "reference_number": "MER-88123",
                "required_amount": "100.00",
                "additional_infaq": "10.00",
                "total_amount": "110.00",
                "currency": "MYR",
                "status": "paid",
                "payment_method": "merchant",
                "paid_at": "2026-09-10T10:00:00.000000Z",
                "created_at": "2026-09-10T09:55:00.000000Z",
                "payer": {
                    "id": 5,
                    "name": "Ahmad Student",
                    "email": "ahmad@example.com"
                },
                "class": {
                    "id": 2,
                    "name": "Form 5 Physics"
                },
                "organization": {
                    "id": 1,
                    "name": "Pusat Tuisyen ClassPay"
                }
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

### 2. Payment Summary (`GET /api/v1/admin/reports/payment-summary`)

```json
{
    "success": true,
    "message": "Payment summary report retrieved successfully.",
    "data": {
        "total_payments": 120,
        "successful_payments": 100,
        "pending_payments": 15,
        "failed_payments": 5,
        "refunded_payments": 0,
        "total_collected": "11000.00",
        "total_outstanding": "1500.00",
        "total_overdue": "500.00",
        "payment_count": 120
    }
}
```

### 3. Outstanding Report (`GET /api/v1/admin/reports/outstanding`)

```json
{
    "success": true,
    "message": "Outstanding report retrieved successfully.",
    "data": {
        "schedules": [
            {
                "id": 10,
                "organization": {
                    "id": 1,
                    "name": "Pusat Tuisyen ClassPay"
                },
                "class": {
                    "id": 2,
                    "name": "Form 5 Physics"
                },
                "participant": {
                    "id": 15,
                    "user_id": 5,
                    "name": "Ahmad Student",
                    "email": "ahmad@example.com"
                },
                "period": {
                    "start": "2026-09-01",
                    "end": "2026-09-30"
                },
                "due_date": "2026-09-15",
                "required_amount": "100.00",
                "amount_paid": "30.00",
                "outstanding_amount": "70.00",
                "status": "pending"
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

### 4. Overdue Report (`GET /api/v1/admin/reports/overdue`)

```json
{
    "success": true,
    "message": "Overdue report retrieved successfully.",
    "data": {
        "schedules": [
            {
                "id": 12,
                "organization": {
                    "id": 1,
                    "name": "Pusat Tuisyen ClassPay"
                },
                "class": {
                    "id": 2,
                    "name": "Form 5 Physics"
                },
                "participant": {
                    "id": 15,
                    "user_id": 5,
                    "name": "Ahmad Student",
                    "email": "ahmad@example.com"
                },
                "period": {
                    "start": "2026-08-01",
                    "end": "2026-08-31"
                },
                "due_date": "2026-09-01",
                "required_amount": "50.00",
                "amount_paid": "0.00",
                "outstanding_amount": "50.00",
                "status": "overdue",
                "days_overdue": 11
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
