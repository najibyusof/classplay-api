<?php

namespace App\Jobs;

use App\Services\Reminder\PaymentReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GeneratePaymentRemindersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'generate_payment_reminders';
    }

    public function handle(PaymentReminderService $reminderService): void
    {
        $sentCount = $reminderService->processAllReminders(now('Asia/Kuala_Lumpur'));

        Log::info('GeneratePaymentRemindersJob executed.', [
            'sent_count' => $sentCount,
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);
    }
}
