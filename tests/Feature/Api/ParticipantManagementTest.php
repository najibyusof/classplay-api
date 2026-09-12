<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\SponsorStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParticipantManagementTest extends TestCase
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

    // 15. Admin can add student to authorized class.
    public function test_admin_can_add_student_to_authorized_class(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/classes/{$class->id}/participants", [
            'user_id' => $student->id,
            'participant_type' => 'student',
        ])->assertCreated()->assertJsonPath('data.participant_type', 'student');

        $this->assertDatabaseHas('class_participants', [
            'class_id' => $class->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
    }

    // 16. Admin can add sponsor to authorized class.
    public function test_admin_can_add_sponsor_to_authorized_class(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/classes/{$class->id}/participants", [
            'user_id' => $sponsor->id,
            'participant_type' => 'sponsor',
        ])->assertCreated()->assertJsonPath('data.participant_type', 'sponsor');
    }

    // 17. Cannot add a student user as a sponsor participant.
    public function test_cannot_add_student_as_sponsor(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/classes/{$class->id}/participants", [
            'user_id' => $student->id,
            'participant_type' => 'sponsor',
        ])->assertUnprocessable()->assertJsonValidationErrors('participant_type');
    }

    // 18. Cannot add a sponsor user as a student participant.
    public function test_cannot_add_sponsor_as_student(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/classes/{$class->id}/participants", [
            'user_id' => $sponsor->id,
            'participant_type' => 'student',
        ])->assertUnprocessable()->assertJsonValidationErrors('participant_type');
    }

    // 19. Duplicate participant is rejected.
    public function test_duplicate_active_participant_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = User::factory()->create(['user_type' => 'student']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id, 'status' => 'active']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/classes/{$class->id}/participants", [
            'user_id' => $student->id,
            'participant_type' => 'student',
        ])->assertUnprocessable()->assertJsonPath('success', false);
    }

    // 20. Admin cannot add a participant to an unauthorized class.
    public function test_admin_cannot_add_participant_to_unauthorized_class(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($adminA);
        $this->postJson("/api/v1/admin/classes/{$classB->id}/participants", [
            'user_id' => $student->id,
            'participant_type' => 'student',
        ])->assertForbidden();
    }

    // 21. Admin can list class participants.
    public function test_admin_can_list_class_participants(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        ClassParticipant::factory()->create(['class_id' => $class->id]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/classes/{$class->id}/participants")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // 22. Admin can update participant status.
    public function test_admin_can_update_participant_status(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'status' => 'active']);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/classes/{$class->id}/participants/{$participant->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    // Reactivating a participant resets joined_at and clears left_at.
    public function test_reactivating_a_participant_resets_joined_at(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $participant = ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'status' => 'removed',
            'left_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/classes/{$class->id}/participants/{$participant->id}", ['status' => 'active'])
            ->assertOk();

        $participant->refresh();
        $this->assertSame('active', $participant->status);
        $this->assertNull($participant->left_at);
    }

    // 23. Removing participant preserves the historical record (logical removal).
    public function test_removing_participant_preserves_historical_record(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'status' => 'active']);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/classes/{$class->id}/participants/{$participant->id}")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);

        $this->assertDatabaseHas('class_participants', [
            'id' => $participant->id,
            'status' => 'removed',
        ]);
        $this->assertNotNull($participant->fresh()->left_at);
    }

    // 24. Admin can link sponsor to student.
    public function test_admin_can_link_sponsor_to_student(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        $student = User::factory()->create(['user_type' => 'student']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/sponsors/{$sponsor->id}/students", [
            'student_id' => $student->id,
            'relationship_type' => 'parent',
        ])->assertCreated()->assertJsonPath('data.relationship_type', 'parent');
    }

    // 25. Duplicate sponsor/student relationship is rejected.
    public function test_duplicate_sponsor_student_relationship_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        $student = User::factory()->create(['user_type' => 'student']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        SponsorStudent::factory()->create(['sponsor_id' => $sponsor->id, 'student_id' => $student->id]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/sponsors/{$sponsor->id}/students", ['student_id' => $student->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id');
    }

    // 26. Sponsor must be sponsor user_type.
    public function test_sponsor_route_rejects_non_sponsor_user(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $notASponsor = User::factory()->create(['user_type' => 'student']);
        $student = User::factory()->create(['user_type' => 'student']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/sponsors/{$notASponsor->id}/students", ['student_id' => $student->id])
            ->assertNotFound();
    }

    // 27. Student must be student user_type.
    public function test_student_id_must_be_a_student_user(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $sponsor->id, 'participant_type' => 'sponsor']);
        $notAStudent = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/sponsors/{$sponsor->id}/students", ['student_id' => $notAStudent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id');
    }

    // 28 & 29. Admin can remove the relationship, and the historical record is preserved (not deleted).
    public function test_admin_can_remove_relationship_and_history_is_preserved(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        $student = User::factory()->create(['user_type' => 'student']);
        ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        $relationship = SponsorStudent::factory()->create([
            'sponsor_id' => $sponsor->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/sponsors/{$sponsor->id}/students/{$student->id}")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);

        $this->assertDatabaseHas('sponsor_students', [
            'id' => $relationship->id,
            'sponsor_id' => $sponsor->id,
            'student_id' => $student->id,
            'status' => 'inactive',
        ]);
        $this->assertNotNull($relationship->fresh()->end_date);
    }

    // 30. Admin A cannot access students belonging only to Organization B (via participants endpoint).
    public function test_admin_a_cannot_list_participants_in_organization_b_class(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);
        ClassParticipant::factory()->create(['class_id' => $classB->id]);

        Sanctum::actingAs($adminA);
        $this->getJson("/api/v1/admin/classes/{$classB->id}/participants")->assertForbidden();
    }

    // 31. Admin A cannot modify participants in Organization B.
    public function test_admin_a_cannot_modify_participant_in_organization_b(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);
        $participantB = ClassParticipant::factory()->create(['class_id' => $classB->id]);

        Sanctum::actingAs($adminA);
        $this->putJson("/api/v1/admin/classes/{$classB->id}/participants/{$participantB->id}", ['status' => 'inactive'])
            ->assertForbidden();
    }

    // 32. Admin A cannot manage sponsors in Organization B.
    public function test_admin_a_cannot_manage_sponsors_in_organization_b(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);
        $sponsorB = User::factory()->create(['user_type' => 'sponsor']);
        ClassParticipant::factory()->create(['class_id' => $classB->id, 'user_id' => $sponsorB->id, 'participant_type' => 'sponsor']);
        $studentB = User::factory()->create(['user_type' => 'student']);
        ClassParticipant::factory()->create(['class_id' => $classB->id, 'user_id' => $studentB->id]);

        Sanctum::actingAs($adminA);
        $this->postJson("/api/v1/admin/sponsors/{$sponsorB->id}/students", ['student_id' => $studentB->id])
            ->assertForbidden();
    }

    // 33/34. Student and sponsor cannot access admin participant APIs.
    public function test_student_and_sponsor_cannot_access_admin_participant_apis(): void
    {
        $organization = Organization::factory()->create();
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = User::factory()->create(['user_type' => 'student']);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($student);
        $this->getJson("/api/v1/admin/classes/{$class->id}/participants")->assertForbidden();

        Sanctum::actingAs($sponsor);
        $this->getJson("/api/v1/admin/classes/{$class->id}/participants")->assertForbidden();
    }

    // 35/36. Unauthenticated -> 401, unauthorized -> 403.
    public function test_unauthenticated_request_to_participants_returns_401(): void
    {
        $class = ClassModel::factory()->create();

        $this->getJson("/api/v1/admin/classes/{$class->id}/participants")->assertUnauthorized();
    }

    // Explicit multi-class isolation test (Phase 10 spec section 33).
    public function test_admin_sees_student_via_authorized_class_but_not_the_unauthorized_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classA1 = ClassModel::factory()->create(['organization_id' => $organizationA->id]);
        $classB1 = ClassModel::factory()->create(['organization_id' => $organizationB->id]);

        $student = User::factory()->create(['user_type' => 'student']);
        ClassParticipant::factory()->create(['class_id' => $classA1->id, 'user_id' => $student->id]);
        ClassParticipant::factory()->create(['class_id' => $classB1->id, 'user_id' => $student->id]);

        Sanctum::actingAs($adminA);

        // Admin A can see the student because of Class A1 membership.
        $this->getJson("/api/v1/admin/students/{$student->id}")->assertOk();

        // Admin A still cannot reach Organization B or Class B1 directly.
        $this->getJson("/api/v1/admin/organizations/{$organizationB->id}")->assertForbidden();
        $this->getJson("/api/v1/admin/classes/{$classB1->id}/participants")->assertForbidden();
    }
}
