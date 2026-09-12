<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function organizationAdmin(Organization $organization, bool $primary = false): User
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'is_primary' => $primary,
            'status' => 'active',
        ]);

        return $admin;
    }

    // 3. User without organization.create permission cannot create one.
    public function test_admin_without_create_permission_cannot_create_organization(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $admin->roles()->first()->permissions()->detach();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/organizations', ['name' => 'Al-Huda Education'])->assertForbidden();
    }

    // 5. Duplicate organization code is rejected.
    public function test_duplicate_organization_code_is_rejected(): void
    {
        Organization::factory()->create(['code' => 'ALHUDA']);
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/organizations', ['name' => 'Another Centre', 'code' => 'ALHUDA'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    // 6. Invalid organization data is rejected.
    public function test_invalid_organization_data_is_rejected(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/organizations', ['status' => 'not-a-status'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'status']);
    }

    // 7 & 8. Admin lists only assigned organizations, never unassigned ones.
    public function test_index_returns_only_organizations_the_admin_administers(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $admin = $this->organizationAdmin($organizationA);
        $this->organizationAdmin($organizationB);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/organizations')->assertOk();

        $names = collect($response->json('data.organizations'))->pluck('id');
        $this->assertTrue($names->contains($organizationA->id));
        $this->assertFalse($names->contains($organizationB->id));
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    // 11. Authorized admin can update organization.
    public function test_authorized_admin_can_update_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization, primary: true);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/organizations/{$organization->id}", ['name' => 'Updated Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    // 12. Unauthorized admin (not assigned to this organization) cannot update it.
    public function test_unauthorized_admin_cannot_update_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherAdmin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($otherAdmin);
        $this->putJson("/api/v1/admin/organizations/{$organization->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    // 13. Non-admin cannot update organization.
    public function test_non_admin_cannot_update_organization(): void
    {
        $organization = Organization::factory()->create();
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->putJson("/api/v1/admin/organizations/{$organization->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    // 14 & 16. Authorized admin can soft-delete; deleted organization disappears from normal queries.
    public function test_authorized_admin_can_soft_delete_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization, primary: true);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/organizations/{$organization->id}")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Organization deleted successfully.', 'data' => null]);

        $this->assertSoftDeleted($organization);
        $this->getJson("/api/v1/admin/organizations/{$organization->id}")->assertNotFound();
    }

    // 15. Unauthorized admin cannot delete organization.
    public function test_unauthorized_admin_cannot_delete_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherAdmin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($otherAdmin);
        $this->deleteJson("/api/v1/admin/organizations/{$organization->id}")->assertForbidden();
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'deleted_at' => null]);
    }

    // Documented future rule: an organization with classes cannot yet be deleted.
    public function test_organization_with_classes_cannot_be_deleted(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization, primary: true);
        ClassModel::factory()->create(['organization_id' => $organization->id]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/organizations/{$organization->id}")
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'deleted_at' => null]);
    }

    // 17. Authorized admin can list organization admins.
    public function test_authorized_admin_can_list_organization_admins(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization, primary: true);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/admin/organizations/{$organization->id}/admins")->assertOk();

        $response->assertJsonPath('data.0.id', $admin->id)
            ->assertJsonPath('data.0.is_primary', true)
            ->assertJsonMissingPath('data.0.password');
    }

    // 18. Authorized admin can add another admin.
    public function test_authorized_admin_can_add_another_admin(): void
    {
        $organization = Organization::factory()->create();
        $primaryAdmin = $this->organizationAdmin($organization, primary: true);
        $newAdmin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($primaryAdmin);
        $this->postJson("/api/v1/admin/organizations/{$organization->id}/admins", ['user_id' => $newAdmin->id])
            ->assertCreated()
            ->assertJsonPath('data.id', $newAdmin->id);

        $this->assertDatabaseHas('organization_admins', [
            'organization_id' => $organization->id,
            'user_id' => $newAdmin->id,
        ]);
    }

    // 19. Cannot add duplicate administrator.
    public function test_cannot_add_duplicate_administrator(): void
    {
        $organization = Organization::factory()->create();
        $primaryAdmin = $this->organizationAdmin($organization, primary: true);

        Sanctum::actingAs($primaryAdmin);
        $this->postJson("/api/v1/admin/organizations/{$organization->id}/admins", ['user_id' => $primaryAdmin->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    // 20. Cannot add a student as organization admin.
    public function test_cannot_add_student_as_organization_admin(): void
    {
        $organization = Organization::factory()->create();
        $primaryAdmin = $this->organizationAdmin($organization, primary: true);
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($primaryAdmin);
        $this->postJson("/api/v1/admin/organizations/{$organization->id}/admins", ['user_id' => $student->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    // 21. Cannot add a sponsor as organization admin.
    public function test_cannot_add_sponsor_as_organization_admin(): void
    {
        $organization = Organization::factory()->create();
        $primaryAdmin = $this->organizationAdmin($organization, primary: true);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($primaryAdmin);
        $this->postJson("/api/v1/admin/organizations/{$organization->id}/admins", ['user_id' => $sponsor->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    // 22. Authorized admin can update organization administrator.
    public function test_authorized_admin_can_update_organization_administrator(): void
    {
        $organization = Organization::factory()->create();
        $primaryAdmin = $this->organizationAdmin($organization, primary: true);
        $otherAdmin = $this->organizationAdmin($organization);

        Sanctum::actingAs($primaryAdmin);
        $this->putJson("/api/v1/admin/organizations/{$organization->id}/admins/{$otherAdmin->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    // 23. Authorized admin can remove administrator.
    public function test_authorized_admin_can_remove_administrator(): void
    {
        $organization = Organization::factory()->create();
        $primaryAdmin = $this->organizationAdmin($organization, primary: true);
        $otherAdmin = $this->organizationAdmin($organization);

        Sanctum::actingAs($primaryAdmin);
        $this->deleteJson("/api/v1/admin/organizations/{$organization->id}/admins/{$otherAdmin->id}")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);

        $this->assertDatabaseMissing('organization_admins', [
            'organization_id' => $organization->id,
            'user_id' => $otherAdmin->id,
        ]);
    }

    // 24. Cannot remove the last administrator.
    public function test_cannot_remove_the_last_administrator(): void
    {
        $organization = Organization::factory()->create();
        $onlyAdmin = $this->organizationAdmin($organization, primary: true);

        Sanctum::actingAs($onlyAdmin);
        $this->deleteJson("/api/v1/admin/organizations/{$organization->id}/admins/{$onlyAdmin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('organization_admins', [
            'organization_id' => $organization->id,
            'user_id' => $onlyAdmin->id,
        ]);
    }

    // 25 & 26. Only one primary administrator can exist; promoting a new one demotes the previous.
    public function test_promoting_a_new_primary_administrator_demotes_the_previous_one(): void
    {
        $organization = Organization::factory()->create();
        $primaryAdmin = $this->organizationAdmin($organization, primary: true);
        $otherAdmin = $this->organizationAdmin($organization);

        Sanctum::actingAs($primaryAdmin);
        $this->putJson("/api/v1/admin/organizations/{$organization->id}/admins/{$otherAdmin->id}", ['is_primary' => true])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $this->assertDatabaseHas('organization_admins', [
            'organization_id' => $organization->id,
            'user_id' => $otherAdmin->id,
            'is_primary' => 1,
        ]);
        $this->assertDatabaseHas('organization_admins', [
            'organization_id' => $organization->id,
            'user_id' => $primaryAdmin->id,
            'is_primary' => 0,
        ]);
    }

    // Cannot remove the primary administrator while they are the only active admin left.
    public function test_removing_the_primary_administrator_requires_another_admin_to_exist(): void
    {
        $organization = Organization::factory()->create();
        $primaryAdmin = $this->organizationAdmin($organization, primary: true);

        Sanctum::actingAs($primaryAdmin);
        $this->deleteJson("/api/v1/admin/organizations/{$organization->id}/admins/{$primaryAdmin->id}")
            ->assertUnprocessable();
    }

    // 30. Student cannot access admin organization endpoints.
    public function test_student_cannot_access_admin_organization_endpoints(): void
    {
        $organization = Organization::factory()->create();
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/admin/organizations')->assertForbidden();
        $this->getJson("/api/v1/admin/organizations/{$organization->id}/admins")->assertForbidden();
    }

    // 31. Sponsor cannot access admin organization endpoints.
    public function test_sponsor_cannot_access_admin_organization_endpoints(): void
    {
        $organization = Organization::factory()->create();
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($sponsor);
        $this->getJson('/api/v1/admin/organizations')->assertForbidden();
        $this->getJson("/api/v1/admin/organizations/{$organization->id}/admins")->assertForbidden();
    }

    // 34. Organization data does not leak through search/filter for an unrelated admin.
    public function test_search_never_reveals_unassigned_organizations(): void
    {
        $organizationA = Organization::factory()->create(['name' => 'Al-Huda Education', 'code' => 'ALHUDA']);
        $organizationB = Organization::factory()->create(['name' => 'Al-Huda Secondary', 'code' => 'ALHUDA2']);
        $adminA = $this->organizationAdmin($organizationA);
        $this->organizationAdmin($organizationB);

        Sanctum::actingAs($adminA);
        $response = $this->getJson('/api/v1/admin/organizations?search=Al-Huda')->assertOk();

        $ids = collect($response->json('data.organizations'))->pluck('id');
        $this->assertTrue($ids->contains($organizationA->id));
        $this->assertFalse($ids->contains($organizationB->id));
    }

    // 35. Sensitive user fields are never returned in the organization admin list.
    public function test_organization_admin_list_never_exposes_sensitive_fields(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization, primary: true);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/admin/organizations/{$organization->id}/admins")->assertOk();

        $response->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.tokens');
    }
}
