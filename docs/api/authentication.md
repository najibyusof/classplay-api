# Authentication API

Base URL: `/api/v1/auth`

All authentication endpoints return a consistent JSON envelope:

**Success**

```json
{
    "success": true,
    "message": "...",
    "data": {}
}
```

**Error**

```json
{
    "success": false,
    "message": "...",
    "errors": {}
}
```

Authenticated endpoints require a Sanctum bearer token:

```
Authorization: Bearer {token}
```

Tokens are Laravel Sanctum personal access tokens. Each login call creates a new token named after the supplied `device_name`, so a user can hold one active token per device (Android Phone, iPhone, Tablet, ...). Logout only revokes the token used for the current request.

## POST /api/v1/auth/register/{userType}

Create an active account and immediately receive a Sanctum bearer token. The `{userType}` path value must be `admin`, `student`, or `sponsor`.

- **Auth required:** No
- **Rate limit:** 3 registration requests per minute per IP address
- **Headers:** `Accept: application/json`

### Request body

| Field                   | Type   | Rules                                                  |
| ----------------------- | ------ | ------------------------------------------------------ |
| `name`                  | string | required, max 150 characters                           |
| `phone`                 | string | required, normalized to `+60XXXXXXXXX`, unique         |
| `email`                 | string | optional, valid email, max 150 characters              |
| `password`              | string | required, minimum 8 characters                         |
| `password_confirmation` | string | must match `password`                                  |
| `device_name`           | string | optional, max 150 characters; defaults to `mobile-app` |

Example for the admin account screen:

```http
POST /api/v1/auth/register/admin
Content-Type: application/json
```

```json
{
    "name": "Admin User",
    "phone": "0123456789",
    "email": "admin@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "device_name": "Admin Mobile App"
}
```

Use the same request body with `/register/student` or `/register/sponsor` for the other account types. The server derives `user_type` from the URL; clients must not send it as a body field.

### Success response — 201

The response uses the same `user`, `token`, and `token_type` shape as login:

```json
{
    "success": true,
    "message": "Registration successful.",
    "data": {
        "user": {
            "id": 10,
            "name": "Admin User",
            "phone": "+60123456789",
            "email": "admin@example.com",
            "user_type": "admin",
            "status": "active",
            "phone_verified_at": null,
            "last_login_at": null
        },
        "token": "10|abcdef...",
        "token_type": "Bearer"
    }
}
```

The password is hashed and never returned. Registration also synchronizes the matching RBAC role (`ADMIN`, `STUDENT`, or `SPONSOR`). Phone verification is not performed by this endpoint; `phone_verified_at` remains null until a verification flow is introduced.

### Error responses

| Status | Cause                                                                                  |
| ------ | -------------------------------------------------------------------------------------- |
| 422    | Missing or invalid fields, mismatched password confirmation, or duplicate phone number |
| 429    | More than 3 registration requests from the same IP in one minute                       |

Admin registration is intentionally exposed as a public registration path for the admin mobile onboarding flow. If production deployment should restrict who can create administrators, this endpoint must be placed behind an invitation or approval mechanism before release.

---

## POST /api/v1/auth/forgot-password

Request a password-reset link by email. This endpoint does not require authentication and always returns the same successful response whether or not the email belongs to an account.

- **Auth required:** No
- **Rate limit:** 5 requests per minute per IP address

### Request body

```json
{
    "email": "user@example.com"
}
```

### Success response — 200

```json
{
    "success": true,
    "message": "If the account exists, a password reset link has been sent.",
    "data": null
}
```

The reset notification is sent through Laravel's configured mailer. The link contains a short-lived, single-use broker token. Configure `MAIL_*` values and `APP_URL` before using this endpoint outside local development.

### Error responses

| Status | Cause                                               |
| ------ | --------------------------------------------------- |
| 422    | Missing or invalid email                            |
| 429    | More than 5 requests from the same IP in one minute |

## POST /api/v1/auth/reset-password

Consume the token from the reset email and set a new password. This endpoint does not require authentication.

- **Auth required:** No
- **Rate limit:** 5 requests per minute per IP address

