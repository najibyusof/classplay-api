<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_organizations(): void
    {
        $this->getJson('/api/v1/admin/organizations')->assertUnauthorized();
    }

    public function test_admin_can_create_organization_and_becomes_primary_admin(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/organizations', ['name' => 'Al-Huda Education']);

        $response->assertCreated()->assertJsonPath('data.name', 'Al-Huda Education');

        $this->assertDatabaseHas('organization_admins', [
            'organization_id' => $response->json('data.id'),
            'user_id' => $admin->id,
            'is_primary' => 1,
        ]);
    }

    public function test_student_cannot_create_organization(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/admin/organizations', ['name' => 'Al-Huda Education'])
            ->assertForbidden();
    }

    public function test_admin_cannot_view_organization_they_do_not_administer(): void
    {
        $organization = Organization::factory()->create();
        $otherAdmin = User::factory()->create(['user_type' => 'admin']);

        $this->actingAs($otherAdmin, 'sanctum')
            ->getJson("/api/v1/admin/organizations/{$organization->id}")
            ->assertForbidden();
    }

    public function test_admin_can_view_organization_they_administer(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $organization = Organization::factory()->create(['created_by' => $admin->id]);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id);
    }
}
