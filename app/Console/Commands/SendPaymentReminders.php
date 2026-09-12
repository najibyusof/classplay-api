<?php

namespace App\Console\Commands;

use App\Services\Reminder\PaymentReminderService;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'payments:send-reminders';

    protected $description = 'Send payment reminder notifications for upcoming and overdue payment schedules';

    public function handle(PaymentReminderService $reminderService): int
    {
        $sent = $reminderService->processAllReminders(now('Asia/Kuala_Lumpur'));

        $this->info("Sent {$sent} payment reminder notification(s).");

        return self::SUCCESS;
    }
}
