# Push Notifications & Mobile Device Management (Phase 16)

## Overview

Phase 16 introduces **Mobile Device Registration** and **Push Notification Delivery** for the ClassPay Flutter mobile client via Firebase Cloud Messaging (FCM).

The design uses a clean service abstraction (`PushNotificationServiceInterface`), allowing the push delivery engine to be swapped or mocked in tests without coupling backend domain services directly to vendor SDKs.

---

## Architecture

```
Mobile App (Flutter)
       │ (POST /api/v1/devices)
       ▼
UserDeviceController
       │
       ▼
UserDevice Model (status: active/inactive)

────────────────────────────────────────────

Notification / Event
       │
       ▼
NotificationDispatcher
       │
       ▼
PushNotificationServiceInterface (bound to FcmPushNotificationService)
       │
       ├── Filters active devices (`status = 'active'`)
       ├── Creates `notification_logs` entry (channel: `push`)
       ├── Sanitizes push payload & adds Flutter deep-link contract
       └── POST https://fcm.googleapis.com/fcm/send
               │
               ├── Success (200 OK): updates log -> `status = 'sent'`, saves `provider_message_id`
               └── Invalid Token (400/404/NotRegistered): deactivates device -> `status = 'inactive'`
```

---

## Device Management API

All endpoints require `Authorization: Bearer {token}` (`auth:sanctum`).

### 1. Register / Update Device

`POST /api/v1/devices`

Registers a new device token or updates an existing token if already registered.

#### Request Body

```json
{
    "device_token": "fcm_token_string_here...",
    "platform": "android",
    "device_name": "Samsung Galaxy S23",
    "app_version": "1.0.0"
}
```

#### Validation Rules

- `device_token`: `required|string|max:512`
- `platform`: `required|in:android,ios`
- `device_name`: `nullable|string|max:150`
- `app_version`: `nullable|string|max:50`

#### Token Deduplication Strategy

If a device token is re-registered (e.g. user re-installs app or switches accounts on the same phone), the system updates the existing `user_devices` record with the new `user_id`, platform, app version, sets `status = 'active'`, and updates `last_seen_at = now()`, preventing duplicate active token records.

---

### 2. List User Devices

`GET /api/v1/devices`

Returns all devices registered to the authenticated user.

#### Response Format

```json
{
    "success": true,
    "message": "User devices retrieved successfully.",
    "data": {
        "devices": [
            {
                "id": 1,
                "device_token": "fcm_toke...here...",
                "platform": "android",
                "device_name": "Samsung Galaxy S23",
                "app_version": "1.0.0",
                "last_seen_at": "2026-09-12T12:00:00.000000Z",
                "status": "active",
                "created_at": "2026-09-12T10:00:00.000000Z"
            }
        ]
    }
}
```

_Note: Device tokens are automatically masked in API resources (`UserDeviceResource`) for security._

---

### 3. Deactivate / Remove Device

`DELETE /api/v1/devices/{device}`

Deactivates a registered device belonging to the authenticated user (`status = 'inactive'`). Attempting to delete another user's device returns `403 Forbidden`.

#### Response Format

```json
{
    "success": true,
    "message": "Device deactivated successfully.",
    "data": null
}
```

---

## Push Notification Service Abstraction

### Interface Contract

`App\Contracts\Notification\PushNotificationServiceInterface`:

- `sendToDevice(UserDevice $device, Notification $notification): bool`
- `sendToUser(User $user, Notification $notification): array`

### Container Binding

In `AppServiceProvider`:

```php
$this->app->bind(PushNotificationServiceInterface::class, FcmPushNotificationService::class);
```

---

## Configuration & Environment

Configuration lives in `config/notifications.php` and `config/services.php`:

```php
return [
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'api_url' => env('FCM_API_URL', 'https://fcm.googleapis.com/fcm/send'),
        'timeout' => (int) env('FCM_TIMEOUT', 10),
    ],
];
```

Environment variable required in `.env`:

```env
FCM_SERVER_KEY=your_firebase_server_key_here
```

_Never commit Firebase private server keys to source control._

---

## Flutter Deep-Link Payload Contract

FCM push messages sent to mobile devices include a structured JSON payload for deep-linking in Flutter:

```json
{
    "to": "device_fcm_token...",
    "notification": {
        "title": "Payment Reminder for Form 5 Physics",
        "body": "Dear student, your payment of RM75.00 is due on 2026-10-15."
    },
    "data": {
        "type": "payment.reminder",
        "notification_id": "45",
        "related_type": "App\\Models\\PaymentSchedule",
        "related_id": "123",
        "payment_schedule_id": "123",
        "route": "/payment-schedules/123",
        "action": "open_payment"
    }
}
```

### Deep-Link Navigation Contract

- `route`: Target Flutter client route (e.g. `/payment-schedules/123`).
- `action`: Client action string (e.g. `open_payment`).
- `payment_schedule_id`: ID of the referenced schedule.

### Security Payload Safeguards

Sensitive fields (`password`, `token`, `secret`, `api_key`, `credit_card`) are automatically stripped from notification data payloads before transmission.

---

## Delivery Lifecycle & Invalid Token Handling

1. **Delivery Target**: Scoped to devices where `user_id = $notification->user_id` AND `status = 'active'`. Inactive devices are automatically skipped.
2. **Delivery Log**: Every attempt creates a `notification_logs` record with `channel = 'push'`.
3. **Invalid Token Handling**:
    - If FCM responds with status code `400` / `404` or error string `NotRegistered` / `InvalidRegistration` / `MismatchSenderId`, the system automatically marks `UserDevice.status = 'inactive'`.
    - Subsequent push attempts to that device will be skipped. Invalid tokens are not retried.

---

## Automated Testing Strategy

Automated tests do **not** make live HTTP requests to FCM:

- **Test Double**: `Tests\Support\FakePushNotificationService` is bound to `PushNotificationServiceInterface::class` in feature tests.
- **HTTP Mocking**: `Http::fake(['fcm.googleapis.com/*' => ...])` verifies raw FCM HTTP payload formatting and response handling.
- **Test Matrix**: `tests/Feature/Api/DevicePushNotificationTest.php` covers all 15 specified scenarios plus edge cases.
