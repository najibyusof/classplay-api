<?php

namespace Tests\Feature\Console;

use App\Jobs\SendNotificationJob;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Notification;
use App\Models\PaymentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SendPaymentRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_one_reminder_for_schedule_due_within_reminder_window(): void
    {
        Bus::fake();

        $participant = ClassParticipant::factory()->create();
        $setting = ClassPaymentSetting::factory()->create([
            'class_id' => $participant->class_id,
            'reminder_enabled' => true,
            'reminder_days_before' => 3,
            'reminder_days_after' => 3,
        ]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $participant->class_id,
            'class_participant_id' => $participant->id,
            'due_date' => now()->addDays(3),
            'status' => 'pending',
        ]);

        $this->artisan('payments:send-reminders')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $participant->user_id,
            'type' => 'payment.reminder',
            'related_id' => $schedule->id,
        ]);

        // Running again the same day must not create a duplicate reminder.
        $this->artisan('payments:send-reminders');

        $this->assertSame(1, Notification::query()->where('related_id', $schedule->id)->count());
        Bus::assertDispatchedTimes(SendNotificationJob::class, 1);
    }
}
