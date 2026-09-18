<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_admin_can_upload_organization_logo(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['user_type' => 'admin']);
        $organization = Organization::factory()->create(['created_by' => $admin->id]);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/organizations/{$organization->id}/logo", [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Organization logo uploaded successfully.')
            ->assertJsonPath('data.logo_path', $organization->fresh()->logo_path);

        Storage::disk('public')->assertExists($organization->fresh()->logo_path);
        $this->assertDatabaseMissing('organizations', ['id' => $organization->id, 'logo_path' => null]);
    }

    public function test_uploading_a_new_organization_logo_removes_the_previous_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['user_type' => 'admin']);
        $oldPath = 'organization-logos/old-logo.png';
        Storage::disk('public')->put($oldPath, 'old logo');
        $organization = Organization::factory()->create([
            'created_by' => $admin->id,
            'logo_path' => $oldPath,
        ]);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/organizations/{$organization->id}/logo", [
                'logo' => UploadedFile::fake()->image('new-logo.jpg'),
            ])
            ->assertOk();

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($organization->fresh()->logo_path);
    }

    public function test_non_admin_cannot_upload_an_organization_logo(): void
    {
        Storage::fake('public');
        $organization = Organization::factory()->create();
        $otherAdmin = User::factory()->create(['user_type' => 'admin']);

        $this->actingAs($otherAdmin, 'sanctum')
            ->postJson("/api/v1/admin/organizations/{$organization->id}/logo", [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertForbidden();
    }

    public function test_organization_logo_upload_requires_an_image(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $organization = Organization::factory()->create(['created_by' => $admin->id]);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/organizations/{$organization->id}/logo", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['logo']);
    }

    public function test_admin_can_get_organization_logo_url(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['user_type' => 'admin']);
        $organization = Organization::factory()->create([
            'created_by' => $admin->id,
            'logo_path' => 'organization-logos/logo.png',
        ]);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/organizations/{$organization->id}/logo")
            ->assertOk()
            ->assertJsonPath('data.logo_url', route('v1.organizations.logo-file', $organization));
    }

    public function test_organization_logo_url_is_null_when_no_logo_uploaded(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $organization = Organization::factory()->create(['created_by' => $admin->id, 'logo_path' => null]);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/organizations/{$organization->id}/logo")
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);
    }

    public function test_non_admin_cannot_get_organization_logo_url(): void
    {
        $organization = Organization::factory()->create(['logo_path' => 'organization-logos/logo.png']);
        $otherAdmin = User::factory()->create(['user_type' => 'admin']);

        $this->actingAs($otherAdmin, 'sanctum')
            ->getJson("/api/v1/admin/organizations/{$organization->id}/logo")
            ->assertForbidden();
    }

    public function test_logo_file_streams_without_authentication(): void
    {
        Storage::fake('public');
        $path = 'organization-logos/logo.png';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('logo.png')->getContent());
        $organization = Organization::factory()->create(['logo_path' => $path]);

        $this->get("/api/v1/organizations/{$organization->id}/logo-file")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_logo_file_returns_404_when_no_logo_uploaded(): void
    {
        $organization = Organization::factory()->create(['logo_path' => null]);

        $this->get("/api/v1/organizations/{$organization->id}/logo-file")
            ->assertNotFound();
    }

    public function test_logo_file_returns_404_when_stored_file_is_missing(): void
    {
        Storage::fake('public');
        $organization = Organization::factory()->create(['logo_path' => 'organization-logos/missing.png']);

        $this->get("/api/v1/organizations/{$organization->id}/logo-file")
            ->assertNotFound();
    }
}
