<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use App\Services\Payment\PaymentScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ClassActivationTest extends TestCase
{
    use RefreshDatabase;

    private function adminOverOrganization(Organization $organization): User
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        return $admin;
    }

    // 18. Class activation triggers schedule generation correctly.
    public function test_activating_a_class_generates_payment_schedules(): void
    {
        $this->travelTo(now()->startOfDay());

        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id, 'status' => 'draft']);
        ClassPaymentSetting::factory()->create(['class_id' => $class->id]);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'participant_type' => 'student', 'status' => 'active']);

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/v1/admin/classes/{$class->id}/activate");

        $response->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('classes', ['id' => $class->id, 'status' => 'active']);
        $this->assertDatabaseHas('payment_schedules', ['class_id' => $class->id]);
    }

    // 16. Admin cannot generate schedules for an unauthorized organization.
    public function test_admin_cannot_activate_class_in_unauthorized_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id, 'status' => 'draft']);
        ClassPaymentSetting::factory()->create(['class_id' => $classB->id]);
        ClassParticipant::factory()->create(['class_id' => $classB->id, 'status' => 'active']);

        Sanctum::actingAs($adminA);
        $this->postJson("/api/v1/admin/classes/{$classB->id}/activate")->assertForbidden();

        $this->assertDatabaseHas('classes', ['id' => $classB->id, 'status' => 'draft']);
        $this->assertDatabaseMissing('payment_schedules', ['class_id' => $classB->id]);
    }

    // 19. Transaction rollback: if schedule generation fails, the class status change is rolled back too.
    public function test_activation_rolls_back_when_schedule_generation_fails(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id, 'status' => 'draft']);
        ClassPaymentSetting::factory()->create(['class_id' => $class->id]);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'participant_type' => 'student', 'status' => 'active']);

        $this->app->bind(PaymentScheduleService::class, function () {
            return new class extends PaymentScheduleService
            {
                public function generateForClass(ClassModel $class, ?int $horizonPeriods = null): Collection
                {
                    throw new RuntimeException('Simulated schedule generation failure.');
                }
            };
        });

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/classes/{$class->id}/activate")->assertServerError();

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'status' => 'draft']);
        $this->assertDatabaseMissing('payment_schedules', ['class_id' => $class->id]);
    }
}