### Request body

```json
{
    "email": "user@example.com",
    "token": "reset-token-from-email",
    "password": "newPassword123",
    "password_confirmation": "newPassword123"
}
```

### Success response — 200

```json
{
    "success": true,
    "message": "Password reset successfully.",
    "data": null
}
```

All existing Sanctum tokens for the account are revoked after a successful reset, so mobile clients must log in again. The reset token expires according to `config/auth.php` (currently 60 minutes) and cannot be reused.

### Error responses

| Status | Cause                                                                                       |
| ------ | ------------------------------------------------------------------------------------------- |
| 422    | Missing or invalid fields, weak password, mismatched confirmation, or invalid/expired token |
| 429    | More than 5 requests from the same IP in one minute                                         |

---

## POST /api/v1/auth/login

Authenticate with a phone number and password and receive a bearer token.

- **Auth required:** No
- **Headers:** `Accept: application/json`

### Request body

| Field         | Type   | Rules                                                    |
| ------------- | ------ | -------------------------------------------------------- |
| `phone`       | string | required, normalized to `+60XXXXXXXXX` before validation |
| `password`    | string | required                                                 |
| `device_name` | string | required, max 150 chars                                  |

```json
{
    "phone": "0123456789",
    "password": "password123",
    "device_name": "Android Phone"
}
```

Phone numbers are normalized through a shared `PhoneNumberNormalizer` service before lookup, so `0123456789`, `60123456789`, and `+60123456789` all resolve to the same account (`+60123456789`).

### Success response — 200

```json
{
    "success": true,
    "message": "Login successful.",
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "phone": "+60123456789",
            "email": null,
            "user_type": "student",
            "status": "active",
            "phone_verified_at": null,
            "last_login_at": "2026-09-12T10:00:00.000000Z"
        },
        "token": "1|abcdef...",
        "token_type": "Bearer"
    }
}
```

### Error responses

| Status | Cause                                                                                                                                    |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| 401    | Phone not found, wrong password, or account not `active` — always the generic message below, so a client cannot tell which case occurred |
| 422    | Missing/invalid `phone`, `password`, or `device_name`                                                                                    |

```json
{
    "success": false,
    "message": "Invalid phone number or password.",
    "errors": {}
}
```

### Business rules

- The user must exist, have a password set, and have `status = active`; otherwise the same generic 401 is returned for every case (no phone-number enumeration).
- `last_login_at` is updated on every successful login.
- The client-supplied `user_type` is never trusted; `user_type` always comes from the stored user record.

---

## POST /api/v1/auth/logout

Revoke the token used to authenticate the current request.

- **Auth required:** Yes (`auth:sanctum`)
- **Headers:** `Authorization: Bearer {token}`

### Success response — 200

```json
{
    "success": true,
    "message": "Logout successful.",
    "data": null
}
```

### Error responses

| Status | Cause                           |
| ------ | ------------------------------- |
| 401    | Missing or invalid bearer token |

### Business rules

- Only `currentAccessToken()` is deleted. Other devices' tokens remain valid.

---

## GET /api/v1/auth/me

Return the currently authenticated user.

- **Auth required:** Yes (`auth:sanctum`)
- **Headers:** `Authorization: Bearer {token}`

### Success response — 200

```json
{
    "success": true,
    "message": "Authenticated user retrieved successfully.",
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "phone": "+60123456789",
            "email": null,
            "user_type": "student",
            "status": "active",
            "phone_verified_at": null,
            "last_login_at": "2026-09-12T10:00:00.000000Z"
        }
    }
}
```

### Error responses

| Status | Cause                           |
| ------ | ------------------------------- |
| 401    | Missing or invalid bearer token |

The user is always resolved from the authenticated token (`$request->user()`); there is no way to request another user's profile through this endpoint.

---

## POST /api/v1/auth/change-password

Change the password for the authenticated user.

- **Auth required:** Yes (`auth:sanctum`)
- **Headers:** `Authorization: Bearer {token}`

### Request body

