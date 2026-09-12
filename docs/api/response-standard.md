# ClassPay API Response Standard

## Overview

All ClassPay REST API endpoints (`/api/v1/...`) enforce a unified, predictable JSON response envelope structure across all success and error outcomes.

---

## 1. Standard Success Response Envelope

All successful API operations return HTTP status `200 OK` or `201 Created` with a `success = true` envelope:

```json
{
    "success": true,
    "message": "Resource retrieved successfully.",
    "data": { ... }
}
```

- **`success`** (`boolean`): Always `true` for successful operations.
- **`message`** (`string`): Human-readable summary of the operation outcome.
- **`data`** (`mixed`): Payload object, array, or null.

### Example (Resource Created - 201 Created)

```json
{
    "success": true,
    "message": "Organization created successfully.",
    "data": {
        "id": 1,
        "name": "Al-Huda Education",
        "code": "AHE"
    }
}
```

---

## 2. Standard Error Response Envelope

All error outcomes (validation, authentication, authorization, not found, or server error) return a consistent `success = false` envelope:

```json
{
    "success": false,
    "message": "Error description.",
    "errors": { ... }
}
```

- **`success`** (`boolean`): Always `false` for error outcomes.
- **`message`** (`string`): Concise description of the failure reason.
- **`errors`** (`object`): Key-value map of field validation failures, or an empty object `{}` for non-validation errors.

---

## 3. Standardized Validation Error Response (422 Unprocessable Entity)

Form request or business rule validation failures automatically return HTTP 422:

```json
{
    "success": false,
    "message": "The given data was invalid.",
    "errors": {
        "email": ["The email field is required."],
        "phone": ["The phone format is invalid."]
    }
}
```

---

## 4. Standardized Pagination Response

Paginated list resources wrap the items array and a `pagination` metadata object inside `data`:

```json
{
    "success": true,
    "message": "Organizations retrieved successfully.",
    "data": {
        "organizations": [
            {
                "id": 1,
                "name": "Al-Huda Education",
                "code": "AHE"
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "total": 100,
            "last_page": 5
        }
    }
}
```

### Standard Pagination Metadata Fields

- `current_page` (`int`): Active page number (1-indexed).
- `per_page` (`int`): Number of items per page.
- `total` (`int`): Total count of matching records across all pages.
- `last_page` (`int`): Maximum page number available.

---

## 5. HTTP Status Codes Matrix

ClassPay API strictly adheres to standard HTTP status codes:

| Code    | Status                  | Meaning & ClassPay Standard Response                                                                                                                                     |
| ------- | ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **200** | `OK`                    | Successful GET, PUT, PATCH, or DELETE operation                                                                                                                          |
| **201** | `Created`               | Successful POST resource creation                                                                                                                                        |
| **400** | `Bad Request`           | Malformed request parameters or invalid payload structure                                                                                                                |
| **401** | `Unauthorized`          | Unauthenticated request (missing/expired Sanctum token): `{"success": false, "message": "Unauthenticated.", "errors": {}}`                                               |
| **403** | `Forbidden`             | Unauthorized action (user lacks RBAC role/permission or org membership): `{"success": false, "message": "You are not authorized to perform this action.", "errors": {}}` |
| **404** | `Not Found`             | Missing endpoint or model instance: `{"success": false, "message": "Resource not found.", "errors": {}}`                                                                 |
| **422** | `Unprocessable Entity`  | Validation or business logic rule failure                                                                                                                                |
| **429** | `Too Many Requests`     | Rate limit threshold exceeded                                                                                                                                            |
| **500** | `Internal Server Error` | Unexpected server failure (stack traces and internal details shielded in production): `{"success": false, "message": "Server error.", "errors": {}}`                     |

---

## 6. Exception Shielding & Security

Exception handling in `bootstrap/app.php` ensures that API requests consistently receive JSON error envelopes:

- Stack traces, raw SQL queries, environment credentials, and internal file paths are **never exposed** in production responses (`app.debug = false`).
- Sensitive model attributes (`password`, `remember_token`, API tokens) are strictly excluded from API Resource outputs.
