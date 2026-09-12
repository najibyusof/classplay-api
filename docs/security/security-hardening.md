# ClassPay Security Hardening Specification (Phase 23)

## Overview

This document details the security posture, controls, and hardening measures implemented across the ClassPay Laravel REST API backend.

---

## 1. Authentication

- **Token Engine**: Powered by Laravel Sanctum 4.0 using bearer token authentication (`Authorization: Bearer {token}`).
- **Password Hashing**: Enforced using BCrypt with configurable rounds.
- **Account Enumeration Shielding**: Login failures in `AuthenticationService` throw `AuthenticationFailedException`, which renders a generic `"Invalid phone number or password."` response (401 Unauthorized), preventing attackers from guessing valid phone numbers or account existence.
- **Account Inactivation**: Inactive users (`status !== 'active'`) are blocked at the authentication layer before token issuance.
- **Token Lifecycle**:
    - Logout explicitly revokes and deletes the current Sanctum token (`$token->delete()`).
    - Password changes immediately revoke all active Sanctum tokens except the current session token (`$user->tokens()->where('id', '!=', $currentTokenId)->delete()`).

---

## 2. Authorization & IDOR Prevention

- **Policy-Based Control**: All protected routes enforce policy checks using Laravel Policies (`OrganizationPolicy`, `ClassModelPolicy`, `PaymentSchedulePolicy`, `PaymentPolicy`, `NotificationPolicy`, `UserDevicePolicy`, `AuditLogPolicy`, `RolePolicy`, `PermissionPolicy`).
- **Organization Boundaries**:
    - Organization admins can only view, update, or report on data belonging to organizations they actively administer (`organization_admins.status = 'active'`).
    - Cross-organization data leakage is prevented at both policy and Eloquent query levels (`isOrganizationAdmin($organizationId)`).
- **Participant Isolation**:
    - Students can only view their own payment schedules, payment attempts, and notifications (`user_id === $request->user()->id`).
    - Sponsors can only access payment schedules of students they actively sponsor (`isSponsorOf($studentId)`).
- **IDOR Protection**: Any attempt by User A to read, modify, or delete User B's resources returns `403 Forbidden`.

---

## 3. Mass Assignment Protection

- **Model Safeguards**: All Eloquent models use explicit `$fillable` attribute lists. Sensitive fields (`user_type`, `status`, `created_by`, `verified_by`, `paid_at`, `verified_at`, `organization_id`) are excluded from user-controllable input arrays.
- **Form Request Validation**: Controller actions process validated data through Form Requests (`StoreStudentRequest`, `StoreSponsorRequest`, `StorePaymentRequest`, `StoreOrganizationAdminRequest`).
- **Role Sync Trigger**: User roles are synced automatically via Eloquent's `booted()` model event when `user_type` is changed by an authorized service, keeping RBAC permissions aligned without allowing client injection.

---

## 4. Payment Security

- **Authoritative Server Calculations**:
    - Payer ID is derived from `$request->user()->id`. Client input for `payer_id` is ignored.
    - Required amount and total amount (`required_amount + additional_infaq`) are computed server-side by `PaymentService::create()`. Client attempts to override amounts or currency are rejected.
    - Initial payment status is set to `pending` (for manual/bank_transfer/qr) or `initiated` (for merchant). Clients cannot set `status = 'paid'` or supply `paid_at`/`verified_at`.
- **Concurrency & Lock Safety**:
    - `PaymentService` uses database row locks (`lockForUpdate()`) inside atomic DB transactions to prevent race conditions and duplicate settlements.

---

## 5. Webhook Security

