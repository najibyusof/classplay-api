# Payment Reminder Engine (Phase 17)

## Overview

The **Payment Reminder Engine** is responsible for evaluating payment schedule due dates against class reminder rules (`ClassPaymentSetting`), determining reminder eligibility, calculating outstanding balances, preventing duplicate notifications, and creating structured notification records for upcoming and overdue payment obligations.

---

## Reminder Rules & Configuration

Reminder configuration is read from `class_payment_settings` for each class:

| Column                 | Type      | Default | Purpose                                               |
| ---------------------- | --------- | ------- | ----------------------------------------------------- |
| `reminder_enabled`     | `boolean` | `true`  | Enables or disables reminders for the class           |
| `reminder_days_before` | `integer` | `3`     | Days before due date to generate an upcoming reminder |
| `reminder_days_after`  | `integer` | `3`     | Days after due date to generate an overdue reminder   |

---

## Architecture

```
PaymentSchedule & ClassPaymentSetting
                 │
                 ▼
      PaymentReminderService
       ├── isEligibleForReminder()
       │     ├── Schedule status not paid/cancelled
       │     ├── Class & participant status active
       │     ├── Outstanding balance > 0
       │     └── Deduplication check (isReminderAlreadySent)
       ├── calculateOutstandingBalance() (required_amount - paid_amount)
       └── createNotification() -> SendNotificationJob
```

- **`App\Services\Reminder\PaymentReminderService`**: Core domain service encapsulating reminder eligibility evaluation, balance calculation, deduplication logic, upcoming/overdue batch processing, admin manual triggers, and admin preview summaries.
- **`App\Policies\PaymentSchedulePolicy::sendReminder`**: Authorizes manual reminder triggers (`notification.send` permission + organization admin membership).

---

## Business Rules

### 1. Upcoming Reminders

- Triggered when `as_of_date` equals `due_date - reminder_days_before`.
- Notification Type: `payment.reminder` (or `payment.upcoming_reminder`).
- Notification Title: `"Payment Reminder"`.
- Notification Message: `"Your payment of RM{outstanding_amount} for {class_name} is due on {due_date}."`.

### 2. Overdue Reminders

- Triggered when `as_of_date` equals `due_date + reminder_days_after`.
- Schedule status is automatically updated to `overdue` if past due date.
- Notification Type: `payment.overdue` (or `payment.overdue_reminder`).
- Notification Title: `"Payment Overdue"`.
- Notification Message: `"Your payment of RM{outstanding_amount} for {class_name} was due on {due_date}."`.

---

## Server-Side Outstanding Balance & Partial Payments

Reminders calculate the remaining **outstanding balance** server-side:

```
outstanding_amount = max(0.00, required_amount - sum(paid_payments.total_amount))
```

- **Partial Payment Example**:
    - `required_amount` = `RM100.00`
    - Total `paid` payments = `RM30.00`
    - Outstanding balance = `RM70.00`
    - Reminder notification message: `"Your payment of RM70.00 for Quran Class is due on 15 September 2026."`
- **Fully Paid Schedule**: If `outstanding_amount <= 0.00`, no reminder is generated regardless of schedule status.

---

## Deduplication Strategy

To prevent duplicate notifications when cron/schedulers run frequently (e.g., every minute):

- Unique Reminder Identity: `payment_schedule_id + reminder_type + reminder_date`.
- Deduplication Query (`isReminderAlreadySent`): Checks if a notification already exists for the user, payment schedule ID, reminder type, and target date (`created_at` or `data->reminder_date`).
- Guarantees that at most **one** notification per reminder type per target date is generated per schedule.

---

## Structured Deep-Link Data Payload

All created reminder notifications include structured JSON payload data for mobile client navigation (Flutter contract):

```json
{
    "type": "payment.reminder",
    "payment_schedule_id": 123,
    "class_id": 10,
    "reminder_type": "upcoming",
    "reminder_date": "2026-09-12",
    "outstanding_amount": "70.00",
    "action": "open_payment",
    "route": "/payment-schedules/123"
}
```

---

## Admin Endpoints & Manual Trigger

### 1. Manual Reminder Trigger

`POST /api/v1/payment-schedules/{paymentSchedule}/reminder`

- **Auth**: `auth:sanctum`
- **Authorization**: `notification.send` permission + organization admin check (`PaymentSchedulePolicy::sendReminder`). Students or unauthorized admins return `403 Forbidden`.
- **Response**: `200 OK` with standard `NotificationResource` envelope.

### 2. Admin Reminder Preview

`GET /api/v1/payment-schedules/reminders/preview?as_of_date=2026-09-12`

- **Auth**: `auth:sanctum`
- **Authorization**: `notification.view` or `payment.view` permission.
- **Response**: Returns JSON list of eligible schedules, user names, due dates, and calculated outstanding balances without creating notification records.

---

## Testing Matrix

Covered in `tests/Feature/Services/PaymentReminderServiceTest.php` and `tests/Feature/Api/PaymentReminderApiTest.php`:

1. Upcoming reminder generation rules.
2. Overdue reminder generation rules.
3. Disabled reminder setting skipping.
4. Paid or cancelled schedule skipping.
5. `reminder_days_before` and `reminder_days_after` date math.
6. Idempotent deduplication prevention on repeated runs.
7. Outstanding balance calculation for partial payments.
8. Fully paid partial payment skipping.
9. Inactive participant skipping.
10. Notification data payload deep-link contract.
11. Multi-schedule and multi-user isolation.
12. Admin manual trigger permission & org authorization (`403` for students).
13. Admin reminder preview functionality.
