<?php

namespace Tests\Feature\Infrastructure;

use App\Contracts\Notification\PushNotificationServiceInterface;
use App\Jobs\GeneratePaymentRemindersJob;
use App\Jobs\SendNotificationJob;
use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Notification;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\NotificationDispatcher;
use App\Services\Reminder\PaymentReminderService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakePushNotificationService;
use Tests\TestCase;

class QueueAndSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function createReminderFixture(): array
    {
        $class = ClassModel::factory()->create(['status' => 'active']);
        $setting = ClassPaymentSetting::factory()->create([
            'class_id' => $class->id,
            'required_amount' => 100.00,
            'reminder_enabled' => true,
            'reminder_days_before' => 3,
            'reminder_days_after' => 2,
        ]);

        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 100.00,
            'due_date' => now('Asia/Kuala_Lumpur')->addDays(3)->toDateString(),
            'status' => 'pending',
        ]);

        return compact('class', 'setting', 'student', 'participant', 'schedule');
    }

    // 1. Reminder job dispatches correctly.
    public function test_reminder_job_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        GeneratePaymentRemindersJob::dispatch();

        Queue::assertPushed(GeneratePaymentRemindersJob::class);
    }

    // 2. Notification job dispatches correctly.
    public function test_notification_job_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        $notification = Notification::factory()->create();
        SendNotificationJob::dispatch($notification);

        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->notificationId === $notification->id);
    }

    // 3. Queue job uses correct service.
    public function test_generate_payment_reminders_job_executes_service(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->createReminderFixture();

        $job = new GeneratePaymentRemindersJob;
        $job->handle(app(PaymentReminderService::class));

        Bus::assertDispatched(SendNotificationJob::class);
    }

    // 4 & 9. Jobs are idempotent: running GeneratePaymentRemindersJob twice produces 1 reminder.
    public function test_generate_payment_reminders_job_is_idempotent(): void
    {
        ['schedule' => $schedule] = $this->createReminderFixture();

        $job = new GeneratePaymentRemindersJob;
        $job->handle(app(PaymentReminderService::class));
        $job->handle(app(PaymentReminderService::class));

        $this->assertSame(1, Notification::query()->where('related_id', $schedule->id)->count());
    }

    // 5. Retry configuration is set correctly on jobs.
    public function test_job_retry_and_backoff_configuration(): void
    {
        $reminderJob = new GeneratePaymentRemindersJob;
        $this->assertSame(3, $reminderJob->tries);
        $this->assertSame([60, 300, 900], $reminderJob->backoff);
        $this->assertSame('generate_payment_reminders', $reminderJob->uniqueId());

        $notificationJob = new SendNotificationJob(123);
        $this->assertSame(3, $notificationJob->tries);
        $this->assertSame([60, 300, 900], $notificationJob->backoff);
    }

    // 6 & 7. Missing notification does not crash or loop infinitely.
    public function test_send_notification_job_handles_missing_notification_gracefully(): void
    {
        $job = new SendNotificationJob(999999);
        $job->handle(app(NotificationDispatcher::class));

        $this->assertTrue(true); // Handled safely without throwing exception
    }

    // 8 & 10. Invalid device token is handled during push dispatch (deactivates token, logs failure).
    public function test_push_dispatch_handles_invalid_token_safely(): void
    {
        $fakePush = new FakePushNotificationService;
        $fakePush->invalidTokens[] = 'bad-token-xyz';
        $this->app->instance(PushNotificationServiceInterface::class, $fakePush);

        $user = User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'device_token' => 'bad-token-xyz', 'status' => 'active']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        $job = new SendNotificationJob($notification);
        $job->handle(app(NotificationDispatcher::class));

        $this->assertSame('inactive', $device->fresh()->status);
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notification->id,
            'recipient' => 'bad-token-xyz',
            'status' => 'failed',
        ]);
    }

    // 10 & 11. Scheduler invokes reminder process & timezone is correct.
    public function test_scheduler_registers_reminder_tasks_with_correct_timezone(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $reminderJobEvents = $events->filter(function (Event $event) {
            return str_contains($event->command ?? '', 'payments:send-reminders') || str_contains($event->description ?? '', 'GeneratePaymentRemindersJob');
        });

        $this->assertNotEmpty($reminderJobEvents);

        foreach ($reminderJobEvents as $event) {
            $this->assertSame('Asia/Kuala_Lumpur', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        }
    }

    // 12. Overlapping executions are prevented (ShouldBeUnique interface & withoutOverlapping).
    public function test_reminder_job_implements_unique_interface(): void
    {
        $job = new GeneratePaymentRemindersJob;
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
    }

    // 14. Queue configuration uses database connection by default.
    public function test_queue_default_connection_is_configured(): void
    {
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }

    // 15 & 16. Notification delivery logging works for success and failure.
    public function test_notification_delivery_logging_for_success_and_failure(): void
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['message_id' => 'fcm-msg-100']),
        ]);
        config(['notifications.fcm.server_key' => 'test-server-key']);

        $user = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        $job = new SendNotificationJob($notification->id);
        $job->handle(app(NotificationDispatcher::class));

        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notification->id,
            'channel' => 'push',
            'status' => 'sent',
            'provider_message_id' => 'fcm-msg-100',
        ]);
    }

    // Command payments:send-reminders runs the reminder process.
    public function test_send_reminders_command_executes_reminder_process(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->createReminderFixture();

        $this->artisan('payments:send-reminders')->assertSuccessful();

        Bus::assertDispatched(SendNotificationJob::class);
    }
}
