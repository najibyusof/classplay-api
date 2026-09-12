<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_creating_payment_ignores_client_supplied_total_amount(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $participant->class_id,
            'class_participant_id' => $participant->id,
            'required_amount' => 50,
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'additional_infaq' => 20,
                'payment_method' => 'manual',
                'total_amount' => 999999,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', [
            'payment_schedule_id' => $schedule->id,
            'required_amount' => 50,
            'additional_infaq' => 20,
            'total_amount' => 70,
        ]);
    }

    public function test_other_student_cannot_create_payment_for_someone_elses_schedule(): void
    {
        $participant = ClassParticipant::factory()->create();
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $participant->class_id,
            'class_participant_id' => $participant->id,
        ]);

        $otherStudent = User::factory()->create(['user_type' => 'student']);

        $this->actingAs($otherStudent, 'sanctum')
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'payment_method' => 'manual',
            ])
            ->assertForbidden();
    }

    public function test_organization_admin_can_verify_payment_as_paid(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['user_type' => 'admin']);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
        ]);
        $payment = Payment::factory()->create(['payment_schedule_id' => $schedule->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/payments/{$payment->id}", ['status' => 'paid']);

        $response->assertOk()->assertJsonPath('data.payment.status', 'paid');
        $this->assertNotNull($payment->fresh()->verified_at);
    }
}
