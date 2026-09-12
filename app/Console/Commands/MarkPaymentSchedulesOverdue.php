<?php

namespace App\Console\Commands;

use App\Models\PaymentSchedule;
use Illuminate\Console\Command;

class MarkPaymentSchedulesOverdue extends Command
{
    protected $signature = 'payment-schedules:mark-overdue';

    protected $description = 'Mark pending or partially paid payment schedules as overdue once their due date has passed';

    public function handle(): int
    {
        $count = PaymentSchedule::query()
            ->whereIn('status', ['upcoming', 'pending', 'partially_paid'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);

        $this->info("Marked {$count} payment schedule(s) as overdue.");

        return self::SUCCESS;
    }
}
