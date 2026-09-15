<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\SponsorStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassControllerTest extends TestCase
{
    use RefreshDatabase;

    private function organizationAdmin(Organization $organization): User
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function validClassPayload(array $overrides = []): array
    {
        return [
            'name' => 'Quran Class',
            'teacher_name' => 'Cikgu Ahmad',
            'day_of_week' => 1,
            'start_time' => '10:00',
            'recurrence_type' => 'weekly',
            'payment_amount' => 50.00,
            ...$overrides,
        ];
    }

    public function test_organization_admin_can_create_class(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/classes", $this->validClassPayload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Quran Class')
            ->assertJsonPath('data.teacher_name', 'Cikgu Ahmad')
            ->assertJsonPath('data.schedules.0.day_of_week', 1)
            ->assertJsonPath('data.payment_setting.required_amount', '50.00');

        $this->assertDatabaseHas('classes', [
            'name' => 'Quran Class',
            'organization_id' => $organization->id,
            'teacher_name' => 'Cikgu Ahmad',
            'day_of_week' => 1,
            'frequency' => 'weekly',
            'payment_amount' => 50.00,
        ]);

        $class = ClassModel::query()->where('name', 'Quran Class')->firstOrFail();
        $this->assertDatabaseHas('class_schedules', [
            'class_id' => $class->id,
            'day_of_week' => 1,
            'recurrence_type' => 'weekly',
        ]);
        $this->assertDatabaseHas('class_payment_settings', [
            'class_id' => $class->id,
            'required_amount' => 50.00,
            'payment_frequency' => 'weekly',
        ]);
    }

    public function test_create_class_requires_schedule_and_payment_fields(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/classes", ['name' => 'Quran Class'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_name', 'day_of_week', 'start_time', 'recurrence_type', 'payment_amount']);
    }

    public function test_non_organization_admin_cannot_create_class(): void
    {
        $organization = Organization::factory()->create();
        $otherAdmin = User::factory()->create(['user_type' => 'admin']);

        $this->actingAs($otherAdmin, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/classes", $this->validClassPayload())
            ->assertForbidden();
    }

    public function test_enrolled_student_can_view_their_class(): void
    {
        $organization = Organization::factory()->create();
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = User::factory()->create(['user_type' => 'student']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $class->id);
    }

    public function test_unrelated_student_cannot_view_class(): void
    {
        $organization = Organization::factory()->create();
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = User::factory()->create(['user_type' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/classes/{$class->id}")
            ->assertForbidden();
    }

    public function test_student_can_list_their_own_classes(): void
    {
        $organization = Organization::factory()->create();
        $enrolledClass = ClassModel::factory()->create(['organization_id' => $organization->id, 'name' => 'Kelas Quran']);
        $otherClass = ClassModel::factory()->create(['organization_id' => $organization->id, 'name' => 'Kelas Iqra']);
        $student = User::factory()->create(['user_type' => 'student']);

        ClassParticipant::factory()->create([
            'class_id' => $enrolledClass->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        ClassParticipant::factory()->create([
            'class_id' => $otherClass->id,
            'user_id' => $student->id,
            'status' => 'removed',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/classes');

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.classes.0.name', 'Kelas Quran');
    }

    public function test_sponsor_classes_include_sponsored_students_classes(): void
    {
        $organization = Organization::factory()->create();
        $class = ClassModel::factory()->create(['organization_id' => $organization->id, 'name' => 'Kelas Tajwid']);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        $student = User::factory()->create(['user_type' => 'student']);

        SponsorStudent::factory()->create([
            'sponsor_id' => $sponsor->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);
        ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        $this->actingAs($sponsor, 'sanctum')
            ->getJson('/api/v1/sponsor/classes')
            ->assertOk()
            ->assertJsonPath('data.classes.0.name', 'Kelas Tajwid');
    }

    public function test_student_can_list_their_organizations(): void
    {
        $enrolledOrg = Organization::factory()->create(['name' => 'Pusat Tahfiz Al-Amin']);
        $otherOrg = Organization::factory()->create(['name' => 'Madrasah Nurul Iman']);
        $class = ClassModel::factory()->create(['organization_id' => $enrolledOrg->id]);
        $otherClass = ClassModel::factory()->create(['organization_id' => $otherOrg->id]);
        $student = User::factory()->create(['user_type' => 'student']);

        ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        ClassParticipant::factory()->create([
            'class_id' => $otherClass->id,
            'user_id' => $student->id,
            'status' => 'removed',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/organizations')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.organizations.0.name', 'Pusat Tahfiz Al-Amin');
    }

    public function test_sponsor_organizations_include_sponsored_students_organizations(): void
    {
        $organization = Organization::factory()->create(['name' => 'Sekolah Agama Al-Falah']);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        $student = User::factory()->create(['user_type' => 'student']);

        SponsorStudent::factory()->create([
            'sponsor_id' => $sponsor->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);
        ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        $this->actingAs($sponsor, 'sanctum')
            ->getJson('/api/v1/sponsor/organizations')
            ->assertOk()
            ->assertJsonPath('data.organizations.0.name', 'Sekolah Agama Al-Falah');
    }

    public function test_organization_scoped_class_endpoint_rejects_a_class_from_another_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $admin = $this->organizationAdmin($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/organizations/{$organizationA->id}/classes/{$classB->id}")
            ->assertNotFound();
    }

    public function test_organization_admin_can_update_a_class_through_the_organization_scoped_endpoint(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/classes/{$class->id}", [
                'name' => 'Updated Class',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Class')
            ->assertJsonPath('data.organization_id', $organization->id);
    }
}
