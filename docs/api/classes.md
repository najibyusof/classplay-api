# Class Management API

## Overview

The Class Management API provides endpoints to create, view, update, delete, and activate classes within an organization, as well as configure class recurring schedules and payment settings (e.g. required amounts, infaq limits, bank account details, and QR codes).

---

## 1. Class Endpoints

All endpoints require `Authorization: Bearer {token}` (`auth:sanctum`).

| Method       | Endpoint                                       | Authorization                        | Description                                                      |
| ------------ | ---------------------------------------------- | ------------------------------------ | ---------------------------------------------------------------- |
| `GET`        | `/api/v1/organizations/{organization}/classes` | `class.view` & Org Admin             | List all classes in an organization                              |
| `POST`       | `/api/v1/organizations/{organization}/classes` | `class.create` & Org Admin           | Create a new class in an organization                            |
| `GET`        | `/api/v1/classes/{class}`                      | `class.view` & Enrolled or Org Admin | View class details                                               |
| `PUT\|PATCH` | `/api/v1/classes/{class}`                      | `class.update` & Org Admin           | Update class details                                             |
| `DELETE`     | `/api/v1/classes/{class}`                      | `class.delete` & Org Admin           | Delete a class                                                   |
| `POST`       | `/api/v1/admin/classes/{class}/activate`       | `class.update` & Org Admin           | Activate a draft class and atomically generate payment schedules |

### Organization-scoped class URLs

New clients should use the organization-scoped equivalents below. The legacy `/classes/{class}` and `/admin/classes/{class}` routes remain available for backward compatibility.

| Method       | Canonical endpoint                                              | Description                                  |
| ------------ | --------------------------------------------------------------- | -------------------------------------------- |
| `GET`        | `/api/v1/organizations/{organization}/classes/{class}`          | View a class belonging to the organization   |
| `PUT\|PATCH` | `/api/v1/organizations/{organization}/classes/{class}`          | Update a class belonging to the organization |
| `DELETE`     | `/api/v1/organizations/{organization}/classes/{class}`          | Delete a class belonging to the organization |
| `POST`       | `/api/v1/organizations/{organization}/classes/{class}/activate` | Activate the class and generate schedules    |

These class detail, update, delete, and activation routes use scoped model binding. If the class does not belong to `{organization}`, the API returns `404` and does not execute the controller action. Child resources retain their existing paths for backward compatibility and remain authorized through the class relationship and policies.

### Self-service class & organization listing

Students and sponsors have their own endpoints, scoped to classes they actively participate in (for sponsors, also classes of students they actively sponsor via `sponsor_students`):

| Method | Endpoint                        | Description                                        |
| ------ | ------------------------------- | -------------------------------------------------- |
| `GET`  | `/api/v1/student/classes`       | List classes the student actively participates in  |
| `GET`  | `/api/v1/sponsor/classes`       | List own + sponsored students' classes             |
| `GET`  | `/api/v1/student/organizations` | Organizations of the student's classes             |
| `GET`  | `/api/v1/sponsor/organizations` | Organizations of own + sponsored students' classes |

All four return the standard paginated envelope (`data.classes` / `data.organizations` + `data.pagination`) with `page`/`per_page` (max 100) query support. Removed or inactive participations are excluded.

### Create Class Example (`POST /api/v1/organizations/1/classes`)

A single request creates the class, its first recurring schedule (Day / Time / Frequency), and its payment setting (Payment Amount) in one atomic database transaction.

#### Request Body

| Field             | Type    | Required | Description                                                        |
| ----------------- | ------- | -------- | ------------------------------------------------------------------ |
| `name`            | string  | Yes      | Class name, max 150 chars                                          |
| `teacher_name`    | string  | Yes      | Teacher name, max 150 chars                                        |
| `description`     | string  | No       | Free-text description                                              |
| `status`          | string  | No       | `draft` (default), `active`, `inactive`, `completed`               |
| `start_date`      | date    | No       | First day of the class                                             |
| `end_date`        | date    | No       | Last day; must be on/after `start_date`                            |
| `day_of_week`     | integer | Yes      | Session day: `0` = Sunday … `6` = Saturday                         |
| `start_time`      | string  | Yes      | Session start time, `HH:MM` 24-hour format (e.g. `10:00`)          |
| `recurrence_type` | string  | Yes      | `weekly`, `fortnightly`, or `monthly`; also sets payment frequency |
| `payment_amount`  | number  | Yes      | Required payment amount in MYR (e.g. `50.00`)                      |

```json
{
    "name": "Quran Class",
    "teacher_name": "Cikgu Ahmad",
    "day_of_week": 1,
    "start_time": "10:00",
    "recurrence_type": "weekly",
    "payment_amount": 50.0
}
```

#### Success Response (`201 Created`)

The response includes the created `schedules` and `payment_setting` resources.

```json
{
    "success": true,
    "message": "Class created successfully.",
    "data": {
        "id": 10,
        "organization_id": 1,
        "name": "Quran Class",
        "description": null,
        "teacher_name": "Cikgu Ahmad",
        "status": "draft",
        "start_date": null,
        "end_date": null,
        "created_by": 1,
        "schedules": [
            {
                "id": 5,
                "class_id": 10,
                "day_of_week": 1,
                "start_time": "10:00:00",
                "end_time": null,
                "timezone": null,
                "recurrence_type": "weekly",
                "effective_from": "2026-09-15",
                "effective_until": null
            }
        ],
        "payment_setting": {
            "id": 5,
            "class_id": 10,
            "required_amount": "50.00",
            "currency": "MYR",
            "payment_frequency": "weekly",
            "bank_name": null,
            "bank_account_name": null,
            "bank_account_number": null,
            "qr_code_path": null,
            "merchant_payment_url": null,
            "allow_additional_infaq": null,
            "minimum_infaq": null,
            "maximum_infaq": null,
            "reminder_enabled": null,
            "reminder_days_before": null,
            "reminder_days_after": null
        },
        "created_at": "2026-09-15T10:00:00.000000Z",
        "updated_at": "2026-09-15T10:00:00.000000Z"
    }
}
```

