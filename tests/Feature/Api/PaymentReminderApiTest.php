<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\PaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReminderApiTest extends TestCase
{
    use RefreshDatabase;

    private function createFixture(): array
    {
        $organization = Organization::factory()->create();
        $class = ClassModel::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $setting = ClassPaymentSetting::factory()->create([
            'class_id' => $class->id,
            'required_amount' => 100.00,
            'reminder_enabled' => true,
        ]);
        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 100.00,
            'due_date' => now()->addDays(5),
            'status' => 'pending',
        ]);

        return compact('organization', 'class', 'setting', 'student', 'participant', 'schedule');
    }

    private function createOrgAdmin(Organization $organization): User
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
        ]);

        return $admin;
    }

    // 17. Admin manual reminder respects authorization.
    public function test_authorized_org_admin_can_trigger_manual_reminder(): void
    {
        ['organization' => $org, 'schedule' => $schedule, 'student' => $student] = $this->createFixture();
        $admin = $this->createOrgAdmin($org);

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/v1/payment-schedules/{$schedule->id}/reminder")->assertOk();

        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'related_id' => $schedule->id,
        ]);
    }

    // 18. Student cannot manually trigger admin reminder API.
    public function test_student_cannot_trigger_manual_reminder(): void
    {
        ['schedule' => $schedule, 'student' => $student] = $this->createFixture();

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/payment-schedules/{$schedule->id}/reminder")->assertForbidden();
    }

    // Admin from another organization cannot trigger manual reminder.
    public function test_admin_from_another_organization_cannot_trigger_reminder(): void
    {
        ['schedule' => $schedule] = $this->createFixture();
        $otherOrg = Organization::factory()->create();
        $otherAdmin = $this->createOrgAdmin($otherOrg);

        Sanctum::actingAs($otherAdmin);
        $this->postJson("/api/v1/payment-schedules/{$schedule->id}/reminder")->assertForbidden();
    }

    // Admin can preview eligible reminders.
    public function test_org_admin_can_preview_eligible_reminders(): void
    {
        ['organization' => $org] = $this->createFixture();
        $admin = $this->createOrgAdmin($org);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/payment-schedules/reminders/preview')->assertOk();

        $response->assertJsonPath('success', true);
        $this->assertArrayHasKey('eligible_count', $response->json('data'));
    }
}
