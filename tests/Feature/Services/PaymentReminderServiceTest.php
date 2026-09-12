<?php

namespace Tests\Feature\Services;

use App\Jobs\SendNotificationJob;
use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Services\Notification\NotificationType;
use App\Services\Reminder\PaymentReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PaymentReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentReminderService::class);
    }

    private function createFixture(array $settingOverrides = [], array $scheduleOverrides = []): array
    {
        $organization = Organization::factory()->create();
        $class = ClassModel::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $setting = ClassPaymentSetting::factory()->create(array_merge([
            'class_id' => $class->id,
            'required_amount' => 100.00,
            'reminder_enabled' => true,
            'reminder_days_before' => 3,
            'reminder_days_after' => 2,
        ], $settingOverrides));

        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        $schedule = PaymentSchedule::factory()->create(array_merge([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 100.00,
            'due_date' => '2026-09-15',
            'status' => 'pending',
        ], $scheduleOverrides));

        return compact('organization', 'class', 'setting', 'student', 'participant', 'schedule');
    }

    // 1. Upcoming reminder generated correctly.
    public function test_upcoming_reminder_generated_correctly(): void
    {
        Bus::fake();
        ['schedule' => $schedule, 'student' => $student] = $this->createFixture();

        // Target date for 3 days before due date (2026-09-15) is 2026-09-12
        $asOfDate = Carbon::parse('2026-09-12');
        $sentCount = $this->service::class ? $this->service->processUpcomingReminders($asOfDate) : 0;

        $this->assertSame(1, $sentCount);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'type' => NotificationType::PAYMENT_REMINDER,
            'related_id' => $schedule->id,
        ]);

        Bus::assertDispatched(SendNotificationJob::class);
    }

    // 2. Overdue reminder generated correctly.
    public function test_overdue_reminder_generated_correctly(): void
    {
        Bus::fake();
        ['schedule' => $schedule, 'student' => $student] = $this->createFixture([], ['due_date' => '2026-09-10']);

        // Target date for 2 days after due date (2026-09-10) is 2026-09-12
        $asOfDate = Carbon::parse('2026-09-12');
        $sentCount = $this->service->processOverdueReminders($asOfDate);

        $this->assertSame(1, $sentCount);
        $this->assertSame('overdue', $schedule->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'type' => NotificationType::PAYMENT_OVERDUE,
            'related_id' => $schedule->id,
        ]);
    }

    // 3. Reminder disabled -> no notification.
    public function test_reminder_disabled_produces_no_notification(): void
    {
        ['schedule' => $schedule] = $this->createFixture(['reminder_enabled' => false]);

        $asOfDate = Carbon::parse('2026-09-12');
        $sentCount = $this->service->processAllReminders($asOfDate);

        $this->assertSame(0, $sentCount);
        $this->assertDatabaseMissing('notifications', ['related_id' => $schedule->id]);
    }

    // 4. Paid schedule -> no reminder.
    public function test_paid_schedule_produces_no_reminder(): void
    {
        ['schedule' => $schedule] = $this->createFixture([], ['status' => 'paid']);

        $asOfDate = Carbon::parse('2026-09-12');
        $sentCount = $this->service->processAllReminders($asOfDate);

        $this->assertSame(0, $sentCount);
    }

    // 5. Cancelled schedule -> no reminder.
    public function test_cancelled_schedule_produces_no_reminder(): void
    {
        ['schedule' => $schedule] = $this->createFixture([], ['status' => 'cancelled']);

        $asOfDate = Carbon::parse('2026-09-12');
        $sentCount = $this->service->processAllReminders($asOfDate);

        $this->assertSame(0, $sentCount);
    }

    // 6. Correct reminder_days_before behavior.
    public function test_reminder_days_before_behavior(): void
    {
        ['schedule' => $schedule] = $this->createFixture(['reminder_days_before' => 5], ['due_date' => '2026-09-20']);

        // Due 2026-09-20, 5 days before is 2026-09-15. Testing on 2026-09-14 should yield 0.
        $this->assertSame(0, $this->service->processUpcomingReminders(Carbon::parse('2026-09-14')));

        // Testing on 2026-09-15 should yield 1.
        $this->assertSame(1, $this->service->processUpcomingReminders(Carbon::parse('2026-09-15')));
    }

    // 7. Correct reminder_days_after behavior.
    public function test_reminder_days_after_behavior(): void
    {
        ['schedule' => $schedule] = $this->createFixture(['reminder_days_after' => 4], ['due_date' => '2026-09-10']);

        // Due 2026-09-10, 4 days after is 2026-09-14. Testing on 2026-09-12 should yield 0.
        $this->assertSame(0, $this->service->processOverdueReminders(Carbon::parse('2026-09-12')));

        // Testing on 2026-09-14 should yield 1.
        $this->assertSame(1, $this->service->processOverdueReminders(Carbon::parse('2026-09-14')));
    }

    // 8. Duplicate scheduler execution does not duplicate notification.
    public function test_duplicate_scheduler_execution_is_idempotent(): void
    {
        ['schedule' => $schedule] = $this->createFixture();
        $asOfDate = Carbon::parse('2026-09-12');

        $firstRun = $this->service->processUpcomingReminders($asOfDate);
        $this->assertSame(1, $firstRun);

        // Second run on the same date
        $secondRun = $this->service->processUpcomingReminders($asOfDate);
        $this->assertSame(0, $secondRun);

        $this->assertSame(1, Notification::query()->where('related_id', $schedule->id)->count());
    }

    // 9. Partial payment calculates outstanding balance correctly.
    public function test_partial_payment_calculates_outstanding_balance(): void
    {
        ['schedule' => $schedule, 'student' => $student] = $this->createFixture([], ['required_amount' => 100.00]);

        // Create partial paid payment of RM30.00
        Payment::factory()->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'required_amount' => 100.00,
            'total_amount' => 30.00,
            'status' => 'paid',
        ]);

        $outstanding = $this->service->calculateOutstandingBalance($schedule);
        $this->assertEquals(70.00, $outstanding);

        $asOfDate = Carbon::parse('2026-09-12');
        $this->service->processUpcomingReminders($asOfDate);

        $notification = Notification::query()->where('related_id', $schedule->id)->sole();
        $this->assertStringContainsString('70.00', $notification->message);
        $this->assertSame('70.00', $notification->data['outstanding_amount']);
    }

    // 10. Fully paid partial-payment schedule produces no reminder.
    public function test_fully_paid_partial_payment_schedule_produces_no_reminder(): void
    {
        ['schedule' => $schedule, 'student' => $student] = $this->createFixture([], ['required_amount' => 100.00]);

        // Two payments adding up to 100.00
        Payment::factory()->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'total_amount' => 40.00,
            'status' => 'paid',
        ]);
        Payment::factory()->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'total_amount' => 60.00,
            'status' => 'paid',
        ]);

        $asOfDate = Carbon::parse('2026-09-12');
        $sentCount = $this->service->processUpcomingReminders($asOfDate);

        $this->assertSame(0, $sentCount);
    }

    // 11. Removed / inactive participant does not receive reminder.
    public function test_inactive_participant_does_not_receive_reminder(): void
    {
        ['participant' => $participant] = $this->createFixture();
        $participant->update(['status' => 'inactive']);

        $asOfDate = Carbon::parse('2026-09-12');
        $sentCount = $this->service->processUpcomingReminders($asOfDate);

        $this->assertSame(0, $sentCount);
    }

    // 12 & 13 & 14. Correct notification type, schedule ID, and deep-link data.
    public function test_notification_contains_correct_type_and_deep_link_data(): void
    {
        ['schedule' => $schedule, 'class' => $class] = $this->createFixture();

        $asOfDate = Carbon::parse('2026-09-12');
        $this->service->processUpcomingReminders($asOfDate);

        $notification = Notification::query()->where('related_id', $schedule->id)->sole();

        $this->assertSame(NotificationType::PAYMENT_REMINDER, $notification->type);
        $this->assertSame($schedule->id, $notification->data['payment_schedule_id']);
        $this->assertSame($class->id, $notification->data['class_id']);
        $this->assertSame('open_payment', $notification->data['action']);
        $this->assertSame("/payment-schedules/{$schedule->id}", $notification->data['route']);
    }

    // 15 & 16. Multiple schedules and multiple users generate independent reminders.
    public function test_multiple_schedules_and_users_generate_independent_reminders(): void
    {
        $fixture1 = $this->createFixture();
        $fixture2 = $this->createFixture();

        $asOfDate = Carbon::parse('2026-09-12');
        $sentCount = $this->service->processUpcomingReminders($asOfDate);

        $this->assertSame(2, $sentCount);
        $this->assertDatabaseHas('notifications', ['user_id' => $fixture1['student']->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $fixture2['student']->id]);
    }

    // Preview method works.
    public function test_preview_eligible_reminders_returns_structure(): void
    {
        $this->createFixture();
        $asOfDate = Carbon::parse('2026-09-12');

        $preview = $this->service->previewEligibleReminders($asOfDate);

        $this->assertSame('2026-09-12', $preview['as_of_date']);
        $this->assertSame(1, $preview['eligible_count']);
        $this->assertCount(1, $preview['reminders']);
        $this->assertSame('upcoming', $preview['reminders'][0]['reminder_type']);
    }
}