- **Provider Signature Verification**: Incoming payment webhooks (`/api/v1/payment/webhook/{provider}`) verify constant-time HMAC-SHA256 signatures (`X-Webhook-Signature` / `X-Signature`) or secret headers (`X-Webhook-Secret`) via `PaymentWebhookVerifier`. Requests with invalid signatures return `403 Forbidden`.
- **Amount & Currency Verification**: `PaymentWebhookService` verifies that the provider's reported payment amount matches `$payment->total_amount` and currency matches `$payment->currency`. Mismatches cause immediate rejection (422) and set `status = 'failed'`.
- **State Machine Safeguards**: State transitions are strictly controlled (e.g. `paid` payments cannot be reset to `failed` via webhook).
- **Idempotency & Replay Protection**: Duplicate webhooks or event IDs return `200 OK` ("Payment already confirmed.") without repeating database updates or side effects.

---

## 6. File Upload Security

- **File Validation**: Payment proofs (`StorePaymentProofRequest`) and QR codes (`StoreClassPaymentSettingQrCodeRequest`) enforce strict MIME type rules (`mimes:jpg,jpeg,png,webp`), `image` rule, and file size limits (max 5MB for proofs, 2MB for QR codes). Executable extensions (`.php`, `.exe`, `.sh`) are rejected (422).
- **Filename Hardening**: Uploaded files are saved using Laravel Storage disk hashing (`store('payment-proofs', 'public')`), generating randomized string filenames. Client-supplied original filenames are never used as storage keys on disk, preventing directory traversal and file overwrite vulnerabilities.

---

## 7. Rate Limiting & Throttling

- **Login Throttling**: The `/api/v1/auth/login` endpoint is rate limited to 5 attempts per minute per phone/IP pair (`throttle:login`).
- **Password Operations**: Sensitive password endpoints (`/change-password`, `/set-password`) are rate limited to 6 requests per minute (`throttle:6,1`).
- **Global API Throttling**: Standard API routes use token bucket rate limiting (60 requests/min per user/IP).
- **Standard 429 Envelope**: Rate limit exceptions return HTTP 429 with the standard ClassPay JSON error envelope: `{"success": false, "message": "Too many requests. Please try again later.", "errors": {}}`.

---

## 8. Secrets & Environment Configuration

- **Zero Hardcoded Credentials**: Source code contains no embedded API keys, passwords, bearer tokens, or private keys.
- **Config Abstraction**: Secrets (`PAYMENT_GATEWAY_SECRET`, `PAYMENT_WEBHOOK_SECRET`, `FCM_SERVER_KEY`, `TELEGRAM_BOT_TOKEN`, `DB_PASSWORD`, `APP_KEY`) are read exclusively from `.env` via `config/*.php` files.
- **Ignored `.env`**: Environment files are listed in `.gitignore` to prevent committing secrets to source control.

---

## 9. Logging Hygiene

- **Sensitive Data Scrubbing**:
    - `PaymentWebhookService` and `FcmPushNotificationService` automatically scrub sensitive keys (`api_key`, `secret`, `password`, `token`, `authorization`, `credit_card`) from payload arrays before writing to `payment_transactions.response_payload` or system logs.
    - Logging statements log only safe operational metadata (`provider`, `transaction_reference`, `status`).

---

## 10. CORS & Exception Shielding

- **CORS Configuration**: Configured in `config/cors.php`, reading allowed origins from `CORS_ALLOWED_ORIGINS` environment variable.
- **Production Exception Shielding**: When `app.debug = false`, unhandled exceptions (`500 Server Error`) return a sanitized response `{"success": false, "message": "Server error.", "errors": {}}`, concealing stack traces, raw SQL queries, environment variables, and internal file paths.

---

## 11. Testing Matrix

Security controls are verified by `tests/Feature/Security/SecurityHardeningTest.php` (10 test cases) alongside the full 316-test suite:

1. Admin cross-organization IDOR isolation (`403`).
2. Student-to-student data isolation (`403`).
3. Sponsor-to-unrelated-student isolation (`403`).
4. Student/sponsor privilege escalation blocking (`403`).
5. Mass assignment field injection protection.
6. Payment amount and status tampering protection.
7. Webhook signature spoofing rejection (`403`).
8. Malicious / executable file upload rejection (`422`).
9. Revoked / invalid Sanctum token rejection (`401`).
10. Device manipulation authorization check (`403`).
