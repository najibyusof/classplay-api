# Payment Webhook & Transaction Confirmation (Phase 14)

## Overview

The **Payment Webhook Service** processes external payment status notifications sent asynchronously by payment providers (such as merchant gateways, FPX, ToyyibPay, Billplz, or Stripe) to confirm payment transactions, update `Payment` and `PaymentSchedule` records, and trigger transactional side effects.

Webhooks do **not** use Sanctum user authentication because requests originate from payment provider servers. Instead, security relies on **provider signature authentication**, **payload validation**, **transaction lookup**, **amount & currency verification**, **state transition checks**, and **replay/duplicate protection**.

---

## Webhook Endpoints

| Method | Path                                  | Auth               | Purpose                                 |
| ------ | ------------------------------------- | ------------------ | --------------------------------------- |
| `POST` | `/api/v1/payment/webhook/{provider}`  | Signature / Secret | Primary Phase 14 webhook endpoint       |
| `POST` | `/api/v1/webhooks/payments/{gateway}` | Signature / Secret | Legacy alias for backward compatibility |

- `{provider}` / `{gateway}` must map to a known, configured payment provider (e.g. `merchant`, `stripe`, `toyyibpay`, `fpx`, `duitnow`).
- Unknown providers return `404 Not Found`.
- Unauthenticated requests (missing or invalid signature) return `403 Forbidden`.

---

## Architecture

```
Payment Provider (HTTP POST)
         │
         ▼
PaymentWebhookController
         │
         ▼
PaymentWebhookService (processWebhook)
    ├── WebhookVerifierInterface (verifySignature)
    │       └── PaymentWebhookVerifier
    ├── DB::transaction + lockForUpdate()
    ├── Amount & Currency Verification
    ├── State Machine Transition Rules
    ├── Payment & PaymentSchedule Atomic Update
    └── Notification & Audit Logging
```

- **`App\Contracts\Payment\WebhookVerifierInterface`**: Interface defining `verifySignature(Request $request, string $provider): bool`.
- **`App\Services\Payment\Verifiers\PaymentWebhookVerifier`**: Verifies HMAC-SHA256 signatures (`X-Webhook-Signature` / `X-Signature`) or shared secret headers (`X-Webhook-Secret`) against `config("payment.gateways.{$provider}.secret")` or `config('services.payment_webhook.secret')`.
- **`App\Services\Payment\PaymentWebhookService`**: Thin-controller service encapsulating the full validation, state transition, and DB transaction logic.
- **`App\Services\Payment\PaymentReconciliationService`**: Extension point for future manual or batch transaction reconciliation.

---

## Step-by-Step Processing Flow

1. **Provider Resolution**: Validates that `{provider}` is a known gateway. If unknown, returns `404 Not Found`.
2. **Signature Verification**: Verifies `X-Webhook-Signature` (HMAC SHA-256) or `X-Webhook-Secret` via constant-time string comparison (`hash_equals`). Returns `403 Forbidden` if invalid.
3. **Payload Extraction & Validation**: Extracts `gateway_reference` / `transaction_reference` and `status` (`paid`, `success`, `completed`, `failed`). Returns `422 Unprocessable Entity` if required fields are missing.
4. **Transaction Lookup**: Queries `PaymentTransaction` matching `gateway_reference` or `transaction_reference`. Returns `404 Not Found` if missing.
5. **Database Transaction & Row Locking**: Wraps execution in `DB::transaction()` and acquires pessimistic row locks (`lockForUpdate()`) on `PaymentTransaction`, `Payment`, and `PaymentSchedule` records.
6. **Idempotency & Replay Protection**:
    - Checks if `event_id` was already processed for the transaction. If so, returns `200 OK` ("Webhook event already processed.").
    - Checks if `payment.status === 'paid'` and incoming status is `paid`. If so, returns `200 OK` ("Payment already confirmed.") without repeating database updates or re-dispatching side effects.
7. **State Machine Validation**:
    - Allowed transitions to `paid`: `initiated`, `pending`, `processing`, or `failed` (successful retry).
    - Allowed transitions to `failed`: `initiated`, `pending`, `processing`.
    - Disallowed transitions (e.g. attempting to un-pay a `paid` payment): Returns `422 Unprocessable Entity`.
8. **Amount Verification**:
    - Compares provider `amount` against `$payment->total_amount` using decimal-safe floating-point comparison.
    - **Amount Mismatch Penalty**: If the provider reports an amount that does not match `total_amount`, the payment is marked `status = 'failed'`, transaction updated with `response_code = 'amount_mismatch'`, and returns `422 Unprocessable Entity`.
9. **Currency Verification**:
    - Compares provider `currency` against `$payment->currency` (default `MYR`). Rejects mismatches with `422 Unprocessable Entity`.
10. **Atomic Database Execution**:
    - Updates `PaymentTransaction`: `response_status`, `response_code`, `response_message`, sanitized `response_payload`, and `completed_at`.
    - Updates `Payment`: `status = 'paid'` (or `'failed'`), `paid_at = now()`, `verified_at = now()`.
    - Updates `PaymentSchedule`: Recalculates sum of all `paid` payments for the schedule. If `sum('total_amount') >= required_amount`, sets `schedule.status = 'paid'`.
11. **Sanitization & Logging**:
    - Scrubs sensitive keys (`api_key`, `secret`, `password`, `token`, `authorization`) before saving `response_payload` or writing to system logs.
12. **Notifications**:
    - Creates a `Notification` record and dispatches `SendNotificationJob`.

---

## Partial Payments

A `PaymentSchedule` represents the required total obligation (e.g. `RM100.00`). If multiple payment attempts are made (e.g. Payment 1 = RM40.00, Payment 2 = RM60.00):

- Payment 1 webhook (`paid` for RM40.00) -> `Payment 1.status = 'paid'`, `PaymentSchedule.status` remains `'pending'` (40 < 100).
- Payment 2 webhook (`paid` for RM60.00) -> `Payment 2.status = 'paid'`, total paid = RM100.00 >= RM100.00 -> `PaymentSchedule.status` becomes `'paid'`.

---

## Transaction Atomicity

The entire operation (updating `PaymentTransaction`, `Payment`, and `PaymentSchedule`) is wrapped in a single database transaction. If updating `PaymentSchedule` fails, both `Payment` and `PaymentTransaction` changes roll back completely, ensuring no partial state discrepancies occur.

---

## Future Reconciliation Extension Point

`App\Services\Payment\PaymentReconciliationService` is provided as an extension point for comparing provider settlement files / API reports against ClassPay records:

- `reconcileTransaction(PaymentTransaction $transaction)`: Checks matching statuses and amounts between transactions and payments.
- `reconcileSchedule(Payment $payment)`: Checks total accumulated paid payments against schedule obligations.

---

## Testing Strategy

All tests use fake webhook requests and mock signatures (no real payment provider endpoints called):

- **Feature Tests**: `tests/Feature/Api/PaymentWebhookTest.php` (21 test cases)
    - Valid successful webhook execution
    - Missing or invalid signature checks (`403`)
    - Unknown provider & unknown transaction handling (`404`)
    - Invalid payload validation (`422`)
    - Amount mismatch rejection (`422`, marks payment failed)
    - Currency mismatch rejection (`422`)
    - Idempotency & duplicate webhook safety
    - Replay protection with `event_id`
    - Failed payment state transitions
    - Invalid state transition rejection
    - Sensitive credential scrubbing from payloads/logs
    - Partial payment schedule accumulation
    - DB transaction atomicity verification
