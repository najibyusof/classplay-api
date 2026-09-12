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

class SponsorManagementTest extends TestCase
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

    private function enrolledSponsor(ClassModel $class): User
    {
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $sponsor->id,
            'participant_type' => 'sponsor',
            'status' => 'active',
        ]);

        return $sponsor;
    }

    // 11. Admin can create sponsor.
    public function test_admin_can_create_sponsor(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/sponsors', [
            'name' => 'Abdullah Ahmad',
            'phone' => '0129876543',
            'email' => 'abdullah@example.com',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Abdullah Ahmad');
    }

    // 12. Sponsor user_type is assigned server-side.
    public function test_sponsor_user_type_is_assigned_server_side(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/sponsors', [
            'name' => 'Abdullah Ahmad',
            'phone' => '+60129876543',
            'user_type' => 'admin',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '+60129876543', 'user_type' => 'sponsor']);
    }

    // 13. Duplicate sponsor phone is rejected.
    public function test_duplicate_sponsor_phone_is_rejected(): void
    {
        User::factory()->create(['phone' => '+60129876543']);
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/sponsors', ['name' => 'Abdullah Ahmad', 'phone' => '+60129876543'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    // 14. Admin cannot manage an unauthorized sponsor.
    public function test_admin_cannot_manage_unauthorized_sponsor(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->adminOverOrganization($organizationA);
        $classB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);
        $sponsorB = $this->enrolledSponsor($classB);

        Sanctum::actingAs($adminA);
        $this->getJson("/api/v1/admin/sponsors/{$sponsorB->id}")->assertForbidden();
        $this->putJson("/api/v1/admin/sponsors/{$sponsorB->id}", ['name' => 'Hacked'])->assertForbidden();
    }

    // Never expose sensitive fields.
    public function test_sponsor_response_never_exposes_password(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->adminOverOrganization($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $sponsor = $this->enrolledSponsor($class);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/sponsors/{$sponsor->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.password');
    }

    // Student cannot access admin sponsor endpoints.
    public function test_student_cannot_access_admin_sponsor_endpoints(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/admin/sponsors')->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/admin/sponsors')->assertUnauthorized();
    }
}
