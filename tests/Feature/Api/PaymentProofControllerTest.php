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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentProofControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_payer_can_upload_proof_for_their_own_payment(): void
    {
        Storage::fake('public');

        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $participant->class_id,
            'class_participant_id' => $participant->id,
        ]);
        $payment = Payment::factory()->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/payments/{$payment->id}/proofs", [
                'file' => UploadedFile::fake()->image('proof.jpg'),
            ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        Storage::disk('public')->assertExists($response->json('data.file_path'));
    }

    public function test_approving_proof_marks_payment_as_paid(): void
    {
        Storage::fake('public');

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
        $payment = Payment::factory()->create(['payment_schedule_id' => $schedule->id, 'status' => 'pending']);
        $proof = $payment->proofs()->create([
            'file_path' => 'payment-proofs/test.jpg',
            'submitted_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/payment-proofs/{$proof->id}", ['status' => 'approved']);

        $response->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('paid', $payment->fresh()->status);
    }
}
