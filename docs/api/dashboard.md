# Admin Dashboard API (Phase 19)

## Overview

The **Admin Dashboard API** provides real-time aggregated metrics, class and participant statistics, payment counts, financial performance summaries, and recent payment activity for ClassPay administrators.

---

## Endpoints

All dashboard endpoints require `Authorization: Bearer {token}` (`auth:sanctum`).

| Method | Endpoint                                               | Authorization                 | Description                                                 |
| ------ | ------------------------------------------------------ | ----------------------------- | ----------------------------------------------------------- |
| `GET`  | `/api/v1/admin/dashboard`                              | Admin role / org admin access | Global dashboard aggregated across accessible organizations |
| `GET`  | `/api/v1/admin/organizations/{organization}/dashboard` | Org admin for specified org   | Organization-specific dashboard metrics                     |

---

## Authorization & Security

1. **Role & Permission Restriction**:
    - Access is restricted to active system administrators and organization admins.
    - Students and Sponsors calling dashboard endpoints receive `403 Forbidden`.
2. **Organization Scoping**:
    - For `GET /api/v1/admin/dashboard`, statistics are calculated strictly for organizations the authenticated user administers (`organization_admins.status = 'active'`).
    - If an admin is assigned to Organization A, metrics for Organization B are never included.
    - For `GET /api/v1/admin/organizations/{organization}/dashboard`, calling the dashboard for an unassigned organization returns `403 Forbidden`.
    - If an admin user has no assigned organizations, valid zeroed statistics are returned.

---

## Query Parameters

Both endpoints accept the following optional query parameters:

| Parameter             | Type    | Default | Purpose / Format                                     |
| --------------------- | ------- | ------- | ---------------------------------------------------- |
| `from` or `date_from` | string  | `null`  | Start date filter (ISO format `YYYY-MM-DD`).         |
| `to` or `date_to`     | string  | `null`  | End date filter (ISO format `YYYY-MM-DD`).           |
| `recent_limit`        | integer | `10`    | Limit for recent payments array (min `1`, max `50`). |

---

## Date Filter Semantics

When `from`/`date_from` and `to`/`date_to` are specified:

- **Payments / Collected Revenue / Recent Payments**: Filtered by payment timestamp (`COALESCE(paid_at, created_at)`).
- **Payment Schedules / Outstanding / Overdue Revenue**: Filtered by payment schedule due date (`due_date`).

---

## Financial Definitions

All financial metrics are calculated using server-side database aggregations and returned as formatted strings with two decimal places (e.g. `"6000.00"`):

- **Collected Amount** (`financial.collected`):
    - Total sum of `payments.total_amount` for all payments with `status = 'paid'` in accessible organizations.
- **Outstanding Amount** (`financial.outstanding`):
    - Total remaining unpaid obligation for active payment schedules that are not yet fully settled (`status` in `['upcoming', 'pending', 'partially_paid', 'overdue']`).
    - Calculated server-side as `sum(required_amount) - sum(paid_total_amount)` on non-cancelled unpaid schedules.
- **Overdue Amount** (`financial.overdue`):
    - Total remaining unpaid obligation for overdue payment schedules (`status = 'overdue'`).

---

## Response Formats

### 1. Global Admin Dashboard (`GET /api/v1/admin/dashboard`)

```json
{
    "success": true,
    "message": "Dashboard retrieved successfully.",
    "data": {
        "organizations": 3,
        "active_organizations": 3,
        "classes": 12,
        "active_classes": 10,
        "students": 150,
        "sponsors": 25,
        "participants": 175,
        "payment_schedules": {
            "total": 145,
            "upcoming": 20,
            "pending": 15,
            "overdue": 10,
            "paid": 100
        },
        "payments": {
            "total": 120,
            "paid": 100,
            "pending": 15,
            "overdue": 0,
            "failed": 5,
            "refunded": 0
        },
        "financial": {
            "collected": "6000.00",
            "outstanding": "750.00",
            "overdue": "500.00"
        },
        "recent_payments": [
            {
                "id": 88,
                "reference_number": "MER-88",
                "required_amount": "50.00",
                "additional_infaq": "0.00",
                "total_amount": "50.00",
                "currency": "MYR",
                "status": "paid",
                "payment_method": "merchant",
                "paid_at": "2026-09-12T10:00:00.000000Z",
                "created_at": "2026-09-12T09:55:00.000000Z",
                "payer": {
                    "id": 12,
                    "name": "Ahmad Student",
                    "email": "ahmad@example.com"
                },
                "class": {
                    "id": 5,
                    "name": "Form 5 Mathematics"
                }
            }
        ]
    }
}
```

### 2. Organization Dashboard (`GET /api/v1/admin/organizations/1/dashboard`)

```json
{
    "success": true,
    "message": "Organization dashboard retrieved successfully.",
    "data": {
        "organization": {
            "id": 1,
            "name": "Pusat Tuisyen ClassPay",
            "code": "PTCP",
            "status": "active"
        },
        "classes": {
            "total": 5,
            "active": 4,
            "inactive": 1
        },
        "participants": {
            "students": 50,
            "sponsors": 10,
            "total": 60
        },
        "payment_schedules": {
            "total": 50,
            "upcoming": 5,
            "pending": 10,
            "overdue": 5,
            "paid": 30
        },
        "payments": {
            "total": 40,
            "paid": 30,
            "pending": 5,
            "overdue": 0,
            "failed": 5,
            "refunded": 0
        },
        "financial": {
            "collected": "2500.00",
            "outstanding": "500.00",
            "overdue": "250.00"
        },
        "recent_payments": [ ... ]
    }
}
```
