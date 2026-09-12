# Production Deployment & Configuration Guide (Phase 26)

## Overview

This guide provides deployment instructions, production configurations, background worker management, scheduler setup, backup procedures, health check specifications, and rollback protocols for the ClassPay Laravel API backend.

---

## 1. Server Requirements

- **PHP**: `^8.3` (with extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`)
- **Database**: MySQL `^8.0` or MariaDB `^10.6`
- **Web Server**: Nginx or Apache configured to serve `public/index.php`
- **Process Manager**: Supervisor or systemd (for background queue workers)
- **Cron**: Standard system crontab (for Laravel scheduler)

---

## 2. Environment Variables (.env)

Ensure the production `.env` file is placed at the application root (`storage` and `.env` permissions `600`/`700` owned by `www-data`).

```env
APP_NAME="ClassPay"
APP_ENV=production
APP_KEY=base64:GENERATE_WITH_ARTISAN_KEY_GENERATE
APP_DEBUG=false
APP_URL=https://api.classpay.com.my

APP_LOCALE=en
APP_FALLBACK_LOCALE=en

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=classpay_prod
DB_USERNAME=classpay_user
DB_PASSWORD=Secure_Random_DB_Password_Here

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true

FILESYSTEM_DISK=public
QUEUE_CONNECTION=database
CACHE_STORE=database

CORS_ALLOWED_ORIGINS="https://admin.classpay.com.my,https://classpay.com.my"

PAYMENT_WEBHOOK_SECRET=your_production_webhook_secret

PAYMENT_GATEWAY=merchant
PAYMENT_GATEWAY_MERCHANT_ID=your_merchant_id
PAYMENT_GATEWAY_API_KEY=your_api_key
PAYMENT_GATEWAY_SECRET=your_gateway_secret
PAYMENT_GATEWAY_URL=https://gateway.provider.com/api/pay
PAYMENT_GATEWAY_TIMEOUT=10

FCM_SERVER_KEY=your_fcm_server_key
TELEGRAM_BOT_TOKEN=your_telegram_bot_token
```

---

## 3. Production Deployment Commands

Run these steps during every production deployment:

```bash
# 1. Pull latest code or extract release build
git pull origin main

# 2. Install production dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Execute database migrations
php artisan migrate --force

# 4. Cache configuration, routes, and views
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5. Ensure storage symbolic link exists
php artisan storage:link

# 6. Restart queue workers
php artisan queue:restart
```

---

## 4. Health Check Endpoint

ClassPay provides a lightweight, unauthenticated health check endpoint:

- **Method & Path**: `GET /api/v1/health`
- **Response Format (`200 OK`)**:
    ```json
    {
        "success": true,
        "message": "Service is healthy.",
        "data": {
            "status": "ok",
            "timestamp": "2026-09-12T12:00:00.000000Z"
        }
    }
    ```
- **Error Handling (`503 Service Unavailable`)**:
    - If database connectivity fails, the health check returns `503 Service Unavailable` with `{"success": false, "message": "Service is unhealthy.", "errors": {}}`.
    - Database credentials, server IP, stack traces, and internal SQL errors are **never exposed**.

---

## 5. Queue Worker Configuration

Production background jobs (`SendNotificationJob`, `GeneratePaymentRemindersJob`) run via the `database` queue driver.

### Supervisor Configuration (`/etc/supervisor/conf.d/classpay-worker.conf`)

```ini
[program:classpay-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/classpay-api/artisan queue:work database --queue=default --tries=3 --backoff=60,300,900 --timeout=90 --sleep=3 --max-jobs=1000 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/classpay-api/storage/logs/worker.log
stopwaitsecs=3600
```

---

## 6. Scheduler Configuration

The Laravel scheduler handles automated payment reminders and overdue schedule marking.

### Server Crontab Entry

Add the following single entry to `www-data` crontab (`crontab -e -u www-data`):

```crontab
* * * * * cd /var/www/classpay-api && php artisan schedule:run >> /dev/null 2>&1
```

All scheduled tasks (`GeneratePaymentRemindersJob`, `payment-schedules:mark-overdue`, `payment-schedules:generate`) enforce `Asia/Kuala_Lumpur` timezone, `withoutOverlapping()`, and `onOneServer()`.

---

## 7. Storage & File Permissions

Ensure `storage` and `bootstrap/cache` directories are writable by the web server user (`www-data`):

```bash
sudo chown -R www-data:www-data /var/www/classpay-api/storage /var/www/classpay-api/bootstrap/cache
sudo chmod -R 775 /var/www/classpay-api/storage /var/www/classpay-api/bootstrap/cache
```

Private files (e.g. `storage/app/private`) are kept outside web-root. Publicly accessible assets (payment proofs, QR codes) are placed in `storage/app/public` and exposed via `public/storage` symlink (`php artisan storage:link`).

---

## 8. Logging & Security Controls

- **Log Channel**: `LOG_CHANNEL=stack`, writing to `storage/logs/laravel.log` at `LOG_LEVEL=error` or `info`.
- **Sensitive Data Filtering**: Passwords, Sanctum tokens, payment keys, API secrets, and authorization headers are scrubbed before logging.
- **Debug Shielding**: `APP_DEBUG=false` ensures all 500 errors return a sanitized `{"success": false, "message": "Server error.", "errors": {}}` JSON envelope without leaking stack traces or internal paths.

---

## 9. Backup Procedures

Perform automated daily backups of MySQL database and uploaded storage assets:

### Database Backup

```bash
mysqldump -u classpay_user -p'Password' classpay_prod | gzip > /backups/classpay_db_$(date +\%F).sql.gz
```

### File Assets Backup

```bash
tar -czf /backups/classpay_storage_$(date +\%F).tar.gz /var/www/classpay-api/storage/app/public
```

---

## 10. Rollback Protocol

If a deployment deployment fails or issues are identified post-release:

```bash
# 1. Roll back code to previous release tag/commit
git checkout PREVIOUS_RELEASE_TAG

# 2. Roll back database migration if necessary
php artisan migrate:rollback --step=1

# 3. Clear and refresh caches
php artisan config:cache
php artisan route:cache

# 4. Restart background queue workers
php artisan queue:restart
```
