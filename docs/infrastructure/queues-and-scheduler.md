# Queue & Scheduler Infrastructure (Phase 18)

## Overview

Phase 18 establishes the asynchronous **Queue & Scheduler Infrastructure** for ClassPay, handling background notification delivery, scheduled payment reminder generation, job retries, failure handling, and production worker management.

No business logic rules (payment calculations, schedule states, reminder eligibility) were altered in this phase — existing domain services (`PaymentReminderService`, `NotificationService`, `NotificationDispatcher`, `PushNotificationServiceInterface`) are invoked asynchronously.

---

## Queue Strategy & Driver

- **Default Queue Driver**: `database` (configured in `config/queue.php` via `QUEUE_CONNECTION=database`).
- **Database Tables**:
    - `jobs` — Pending and reserved queue jobs.
    - `job_batches` — Batching metadata.
    - `failed_jobs` — Log of permanently failed job executions.
- **Why Database Driver**: Simple, zero external infrastructure dependency for production deployment, reliable transactional guarantee.

---

## Queue Jobs Architecture

```
Laravel Scheduler (Cron)
          │
          ▼
GeneratePaymentRemindersJob (ShouldBeUnique)
          │
          ├── Calls PaymentReminderService::processAllReminders()
          │     ├── Evaluates upcoming & overdue schedules
          │     ├── Checks deduplication (isReminderAlreadySent)
          │     └── Creates Notification records
          │
          ▼
SendNotificationJob (ShouldQueue)
          │
          ├── Receives notification ID
          ├── Calls NotificationDispatcher::dispatch()
          │     ├── Push delivery (PushNotificationServiceInterface / FCM)
          │     ├── Email delivery (Mail)
          │     └── Telegram delivery (Bot API)
          │
          └── Writes NotificationLog (`status = 'sent'` / `'failed'`)
```

### 1. `GeneratePaymentRemindersJob`

- **Class**: `App\Jobs\GeneratePaymentRemindersJob`
- **Interfaces**: `ShouldQueue`, `ShouldBeUnique`
- **Unique Lock Duration**: `uniqueFor = 600` (10 minutes)
- **Unique Lock ID**: `generate_payment_reminders`
- **Tries & Backoff**: `tries = 3`, `backoff = [60, 300, 900]`
- **Timezone**: Evaluates business logic in `Asia/Kuala_Lumpur`.

### 2. `SendNotificationJob`

- **Class**: `App\Jobs\SendNotificationJob`
- **Interfaces**: `ShouldQueue`
- **Payload**: Accepts `Notification|int $notification` (deserializes model by ID safely without serializing sensitive state).
- **Tries & Backoff**: `tries = 3`, `backoff = [60, 300, 900]`
- **Idempotency & Failure**: Gracefully handles deleted notifications. Automatically deactivates invalid push tokens (`UserDevice.status = 'inactive'`) without infinite retries.

---

## Scheduler Configuration

The scheduler is defined in `routes/console.php`:

```php
// Daily maintenance tasks
Schedule::command('payment-schedules:mark-overdue')
    ->dailyAt('00:05')
    ->timezone('Asia/Kuala_Lumpur')
    ->onOneServer();

Schedule::command('payment-schedules:generate')
    ->dailyAt('00:10')
    ->timezone('Asia/Kuala_Lumpur')
    ->onOneServer();

// Automated Payment Reminder Generation (every 10 minutes)
Schedule::job(new GeneratePaymentRemindersJob)
    ->everyTenMinutes()
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping()
    ->onOneServer();

// Daily Artisan Reminder Command Fallback
Schedule::command('payments:send-reminders')
    ->dailyAt('08:00')
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping()
    ->onOneServer();
```

### Key Scheduler Safeguards

- **Timezone**: Explicitly pinned to `Asia/Kuala_Lumpur` for Malaysian due-date math.
- **Overlap Protection**: `withoutOverlapping()` + `ShouldBeUnique` on `GeneratePaymentRemindersJob` prevents multiple instances from running concurrently.
- **Server Isolation**: `onOneServer()` prevents duplicate scheduler runs in multi-instance or load-balanced environments.

---

## Local Development Workflow

To run the application, background worker, and scheduler locally during development:

1. **Run Application**:

    ```bash
    php artisan serve
    ```

2. **Run Queue Worker**:

    ```bash
    php artisan queue:work --tries=3 --backoff=60,300,900
    ```

3. **Run Scheduler Locally**:
    ```bash
    php artisan schedule:work
    ```

---

## Production Deployment & Worker Management

### 1. Cron Entry (Every Minute)

On the production server, add a single crontab entry for the Web/API server:

```crontab
* * * * * cd /path/to/classpay-api && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Production Queue Worker

Run worker processes using Supervisor, systemd, or container process managers:

```bash
php artisan queue:work database --queue=default --tries=3 --backoff=60,300,900 --timeout=90 --sleep=3 --max-jobs=1000 --max-time=3600
```

### 3. Supervisor Example Configuration (`/etc/supervisor/conf.d/classpay-worker.conf`)

```ini
[program:classpay-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/classpay-api/artisan queue:work database --tries=3 --backoff=60,300,900 --timeout=90
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/classpay-api/storage/logs/worker.log
```

---

## Failed Jobs & Monitoring

### Inspecting Failed Jobs

If an unhandled exception occurs after all 3 retry attempts, the job is moved to `failed_jobs`:

```bash
# List failed jobs
php artisan queue:failed

# Retry a specific failed job
php artisan queue:retry {uuid}

# Retry all failed jobs
php artisan queue:retry all

# Flush all failed jobs
php artisan queue:flush
```

---

## Testing Matrix

Covered in `tests/Feature/Infrastructure/QueueAndSchedulerTest.php` (17 tests):

1. `GeneratePaymentRemindersJob` dispatching to queue.
2. `SendNotificationJob` dispatching to queue.
3. Service execution inside job handlers.
4. Job execution idempotency.
5. Retry & backoff configuration validation (`tries = 3`, `backoff = [60, 300, 900]`).
6. Missing notification ID graceful handling.
7. Invalid device token deactivation & logging.
8. Scheduler task registration, frequency, and `Asia/Kuala_Lumpur` timezone checks.
9. Overlap protection (`withoutOverlapping`, `ShouldBeUnique`).
10. Database queue connection configuration.
11. Success and failure notification log generation.
12. `payments:send-reminders` Artisan command execution.
