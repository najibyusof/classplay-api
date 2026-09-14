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

### Create Class Example (`POST /api/v1/organizations/1/classes`)

```json
{
    "name": "Form 5 Physics",
    "description": "Weekly SPM Physics class",
    "teacher_name": "Cikgu Ahmad",
    "start_date": "2026-01-01",
    "end_date": "2026-12-31"
}
```

#### Success Response (`201 Created`)

```json
{
    "success": true,
    "message": "Class created successfully.",
    "data": {
        "id": 10,
        "organization_id": 1,
        "name": "Form 5 Physics",
        "description": "Weekly SPM Physics class",
        "teacher_name": "Cikgu Ahmad",
        "status": "draft",
        "start_date": "2026-01-01",
        "end_date": "2026-12-31",
        "created_at": "2026-09-12T10:00:00.000000Z"
    }
}
```

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
