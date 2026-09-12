<?php

use App\Jobs\GeneratePaymentRemindersJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payment-schedules:mark-overdue')
    ->dailyAt('00:05')
    ->timezone('Asia/Kuala_Lumpur')
    ->onOneServer();

Schedule::command('payment-schedules:generate')
    ->dailyAt('00:10')
    ->timezone('Asia/Kuala_Lumpur')
    ->onOneServer();

Schedule::job(new GeneratePaymentRemindersJob)
    ->everyTenMinutes()
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('payments:send-reminders')
    ->dailyAt('08:00')
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping()
    ->onOneServer();
