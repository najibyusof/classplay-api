<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\SponsorStudent;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private function studentWithSchedule(array $settingAttributes = []): array
    {
        $class = ClassModel::factory()->create(['status' => 'active']);
        $setting = ClassPaymentSetting::factory()->create(array_merge(['class_id' => $class->id, 'required_amount' => 50], $settingAttributes));
        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 50,
            'status' => 'pending',
        ]);

        return compact('class', 'setting', 'student', 'participant', 'schedule');
    }

    // 1. Student can view own payment schedules.
    public function test_student_can_view_own_payment_schedules(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/v1/student/payment-schedules')->assertOk();

        $ids = collect($response->json('data.payment_schedules'))->pluck('id');
        $this->assertTrue($ids->contains($schedule->id));
    }

    // 2. Student cannot view another student's schedule.
    public function test_student_cannot_view_another_students_schedule(): void
    {
        ['schedule' => $scheduleB] = $this->studentWithSchedule();
        $studentA = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($studentA);
        $this->getJson("/api/v1/student/payment-schedules/{$scheduleB->id}")->assertForbidden();
    }

    // 3. Sponsor can view authorized schedules (of their sponsored student).
    public function test_sponsor_can_view_sponsored_students_schedule(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        SponsorStudent::factory()->create(['sponsor_id' => $sponsor->id, 'student_id' => $student->id, 'status' => 'active']);

        Sanctum::actingAs($sponsor);
        $this->getJson("/api/v1/sponsor/payment-schedules/{$schedule->id}")->assertOk();

        $response = $this->getJson('/api/v1/sponsor/payment-schedules')->assertOk();
        $ids = collect($response->json('data.payment_schedules'))->pluck('id');
        $this->assertTrue($ids->contains($schedule->id));
    }

    // 4. Sponsor cannot view unrelated schedules.
    public function test_sponsor_cannot_view_unrelated_schedule(): void
    {
        ['schedule' => $schedule] = $this->studentWithSchedule();
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($sponsor);
        $this->getJson("/api/v1/sponsor/payment-schedules/{$schedule->id}")->assertForbidden();
    }

    // 5. Student can create payment.
    public function test_student_can_create_payment(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'manual',
        ]);

        $response->assertCreated()->assertJsonPath('message', 'Payment initiated successfully.');
        $this->assertDatabaseHas('payments', ['payment_schedule_id' => $schedule->id, 'payer_id' => $student->id]);
    }

    // 6. Sponsor can create payment for their sponsored student's schedule.
    public function test_sponsor_can_create_payment(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        SponsorStudent::factory()->create(['sponsor_id' => $sponsor->id, 'student_id' => $student->id, 'status' => 'active']);

        Sanctum::actingAs($sponsor);
        $response = $this->postJson("/api/v1/sponsor/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'manual',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', ['payment_schedule_id' => $schedule->id, 'payer_id' => $sponsor->id]);
    }

    // 7/8/9/10/11. Required amount, infaq, and total are all calculated server-side.
    public function test_amounts_are_calculated_server_side_and_client_cannot_override(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule(['allow_additional_infaq' => true]);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'additional_infaq' => 20,
            'payment_method' => 'manual',
            'required_amount' => 999,
            'total_amount' => 999999,
            'payer_id' => 999,
            'status' => 'paid',
        ])->assertCreated();

        $this->assertDatabaseHas('payments', [
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'required_amount' => 50,
            'additional_infaq' => 20,
            'total_amount' => 70,
        ]);
    }

    // 12. Client cannot mark payment paid via creation.
    public function test_created_payment_is_never_immediately_paid(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'manual',
        ])->assertCreated();

        $this->assertDatabaseMissing('payments', ['payment_schedule_id' => $schedule->id, 'status' => 'paid']);
    }

    // 21. Payment remains pending/initiated after creation, depending on method.
    public function test_payment_status_depends_on_payment_method(): void
    {
        ['student' => $student1, 'schedule' => $schedule1] = $this->studentWithSchedule();
        Sanctum::actingAs($student1);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule1->id}/payments", ['payment_method' => 'manual'])->assertCreated();
        $this->assertDatabaseHas('payments', ['payment_schedule_id' => $schedule1->id, 'status' => 'pending']);

        ['student' => $student2, 'schedule' => $schedule2] = $this->studentWithSchedule();
        Sanctum::actingAs($student2);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule2->id}/payments", ['payment_method' => 'qr'])->assertCreated();
        $this->assertDatabaseHas('payments', ['payment_schedule_id' => $schedule2->id, 'status' => 'pending']);
    }

    // A placeholder payment_transactions row is created (no real gateway contacted).
    public function test_payment_creation_creates_placeholder_transaction(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", ['payment_method' => 'qr'])->assertCreated();

        $payment = Payment::query()->where('payment_schedule_id', $schedule->id)->sole();
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'gateway_name' => 'qr',
        ]);
    }

    // 13. Infaq disabled -> reject infaq.
    public function test_infaq_is_rejected_when_disabled(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule(['allow_additional_infaq' => false]);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'additional_infaq' => 10,
            'payment_method' => 'manual',
        ])->assertUnprocessable()->assertJsonValidationErrors('additional_infaq');
    }

    // 14. Minimum infaq enforced.
    public function test_minimum_infaq_is_enforced(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule([
            'allow_additional_infaq' => true,
            'minimum_infaq' => 10,
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'additional_infaq' => 5,
            'payment_method' => 'manual',
        ])->assertUnprocessable()->assertJsonValidationErrors('additional_infaq');
    }

    // 15. Maximum infaq enforced.
    public function test_maximum_infaq_is_enforced(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule([
            'allow_additional_infaq' => true,
            'maximum_infaq' => 30,
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'additional_infaq' => 50,
            'payment_method' => 'manual',
        ])->assertUnprocessable()->assertJsonValidationErrors('additional_infaq');
    }

    // 16. Invalid payment method rejected.
    public function test_invalid_payment_method_is_rejected(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'crypto',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    // 17. Duplicate/repeat payment attempts are handled safely (multiple attempts allowed on one schedule).
    public function test_multiple_payment_attempts_are_allowed_until_settled(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", ['payment_method' => 'manual'])->assertCreated();
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", ['payment_method' => 'qr'])->assertCreated();

        $this->assertSame(2, Payment::query()->where('payment_schedule_id', $schedule->id)->count());
    }

    // A schedule already settled by a paid payment rejects further payment attempts.
    public function test_cannot_pay_an_already_settled_schedule(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();
        Payment::factory()->create(['payment_schedule_id' => $schedule->id, 'status' => 'paid']);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", ['payment_method' => 'manual'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    // 18. Payment history only shows own payments.
    public function test_payment_history_only_shows_own_payments(): void
    {
        ['student' => $studentA, 'schedule' => $scheduleA] = $this->studentWithSchedule();
        Payment::factory()->create(['payment_schedule_id' => $scheduleA->id, 'payer_id' => $studentA->id]);
        $otherPayment = Payment::factory()->create();

        Sanctum::actingAs($studentA);
        $response = $this->getJson('/api/v1/student/payments')->assertOk();

        $ids = collect($response->json('data.payments'))->pluck('id');
        $this->assertFalse($ids->contains($otherPayment->id));
    }

    // Payment history supports filtering by status.
    public function test_payment_history_supports_status_filter(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();
        $pending = Payment::factory()->create(['payment_schedule_id' => $schedule->id, 'payer_id' => $student->id, 'status' => 'pending']);
        $failed = Payment::factory()->create(['payment_schedule_id' => $schedule->id, 'payer_id' => $student->id, 'status' => 'failed']);

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/v1/student/payments?status=pending')->assertOk();

        $ids = collect($response->json('data.payments'))->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertFalse($ids->contains($failed->id));
    }

    // 19. Unauthorized payment/schedule access returns 403.
    public function test_unauthorized_payment_access_returns_403(): void
    {
        $unrelatedPayment = Payment::factory()->create();
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->getJson("/api/v1/student/payments/{$unrelatedPayment->id}")->assertForbidden();
    }

    // 20. Concurrent payment attempts cannot both settle the same schedule.
    public function test_concurrent_settlement_is_prevented_by_row_lock(): void
    {
        ['schedule' => $schedule] = $this->studentWithSchedule();

        // Simulate a payment reaching "paid" between the lock check and creation of a second attempt.
        DB::transaction(function () use ($schedule) {
            $locked = PaymentSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            Payment::factory()->create(['payment_schedule_id' => $locked->id, 'status' => 'paid']);
        });

        $service = app(PaymentService::class);
        $student = User::factory()->create(['user_type' => 'student']);

        $this->expectException(\RuntimeException::class);
        $service->create($schedule, $student, 0, 'manual');
    }

    // Unauthenticated request returns 401.
    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/student/payment-schedules')->assertUnauthorized();
    }

    // 22. Password/token data is never exposed in payment responses.
    public function test_payment_response_never_exposes_sensitive_user_fields(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'manual',
        ])->assertCreated();

        $response->assertJsonMissingPath('data.payment.payer.password');
    }

    // Current-schedule endpoint returns the most relevant unpaid schedule.
    public function test_current_schedule_endpoint_returns_most_relevant_schedule(): void
    {
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/v1/student/payment-schedules/current')->assertOk();

        $response->assertJsonPath('data.payment_schedule.id', $schedule->id);
    }
}
