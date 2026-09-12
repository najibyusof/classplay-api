<?php

namespace Tests\Feature\Services;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\PaymentSchedule;
use App\Services\Payment\PaymentScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PaymentScheduleService::class);
    }

    private function activeClass(array $classAttributes = [], array $settingAttributes = []): ClassModel
    {
        $class = ClassModel::factory()->create(array_merge(['status' => 'active'], $classAttributes));

        ClassPaymentSetting::factory()->create(array_merge(['class_id' => $class->id], $settingAttributes));

        return $class->fresh();
    }

    private function activeStudent(ClassModel $class): ClassParticipant
    {
        return ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'participant_type' => 'student',
            'status' => 'active',
        ]);
    }

    // 1. Weekly schedule generation.
    public function test_weekly_generation_creates_seven_day_period(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05'], ['payment_frequency' => 'weekly']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertCount(1, $created);
        $this->assertSame('2026-01-05', $created->first()->period_start->toDateString());
        $this->assertSame('2026-01-11', $created->first()->period_end->toDateString());
    }

    // 2. Fortnightly schedule generation.
    public function test_fortnightly_generation_creates_fourteen_day_period(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05'], ['payment_frequency' => 'fortnightly']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertSame('2026-01-05', $created->first()->period_start->toDateString());
        $this->assertSame('2026-01-18', $created->first()->period_end->toDateString());
    }

    // 3/4/5. Monthly schedule generation with correct period_start/period_end.
    public function test_monthly_generation_creates_calendar_month_period(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15'));
        $class = $this->activeClass(['start_date' => '2026-01-15'], ['payment_frequency' => 'monthly']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertSame('2026-01-15', $created->first()->period_start->toDateString());
        $this->assertSame('2026-02-14', $created->first()->period_end->toDateString());
    }

    // 6. Correct due_date (centralized default: due at the start of the period).
    public function test_due_date_defaults_to_period_start(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertSame('2026-01-05', $created->first()->due_date->toDateString());
    }

    // 7. Required amount is snapshotted from the class payment setting.
    public function test_required_amount_is_snapshotted(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05'], ['required_amount' => 50]);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertSame('50.00', $created->first()->required_amount);
    }

    // 8 & 17. Changing the class amount does not alter existing (historical) schedules.
    public function test_changing_class_amount_does_not_alter_existing_schedules(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05'], ['required_amount' => 50]);
        $this->activeStudent($class);

        $this->service->generateForClass($class, 1);
        $original = PaymentSchedule::query()->sole();

        $class->paymentSetting->update(['required_amount' => 60]);

        $this->assertSame('50.00', $original->fresh()->required_amount);
        $this->assertSame('active', $original->fresh()->classModel->status);
    }

    // 9. New schedules use the new amount.
    public function test_new_schedules_use_the_updated_amount(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05', 'end_date' => null], ['required_amount' => 50, 'payment_frequency' => 'weekly']);
        $this->activeStudent($class);

        $this->service->generateForClass($class, 1);
        $class->paymentSetting->update(['required_amount' => 60]);

        // Horizon 2 leaves period 1 untouched (already exists) and creates period 2 with the new amount.
        $created = $this->service->generateForClass($class, 2);

        $this->assertCount(1, $created);
        $this->assertSame('60.00', $created->first()->required_amount);
        $this->assertSame('50.00', PaymentSchedule::query()->orderBy('period_start')->first()->required_amount);
    }

    // 10/11. Only active participants receive schedules; inactive participants do not.
    public function test_inactive_participant_does_not_receive_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'status' => 'inactive']);

        $created = $this->service->generateForClass($class, 1);

        $this->assertCount(0, $created);
    }

    // 12. Removed participants do not receive new schedules.
    public function test_removed_participant_does_not_receive_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'status' => 'removed']);

        $created = $this->service->generateForClass($class, 1);

        $this->assertCount(0, $created);
    }

    // Sponsors are not billed directly; only student participants receive schedules.
    public function test_sponsor_participant_does_not_receive_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'participant_type' => 'sponsor', 'status' => 'active']);

        $created = $this->service->generateForClass($class, 1);

        $this->assertCount(0, $created);
    }

    // 13. Running generation twice does not create duplicates.
    public function test_running_generation_twice_does_not_duplicate(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05']);
        $this->activeStudent($class);

        $this->service->generateForClass($class, 2);
        $firstRunCount = PaymentSchedule::query()->count();

        $secondRunCreated = $this->service->generateForClass($class, 2);

        $this->assertCount(0, $secondRunCreated);
        $this->assertSame($firstRunCount, PaymentSchedule::query()->count());
    }

    // 14. Multiple participants receive independent schedules.
    public function test_multiple_participants_receive_independent_schedules(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05']);
        $this->activeStudent($class);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertCount(2, $created);
        $this->assertCount(2, $created->pluck('class_participant_id')->unique());
    }

    // 15. Multiple classes generate independent schedules.
    public function test_multiple_classes_generate_independent_schedules(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $classA = $this->activeClass(['start_date' => '2026-01-05']);
        $classB = $this->activeClass(['start_date' => '2026-01-05']);
        $this->activeStudent($classA);
        $this->activeStudent($classB);

        $this->service->generateForClass($classA, 1);
        $this->service->generateForClass($classB, 1);

        $this->assertSame(1, PaymentSchedule::query()->forClass($classA->id)->count());
        $this->assertSame(1, PaymentSchedule::query()->forClass($classB->id)->count());
    }

    // Edge case: class without payment settings generates nothing.
    public function test_class_without_payment_settings_generates_nothing(): void
    {
        $class = ClassModel::factory()->create(['status' => 'active']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class);

        $this->assertCount(0, $created);
    }

    // Edge case: class without participants generates nothing.
    public function test_class_without_participants_generates_nothing(): void
    {
        $class = $this->activeClass();

        $created = $this->service->generateForClass($class);

        $this->assertCount(0, $created);
    }

    // Edge case: class with no start date falls back to today.
    public function test_class_without_start_date_anchors_to_today(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10'));
        $class = $this->activeClass(['start_date' => null]);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertSame('2026-03-10', $created->first()->period_start->toDateString());
    }

    // Edge case: class with an end date already in the past generates nothing.
    public function test_class_with_end_date_in_the_past_generates_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01'));
        $class = $this->activeClass(['start_date' => '2026-01-01', 'end_date' => '2026-02-01']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 3);

        $this->assertCount(0, $created);
    }

    // Edge case: inactive class generates nothing even with valid settings/participants.
    public function test_inactive_class_generates_nothing(): void
    {
        $class = $this->activeClass(['status' => 'draft']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class);

        $this->assertCount(0, $created);
    }

    // Edge case: month-end date does not overflow into the following month (e.g. Jan 31 -> Mar 3 bug).
    public function test_month_end_start_date_does_not_overflow(): void
    {
        $this->travelTo(Carbon::parse('2026-01-31'));
        $class = $this->activeClass(['start_date' => '2026-01-31'], ['payment_frequency' => 'monthly']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertSame('2026-02-27', $created->first()->period_end->toDateString());
    }

    // Edge case: year boundary is handled correctly.
    public function test_monthly_period_crossing_year_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-12-20'));
        $class = $this->activeClass(['start_date' => '2026-12-20'], ['payment_frequency' => 'monthly']);
        $this->activeStudent($class);

        $created = $this->service->generateForClass($class, 1);

        $this->assertSame('2026-12-20', $created->first()->period_start->toDateString());
        $this->assertSame('2027-01-19', $created->first()->period_end->toDateString());
    }

    // Edge case: a participant removed after schedules exist keeps their historical schedules.
    public function test_historical_schedules_survive_participant_removal(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05'));
        $class = $this->activeClass(['start_date' => '2026-01-05']);
        $participant = $this->activeStudent($class);

        $this->service->generateForClass($class, 1);
        $scheduleId = PaymentSchedule::query()->sole()->id;

        $participant->update(['status' => 'removed', 'left_at' => now()]);

        $this->assertDatabaseHas('payment_schedules', ['id' => $scheduleId]);
    }
}
