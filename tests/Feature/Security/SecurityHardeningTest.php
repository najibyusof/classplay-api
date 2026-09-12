<?php

namespace Tests\Feature\Security;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function createOrgWithAdmin(): array
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $org = Organization::factory()->create(['status' => 'active']);
        OrganizationAdmin::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        return compact('admin', 'org');
    }

    // 1. IDOR & Organization isolation: Admin A cannot access Organization B's data
    public function test_admin_cannot_access_unauthorized_organization_data(): void
    {
        ['admin' => $adminA, 'org' => $orgA] = $this->createOrgWithAdmin();
        ['org' => $orgB] = $this->createOrgWithAdmin();

        Sanctum::actingAs($adminA);

        $this->getJson("/api/v1/admin/organizations/{$orgB->id}")->assertForbidden();
        $this->getJson("/api/v1/admin/organizations/{$orgB->id}/dashboard")->assertForbidden();
        $this->getJson("/api/v1/admin/reports/payment-summary?organization_id={$orgB->id}")->assertForbidden();
    }

    // 2. Student isolation: Student A cannot view Student B's payment schedules or payments
    public function test_student_cannot_view_another_students_schedules_or_payments(): void
    {
        $studentA = User::factory()->create(['user_type' => 'student']);
        $studentB = User::factory()->create(['user_type' => 'student']);

        $class = ClassModel::factory()->create();
        $participantB = ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $studentB->id]);
        $scheduleB = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participantB->id,
        ]);
        $paymentB = Payment::factory()->create([
            'payment_schedule_id' => $scheduleB->id,
            'payer_id' => $studentB->id,
        ]);

        Sanctum::actingAs($studentA);

        $this->getJson("/api/v1/student/payment-schedules/{$scheduleB->id}")->assertForbidden();
        $this->getJson("/api/v1/student/payments/{$paymentB->id}")->assertForbidden();
        $this->postJson("/api/v1/student/payments/{$paymentB->id}/initiate")->assertForbidden();
    }

    // 3. Sponsor isolation: Sponsor cannot access schedules of un-sponsored students
    public function test_sponsor_cannot_view_unrelated_students_data(): void
    {
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        $student = User::factory()->create(['user_type' => 'student']);

        $class = ClassModel::factory()->create();
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
        ]);

        Sanctum::actingAs($sponsor);

        $this->getJson("/api/v1/sponsor/payment-schedules/{$schedule->id}")->assertForbidden();
    }

    // 4. Privilege escalation: Students and sponsors cannot call admin endpoints
    public function test_students_and_sponsors_cannot_access_admin_endpoints(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        foreach ([$student, $sponsor] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
            $this->getJson('/api/v1/admin/organizations')->assertForbidden();
            $this->getJson('/api/v1/admin/students')->assertForbidden();
            $this->getJson('/api/v1/admin/sponsors')->assertForbidden();
            $this->getJson('/api/v1/admin/payments')->assertForbidden();
            $this->getJson('/api/v1/admin/reports/payment-summary')->assertForbidden();
        }
    }

    // 5. Mass assignment protection: Client cannot manipulate user_type, verified_by, or status
    public function test_mass_assignment_fields_cannot_be_injected(): void
    {
        ['admin' => $admin, 'org' => $org] = $this->createOrgWithAdmin();

        Sanctum::actingAs($admin);

        // Attempting to create a student while setting user_type = admin
        $response = $this->postJson('/api/v1/admin/students', [
            'name' => 'Ahmad Student',
            'phone' => '60111111111',
            'user_type' => 'admin',
        ])->assertCreated();

        $this->assertSame('student', $response->json('data.user_type'));
    }

    // 6. Payment tampering protection: Client cannot override total_amount or set payment status to paid
    public function test_client_cannot_tamper_payment_amounts_or_status(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);
        $class = ClassModel::factory()->create(['status' => 'active']);
        ClassPaymentSetting::factory()->create(['class_id' => $class->id, 'required_amount' => 100.00]);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 100.00,
        ]);

        Sanctum::actingAs($student);

        // Attempting to send total_amount = 0.01 and status = paid
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'manual',
            'total_amount' => 0.01,
            'status' => 'paid',
            'paid_at' => now()->toDateString(),
        ])->assertCreated();

        $paymentId = $response->json('data.payment.id');
        $payment = Payment::findOrFail($paymentId);

        $this->assertEquals(100.00, (float) $payment->total_amount);
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->paid_at);
    }

    // 7. Webhook spoofing protection: Request without signature is rejected with 403
    public function test_webhook_without_signature_is_rejected(): void
    {
        config(['services.payment_webhook.secret' => 'test-secret']);

        $this->postJson('/api/v1/payment/webhook/merchant', [
            'gateway_reference' => 'GW-123',
            'status' => 'paid',
        ])->assertForbidden();
    }

    // 8. File upload security: Executable or non-image files are rejected
    public function test_file_upload_rejects_non_image_or_executable_files(): void
    {
        Storage::fake('public');
        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create(['class_id' => $participant->class_id, 'class_participant_id' => $participant->id]);
        $payment = Payment::factory()->create(['payment_schedule_id' => $schedule->id, 'payer_id' => $student->id]);

        Sanctum::actingAs($student);

        // Attempting to upload a PHP script
        $file = UploadedFile::fake()->create('malicious.php', 10, 'text/x-php');
        $this->postJson("/api/v1/payments/{$payment->id}/proofs", ['file' => $file])
            ->assertUnprocessable();
    }

    // 9. Revoked or invalid token cannot access protected endpoints
    public function test_revoked_or_invalid_sanctum_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('TestDevice');
        $plainToken = $token->plainTextToken;

        // Revoke the token
        $token->accessToken->delete();

        $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
            ->getJson('/api/v1/notifications')
            ->assertUnauthorized();
    }

    // 10. Device registration cannot assign device to another user via token manipulation
    public function test_user_device_cannot_be_manipulated_for_another_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $deviceB = UserDevice::factory()->create(['user_id' => $userB->id]);

        Sanctum::actingAs($userA);

        $this->deleteJson("/api/v1/devices/{$deviceB->id}")->assertForbidden();
        $this->putJson("/api/v1/devices/{$deviceB->id}", ['device_name' => 'Hacked'])->assertForbidden();
    }
}