| Field                   | Type   | Rules                                                             |
| ----------------------- | ------ | ----------------------------------------------------------------- |
| `current_password`      | string | required                                                          |
| `password`              | string | required, confirmed, Laravel default password rules (min 8 chars) |
| `password_confirmation` | string | required, must match `password`                                   |

```json
{
    "current_password": "oldPassword123",
    "password": "newPassword123",
    "password_confirmation": "newPassword123"
}
```

### Success response — 200

```json
{
    "success": true,
    "message": "Password changed successfully.",
    "data": null
}
```

### Error responses

| Status | Cause                                                                           |
| ------ | ------------------------------------------------------------------------------- |
| 422    | `current_password` is incorrect (`"message": "Current password is incorrect."`) |
| 422    | Validation failure (missing fields, weak password, confirmation mismatch)       |
| 401    | Missing or invalid bearer token                                                 |

### Business rules

- The new password is hashed via Laravel's `hashed` cast before saving.
- All other Sanctum tokens belonging to the user are revoked; the token used to make this request remains valid.

---

## POST /api/v1/auth/set-password

Allow a user who does not yet have a usable password (e.g. created by an administrator) to establish their first password.

- **Auth required:** Yes (`auth:sanctum`)
- **Headers:** `Authorization: Bearer {token}`

### Request body

| Field                   | Type   | Rules                                                             |
| ----------------------- | ------ | ----------------------------------------------------------------- |
| `password`              | string | required, confirmed, Laravel default password rules (min 8 chars) |
| `password_confirmation` | string | required, must match `password`                                   |

### Success response — 200

```json
{
    "success": true,
    "message": "Password set successfully.",
    "data": null
}
```

### Error responses

| Status | Cause                                                                                                                                                                  |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 422    | The account already has a password set (`"message": "A password has already been set for this account. Use change-password instead."`) — use `change-password` instead |
| 422    | Validation failure                                                                                                                                                     |
| 401    | Missing or invalid bearer token                                                                                                                                        |

### Business rules

- Only usable when `users.password` is `NULL`. This is the intentional signal that an account has not completed initial password setup; the column is nullable specifically to support this flow.

---

## POST /api/v1/auth/refresh-token

Rotate the token used to authenticate the current request.

- **Auth required:** Yes (`auth:sanctum`)
- **Headers:** `Authorization: Bearer {token}`

### Success response — 200

```json
{
    "success": true,
    "message": "Token refreshed successfully.",
    "data": {
        "token": "2|ghijkl...",
        "token_type": "Bearer"
    }
}
```

### Error responses

| Status | Cause                           |
| ------ | ------------------------------- |
| 401    | Missing or invalid bearer token |

### Why this is a rotation, not a classic "refresh"

Laravel Sanctum personal access tokens are simple bearer tokens: they don't expire on a fixed schedule and there is no refresh-token/access-token pair like OAuth2 or JWT. There is nothing to "exchange" ahead of expiry.

To still provide a meaningful endpoint at this URL without faking a refresh flow, `refresh-token` performs **token rotation**: it requires the current valid token, deletes it, and issues a brand-new token with the same device name. This lets a client periodically rotate its credential (e.g. after a suspected leak, or as routine hygiene) without a full re-login. It is a real, working operation — just not "refresh" in the JWT sense.

---

## Phone number normalization

`App\Services\PhoneNumberNormalizer::normalize()` is a small, stateless, reusable helper (not tied to authentication) so any future registration or admin-user-creation flow can normalize phone numbers the same way:

| Input          | Normalized     |
| -------------- | -------------- |
| `0123456789`   | `+60123456789` |
| `60123456789`  | `+60123456789` |
| `+60123456789` | `+60123456789` |

It strips all non-digit characters, replaces a leading `0` with `60`, assumes a bare local number should be prefixed with `60`, and always returns the number with a leading `+`.

---

## Rate limiting

No rate limiting has been added in this phase. This Laravel version does not attach a default `throttle` middleware to the `api` group automatically, and none has been configured for these routes. If per-endpoint throttling (e.g. stricter limits on `/login`) is required, add `->middleware('throttle:login')` with a matching `RateLimiter::for('login', ...)` definition and document it here.
