# Payment Gateway / QR / Merchant Integration (Phase 13)

## Scope

This phase adds a **gateway abstraction** for the `merchant` payment method, and a **display-only** flow for the `qr` payment method. It does **not** implement webhook confirmation (a `PaymentWebhookController` already existed in this codebase from earlier ad-hoc work, before strict phasing began — it is untouched by this phase and remains responsible for consuming the fields this phase populates).

No specific real-world provider (Stripe, ToyyibPay, Billplz, iPay88, FPX, DuitNow, ...) has been chosen — none is referenced anywhere in the codebase or `composer.json`, so a **generic, configurable adapter** was built instead.

## Architecture

```
PaymentController
    └── PaymentService (create() / initiate())
            └── PaymentGatewayInterface   (bound in AppServiceProvider)
                    └── MerchantPaymentGateway   (default implementation)
```

- `App\Services\Payment\Gateways\PaymentGatewayInterface` — the contract. `name(): string` and `initiatePayment(Payment $payment): GatewayInitiationResult`.
- `App\Services\Payment\Gateways\GatewayInitiationResult` — an immutable, safe (no secrets) DTO: `successful`, `transactionReference`, `gatewayReference`, `paymentUrl`, `responseStatus`, `responseCode`, `responseMessage`.
- `App\Services\Payment\Gateways\MerchantPaymentGateway` — the only concrete implementation. POSTs to a configured URL and expects a JSON body containing `payment_url` (and optionally `reference`). If no URL is configured, it returns an honest `gateway_not_configured` failure rather than pretending success.
- `App\Providers\AppServiceProvider::register()` binds `PaymentGatewayInterface` to `MerchantPaymentGateway`, constructed from `config('payment.gateways.{default}')`. Adding a real provider later only requires a new class implementing the interface and a one-line change to this binding (or a small factory keyed on `config('payment.default')`, if multiple providers are added at once).

## Configuration

`config/payment.php`:

```php
return [
    'default' => env('PAYMENT_GATEWAY', 'merchant'),
    'gateways' => [
        'merchant' => [
            'merchant_id' => env('PAYMENT_GATEWAY_MERCHANT_ID'),
            'api_key' => env('PAYMENT_GATEWAY_API_KEY'),
            'secret' => env('PAYMENT_GATEWAY_SECRET'),
            'url' => env('PAYMENT_GATEWAY_URL'),
            'timeout' => (int) env('PAYMENT_GATEWAY_TIMEOUT', 10),
        ],
    ],
];
```

Environment variables (added to `.env` and `.env.example`, all empty by default):

| Variable                      | Purpose                                                                |
| ----------------------------- | ---------------------------------------------------------------------- |
| `PAYMENT_GATEWAY`             | Which gateway config to use (currently only `merchant`)                |
| `PAYMENT_GATEWAY_MERCHANT_ID` | Provider-assigned merchant identifier                                  |
| `PAYMENT_GATEWAY_API_KEY`     | Sent as a bearer token to the provider                                 |
| `PAYMENT_GATEWAY_SECRET`      | Used to HMAC-sign the outgoing request; never sent in the request body |
| `PAYMENT_GATEWAY_URL`         | Provider endpoint. Left empty until a real provider is chosen          |
| `PAYMENT_GATEWAY_TIMEOUT`     | HTTP timeout in seconds (default 10)                                   |

No credential is ever returned in an API response or included in a log line.

## Merchant payment flow

`POST /{student|sponsor}/payment-schedules/{schedule}/payments` with `payment_method = "merchant"`:

1. `PaymentService::create()` creates the `Payment` (status `initiated`) exactly as before, using the schedule's authoritative `required_amount` — never a client-supplied amount.
2. A `payment_transactions` placeholder row is created (`gateway_name = 'merchant'`, `response_status = 'pending'`).
3. `PaymentGatewayInterface::initiatePayment($payment)` is called, passing `$payment->total_amount` (never trusting the client).
4. The transaction row is updated in place with the gateway's response (`transaction_reference`, `gateway_reference`, `response_status`, `response_code`, `response_message`, and — sanitized, secret-free — the `payment_url` in `response_payload`).
5. On failure (rejected, timed out, or an invalid/unexpected response), the `Payment` is marked `status = 'failed'` with a safe `notes` message. It is **never** marked `paid` during initiation — only a later webhook/verification step (out of scope here) can do that.

