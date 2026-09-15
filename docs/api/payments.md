# Payment API

## Payment Schedule vs. Payment

A **Payment Schedule** (Phase 11) is an obligation: "this participant owes this amount for this period." A **Payment** is an actual _attempt_ to settle that obligation. One schedule can have many payment attempts (e.g. a failed manual transfer followed by a successful QR payment) — a schedule is never assumed to have exactly one payment row.

```
Organization → Class → Class Participant → Payment Schedule → Payment → Payment Transaction
```

This phase adds the **self-service** (mobile client) endpoints on top of the existing admin `PaymentScheduleController`/`PaymentController` (unchanged for admin use). No payment gateway, webhook, or provider integration is implemented here — that is Phase 13.

## Endpoints

Every endpoint requires `Authorization: Bearer {token}` (`auth:sanctum`) and exists identically under both `/api/v1/student/...` and `/api/v1/sponsor/...` (same controllers — a sponsor's access is additionally scoped to students they actively sponsor via `sponsor_students`).

| Method | Path                                                        | Purpose                                                  |
| ------ | ----------------------------------------------------------- | -------------------------------------------------------- |
| GET    | `/{student\|sponsor}/organizations`                         | Organizations of classes the user participates in        |
| GET    | `/{student\|sponsor}/classes`                              | Classes the user actively participates in                |
| GET    | `/{student\|sponsor}/payment-schedules`                     | List own (or sponsored students') payment schedules      |
| GET    | `/{student\|sponsor}/payment-schedules/current`             | The single most relevant unpaid/pending/overdue schedule |
| GET    | `/{student\|sponsor}/payment-schedules/{schedule}`          | View one schedule                                        |
| POST   | `/{student\|sponsor}/payment-schedules/{schedule}/payments` | Create a payment attempt against a schedule              |
| GET    | `/{student\|sponsor}/payments`                              | Payment history (own payments only)                      |
| GET    | `/{student\|sponsor}/payments/{payment}`                    | View one payment                                         |

### Query parameters

- `payment-schedules`: `status`, `class_id`, `per_page` (max 100)
- `payments`: `status`, `payment_method`, `class_id`, `date_from`, `date_to`, `per_page` (max 100)

## Authorization & ownership

No endpoint trusts a client-supplied `user_id`/`payer_id`/`participant_id` — every check re-derives ownership from `$request->user()` and the database:

- **Student:** may only see/pay a schedule where `PaymentSchedule.classParticipant.user_id === $request->user()->id`.
- **Sponsor:** may additionally see/pay a schedule if `sponsor_students` has an **active** row linking them (`sponsor_id`) to that schedule's student (`student_id`). Added this phase via `User::isSponsorOf()` and used in `PaymentPolicy`/`PaymentSchedulePolicy`.
- Admins retain their existing organization-scoped access (`payment.view`/`payment.update` + `isOrganizationAdmin`), unchanged.

A schedule/payment that exists but isn't owned by the requester returns `403` (not `404`) — consistent with every other resource in this API, since `404` here would only be warranted to hide existence entirely, which isn't this project's convention for authenticated-but-unauthorized access.

## Payment creation

`POST /{student|sponsor}/payment-schedules/{schedule}/payments`

```json
{ "additional_infaq": 20, "payment_method": "merchant" }
```

The client sends **only** `additional_infaq` and `payment_method`. Everything else is computed server-side by `PaymentService::create()`:

```
required_amount = payment_schedule.required_amount   (never recalculated from the class setting)
additional_infaq = validated request input
total_amount     = required_amount + additional_infaq
payer_id         = $request->user()->id               (never trusted from the client)
```

`required_amount`, `total_amount`, `payer_id`, `status`, `paid_at`, and `verified_at` are rejected outright if present in the request body — `StorePaymentRequest`'s `rules()` doesn't declare them, so Laravel's validated-data array never contains them even if a client sends them (verified by test: `test_amounts_are_calculated_server_side_and_client_cannot_override`).

### Additional Infaq rules

Read from `class_payment_settings` for the schedule's class (`ClassPaymentSetting.allow_additional_infaq`/`minimum_infaq`/`maximum_infaq`), enforced in `StorePaymentRequest::withValidator()`:

- `allow_additional_infaq = false` → any `additional_infaq > 0` is rejected (`422`).
- `minimum_infaq` set → `additional_infaq` below it is rejected (only checked when `additional_infaq > 0`, so a plain required-amount-only payment is always allowed).
- `maximum_infaq` set → `additional_infaq` above it is rejected.
- All amounts use Laravel's `decimal:2` cast (backed by `DECIMAL(12,2)` columns) — no floating-point arithmetic.

### Payment status on creation

A newly created payment is **never** `paid`. `PaymentService` assigns:

- `pending` for `manual`, `bank_transfer`, and `qr` (awaiting manual proof review / manual bank transfer confirmation / QR-based transfer confirmation)
- `initiated` for `merchant` (a gateway initiation was attempted; see [payment-gateway.md](../payment/payment-gateway.md) for how success/failure is handled)

### Payment transaction placeholder

A `payment_transactions` row is created alongside the payment with `request_amount = total_amount` and `response_status = 'pending'`. For `manual`/`bank_transfer`/`qr`, `gateway_name` is set to the payment method itself and no external call is made. For `merchant`, see [payment-gateway.md](../payment/payment-gateway.md) — the real gateway is called and this row is updated in place with the provider's response.

## Concurrency & duplicate settlement protection

`PaymentService::create()` runs inside `DB::transaction()` and re-fetches the schedule with `lockForUpdate()` before checking whether it's already settled:

```php
$schedule = PaymentSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

if ($schedule->payments()->where('status', 'paid')->exists()) {
    throw new RuntimeException('This payment schedule has already been paid.');
}
```

This prevents two near-simultaneous requests from both succeeding once one payment has been marked `paid` — the row lock serializes the check-then-create sequence per schedule. Creating multiple **non-paid** attempts (e.g. a failed manual transfer followed by a successful QR attempt) is explicitly allowed; only settling an already-`paid` schedule again is blocked.

## Resources

- `PaymentResource` (reused) — `id`, `payment_schedule_id`, `payer`, `required_amount`, `additional_infaq`, `total_amount`, `currency`, `status`, `payment_method`, `paid_at`, `verified_at`, `verified_by`, `reference_number`, `notes`. Never includes `password` or Sanctum tokens (enforced by `UserResource`'s own field list, used for the nested `payer`).
- `PaymentScheduleResource` (enriched this phase) — adds `class` (`id`, `name`), `payment_status` (derived from the most recent payment attempt, when loaded), and `payment_options` (currency, infaq rules, bank/QR/merchant details from `class_payment_settings`) alongside the existing `period_start`/`period_end`/`due_date`/`required_amount`/`status` fields. Never includes other participants.

## Response format

```json
{
    "success": true,
    "message": "Payment initiated successfully.",
    "data": { "payment": { "...": "..." } }
}
```

List endpoints wrap results as `data.payment_schedules`/`data.payments` alongside a `data.pagination` block (`current_page`, `per_page`, `total`, `last_page`). Validation failures return `422` with `{"success": false, "message": "...", "errors": {...}}`; unauthenticated requests return `401`; unauthorized (valid token, wrong owner) requests return `403`.

## Security summary

Never trusted from the client: `payer_id`, `required_amount`, `total_amount`, `status`, `paid_at`, `verified_at`, or which schedule/participant a payment belongs to — all resolved from `$request->user()` and the route-bound `PaymentSchedule`. See `tests/Feature/Api/PaymentApiTest.php` for the full authorization/ownership/amount-override/concurrency test matrix.