#### Validation Error (`422 Unprocessable Content`)

Missing any of the required schedule/payment fields returns field-level errors:

```json
{
    "success": false,
    "message": "The given data was invalid.",
    "errors": {
        "teacher_name": ["The teacher name field is required."],
        "day_of_week": ["The day of week field is required."],
        "start_time": ["The start time field is required."],
        "recurrence_type": ["The recurrence type field is required."],
        "payment_amount": ["The payment amount field is required."]
    }
}
```

> **Note:** The class is created with status `draft` by default. Use the activate endpoint (section 4) to make it active and generate payment schedules for participants. Bank details, QR code, and infaq limits are configured separately via the payment-setting endpoints (section 3).

### View Class (`GET /api/v1/classes/{class}` or `/api/v1/organizations/{organization}/classes/{class}`)

Returns a single class. The schedule (`day_of_week`, `start_time`, `frequency`) and `payment_amount` are exposed as flat attributes on the class; the recurring `schedules` and `payment_setting` relationships are included when loaded.

```json
{
    "success": true,
    "message": "Class retrieved successfully.",
    "data": {
        "id": 1,
        "organization_id": 1,
        "name": "Quran Class",
        "description": "Weekly Quran recitation class.",
        "teacher_name": "Cikgu Ahmad",
        "day_of_week": 1,
        "start_time": "10:00:00",
        "frequency": "monthly",
        "payment_amount": "150.00",
        "status": "active",
        "start_date": "2026-09-01",
        "end_date": null,
        "created_by": 1,
        "created_at": "2026-09-15T10:00:00.000000Z",
        "updated_at": "2026-09-15T10:00:00.000000Z"
    }
}
```

### Update Class (`PUT|PATCH /api/v1/organizations/{organization}/classes/{class}`)

All update fields are optional. Only these attributes are updatable through this endpoint; schedule (`day_of_week`, `start_time`, `recurrence_type`) and `payment_amount` are managed via the class-schedule and payment-setting endpoints (sections 2 and 3), not the class update route.

#### Request Body

| Field          | Type   | Required | Description                                |
| -------------- | ------ | -------- | ------------------------------------------ |
| `name`         | string | No       | Class name, max 150 chars                  |
| `description`  | string | No       | Free-text description                      |
| `teacher_name` | string | No       | Teacher name, max 150 chars                |
| `status`       | string | No       | `draft`, `active`, `inactive`, `completed` |
| `start_date`   | date   | No       | First day of the class                     |
| `end_date`     | date   | No       | Last day; must be on/after `start_date`    |

Returns the updated class using the same shape as the view response above.

---

## 2. Class Schedules (Days & Times)

| Method       | Endpoint                                  | Authorization              | Description                                           |
| ------------ | ----------------------------------------- | -------------------------- | ----------------------------------------------------- |
| `GET`        | `/api/v1/classes/{class}/schedules`       | `class.view`               | List recurring class session schedules                |
| `POST`       | `/api/v1/classes/{class}/schedules`       | `class.update` & Org Admin | Add a session schedule slot (e.g. Monday 20:00-22:00) |
| `PUT\|PATCH` | `/api/v1/class-schedules/{classSchedule}` | `class.update` & Org Admin | Update a session schedule slot                        |
| `DELETE`     | `/api/v1/class-schedules/{classSchedule}` | `class.update` & Org Admin | Remove a session schedule slot                        |

---

## 3. Class Payment Settings

| Method       | Endpoint                                          | Authorization              | Description                                |
| ------------ | ------------------------------------------------- | -------------------------- | ------------------------------------------ |
| `GET`        | `/api/v1/classes/{class}/payment-setting`         | `class.view`               | View payment configuration for a class     |
| `POST`       | `/api/v1/classes/{class}/payment-setting`         | `class.update` & Org Admin | Create payment configuration               |
| `PUT\|PATCH` | `/api/v1/classes/{class}/payment-setting`         | `class.update` & Org Admin | Update payment configuration               |
| `DELETE`     | `/api/v1/classes/{class}/payment-setting`         | `class.update` & Org Admin | Delete payment configuration               |
| `POST`       | `/api/v1/classes/{class}/payment-setting/qr-code` | `class.update` & Org Admin | Upload DuitNow / Touch 'n Go QR code image |

### Payment Setting Body Example (`POST /api/v1/classes/10/payment-setting`)

```json
{
    "required_amount": 100.0,
    "currency": "MYR",
    "payment_frequency": "monthly",
    "bank_name": "Maybank",
    "bank_account_name": "Pusat Tuisyen ClassPay",
    "bank_account_number": "551234567890",
    "allow_additional_infaq": true,
    "minimum_infaq": 5.0,
    "maximum_infaq": 500.0,
    "reminder_enabled": true,
    "reminder_days_before": 3,
    "reminder_days_after": 3
}
```

---

## 4. Class Activation & Payment Schedule Generation

`POST /api/v1/admin/classes/{class}/activate`

Activates a draft class and generates initial payment schedules for all enrolled active participants inside an atomic database transaction.

#### Success Response (`200 OK`)

```json
{
    "success": true,
    "message": "Class activated and payment schedules generated successfully.",
    "data": {
        "id": 10,
        "name": "Form 5 Physics",
        "status": "active"
    }
}
```
