<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentManagementTest extends TestCase
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

    private function enrolledStudent(ClassModel $class): User
    {
        $student = User::factory()->create(['user_type' => 'student']);

        ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'participant_type' => 'student',
            'status' => 'active',
        ]);

        return $student;
    }

    // 1. Admin can create student.
    public function test_admin_can_create_student(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/students', [
            'name' => 'Ahmad Ali',
            'phone' => '0123456789',
            'email' => 'ahmad@example.com',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Ahmad Ali');
        $this->assertDatabaseHas('users', [
            'phone' => '+60123456789',
            'user_type' => 'student',
            'status' => 'active',
        ]);
    }

    // 2. Student cannot create another student through the admin API.
    public function test_student_cannot_create_another_student(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->postJson('/api/v1/admin/students', ['name' => 'New Student', 'phone' => '+60123456789'])
            ->assertForbidden();
    }

    // 3. Duplicate phone is rejected.
    public function test_duplicate_student_phone_is_rejected(): void
    {
        User::factory()->create(['phone' => '+60123456789']);
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/students', ['name' => 'Ahmad Ali', 'phone' => '+60123456789'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    // 4. Invalid phone is rejected.
    public function test_invalid_student_phone_is_rejected(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/students', ['name' => 'Ahmad Ali', 'phone' => 'not-a-phone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    // 5. Student user_type is assigned server-side, ignoring any client-supplied value.
    public function test_student_user_type_is_assigned_server_side(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/students', [
            'name' => 'Ahmad Ali',
            'phone' => '+60123456789',
            'user_type' => 'admin',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '+60123456789', 'user_type' => 'student']);
        $this->assertDatabaseMissing('users', ['phone' => '+60123456789', 'user_type' => 'admin']);
    }

    // No password is set — reuses the Phase 6 initial-password state.
    public function test_created_student_has_no_password_set(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/students', ['name' => 'Ahmad Ali', 'phone' => '+60123456789'])
            ->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '+60123456789', 'password' => null]);
    }

    // 6. Admin can view an authorized student.
    public function test_admin_can_view_authorized_student(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = $this->enrolledStudent($class);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonMissingPath('data.password');
    }

    // 7. Admin cannot view a student belonging only to another organization.
    public function test_admin_cannot_view_student_from_unauthorized_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);
        $studentB = $this->enrolledStudent($classB);

        Sanctum::actingAs($adminA);
        $this->getJson("/api/v1/admin/students/{$studentB->id}")->assertForbidden();
    }

    // 8. Admin can update an authorized student.
    public function test_admin_can_update_authorized_student(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = $this->enrolledStudent($class);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/students/{$student->id}", ['name' => 'Renamed Student'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Student');
    }

    // 9. Admin cannot update an unauthorized student.
    public function test_admin_cannot_update_unauthorized_student(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);
        $studentB = $this->enrolledStudent($classB);

        Sanctum::actingAs($adminA);
        $this->putJson("/api/v1/admin/students/{$studentB->id}", ['name' => 'Hacked'])->assertForbidden();
    }

    // 10. Student is deactivated (not hard-deleted) while they still have active participation.
    public function test_deleting_student_with_active_participation_deactivates_and_soft_deletes(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = $this->enrolledStudent($class);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/students/{$student->id}")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Student deleted successfully.', 'data' => null]);

        $this->assertSoftDeleted($student);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('class_participants', ['user_id' => $student->id, 'status' => 'active']);
    }

    // Security: student list never reveals a student only visible to another organization.
    public function test_student_list_only_shows_authorized_students(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classA = ClassModel::factory()->create(['organization_id' => $organizationA->id]);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);
        $studentA = $this->enrolledStudent($classA);
        $studentB = $this->enrolledStudent($classB);

        Sanctum::actingAs($adminA);
        $response = $this->getJson('/api/v1/admin/students')->assertOk();

        $ids = collect($response->json('data.students'))->pluck('id');
        $this->assertTrue($ids->contains($studentA->id));
        $this->assertFalse($ids->contains($studentB->id));
    }

    // 33. Student cannot access admin student endpoints.
    public function test_student_cannot_access_admin_student_endpoints(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/admin/students')->assertForbidden();
    }

    // 34. Sponsor cannot access admin student endpoints.
    public function test_sponsor_cannot_access_admin_student_endpoints(): void
    {
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($sponsor);
        $this->getJson('/api/v1/admin/students')->assertForbidden();
    }

    // 35. Unauthenticated request returns 401.
    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/admin/students')->assertUnauthorized();
    }
}
