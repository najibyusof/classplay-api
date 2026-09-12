<?php

namespace App\Console\Commands;

use App\Models\ClassModel;
use App\Services\Payment\PaymentScheduleService;
use Illuminate\Console\Command;

class GeneratePaymentSchedules extends Command
{
    protected $signature = 'payment-schedules:generate {--horizon= : Number of upcoming periods to generate, defaults to the service default}';

    protected $description = 'Generate missing payment schedules for every active class';

    public function handle(PaymentScheduleService $service): int
    {
        $horizon = $this->option('horizon') ? (int) $this->option('horizon') : null;

        $classes = ClassModel::query()->where('status', 'active')->whereHas('paymentSetting')->get();

        $totalCreated = 0;

        foreach ($classes as $class) {
            $created = $service->generateForClass($class, $horizon);
            $totalCreated += $created->count();

            if ($created->isNotEmpty()) {
                $this->line("Class #{$class->id} ({$class->name}): {$created->count()} schedule(s) created.");
            }
        }

        $this->info("Processed {$classes->count()} active class(es); {$totalCreated} new payment schedule(s) created.");

        return self::SUCCESS;
    }
}