Response shape (merchant, success):

```json
{
    "success": true,
    "message": "Payment initiated successfully.",
    "data": {
        "payment": { "...": "..." },
        "gateway": {
            "name": "merchant",
            "payment_url": "https://provider.example/pay/...",
            "transaction_reference": "MER-..."
        }
    }
}
```

Response shape (merchant, failure — still HTTP 201, since the `Payment`/`PaymentTransaction` rows were created; `success` reflects the gateway outcome via `message`, and `data.payment.status` is `"failed"`):

```json
{
    "success": true,
    "message": "Payment gateway initiation failed.",
    "data": {
        "payment": { "status": "failed", "...": "..." },
        "gateway": {
            "name": "merchant",
            "payment_url": null,
            "transaction_reference": "MER-..."
        }
    }
}
```

### Retrying / explicitly re-initiating

`POST /{student|sponsor}/payments/{payment}/initiate` — for when a client wants to retry after a transient failure. `PaymentService::initiate()`:

- Requires `payment_method === 'merchant'` (otherwise `422`).
- Requires ownership (`PaymentPolicy::view`, same rule as viewing the payment) — a user cannot initiate another user's payment (`403`).
- If an existing transaction for this payment already has a `gateway_reference` and isn't `failed`, it is **reused** — no duplicate transaction row is created.
- Otherwise, behaves like the initiation step above.

## QR payment flow

`payment_method = "qr"` is intentionally **not** a gateway call — it is a **read/display** flow. The response surfaces the class's configured bank/QR details (`ClassPaymentSetting.bank_name`/`bank_account_name`/`bank_account_number`/`qr_code_path`) so the mobile client can render a QR code or bank details for a manual transfer:

```json
{
    "data": {
        "payment": { "status": "pending", "...": "..." },
        "qr": {
            "bank": {
                "name": "...",
                "account_name": "...",
                "account_number": "..."
            },
            "qr_code_path": "qr-codes/....png"
        }
    }
}
```

No automatic verification is implied or claimed — the payment stays `pending` until manually reviewed (existing `payment.update`/`payment.verify` flow), exactly like `manual`/`bank_transfer`.

## Error handling & security

- HTTP calls to the provider use a configured `timeout` and catch `Illuminate\Http\Client\ConnectionException` — a timeout never bubbles up as an uncaught exception (which would return a `500`).
- A non-2xx response, or a 2xx response missing `payment_url`, is treated as a failure with a safe, generic message (`response_message`) — the raw provider error body is never stored or returned.
- Credentials (`api_key`, `secret`) are only ever used to build outgoing request headers; they are never written to `payment_transactions.response_payload`, never included in any API response, and never logged.
- No blind retries are performed for financial operations — retrying is an explicit, authorized, idempotent client action (`initiate()`), not automatic.

## Testing

- `tests/Support/FakePaymentGateway.php` — a deterministic `PaymentGatewayInterface` test double (`success`/`failure`/`timeout`/`invalid_response` modes), bound via `$this->app->instance(PaymentGatewayInterface::class, ...)` in tests. No real HTTP call is ever made in the test suite.
- `tests/Feature/Api/PaymentGatewayApiTest.php` covers: interface resolution, default gateway binding, authoritative amount passed to the gateway, successful `payment_url`/transaction creation, transaction reference persistence, gateway failure/timeout/invalid-response handling, idempotent re-initiation (no duplicate transaction), the QR display-only flow, secrets never returned or logged, payment never marked `paid` during initiation, unauthorized initiation rejected, and the real `MerchantPaymentGateway`'s "not configured" safe-failure path.

## Adding a real provider later

1. Add a new class implementing `PaymentGatewayInterface` (e.g. `ToyyibpayGateway`).
2. Add its config block under `config('payment.gateways.*')` and corresponding `.env` variables.
3. Update the `AppServiceProvider` binding (or introduce a small factory keyed on `config('payment.default')`) to resolve the new class.
4. No changes to `PaymentService`, routes, or resources are needed — they only depend on the interface.
