<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use App\Services\Organization\OrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiResponseStandardTest extends TestCase
{
    use RefreshDatabase;

    // 1. Success response structure (200 OK)
    public function test_standard_success_response_structure_for_200(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/organizations')->assertOk();

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'organizations',
                'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
            ],
        ]);
        $this->assertTrue($response->json('success'));
    }

    // 2. Resource created success response structure (201 Created)
    public function test_standard_success_response_structure_for_201(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/organizations', [
            'name' => 'Al-Standard Academy',
            'code' => 'ASA',
        ])->assertCreated();

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['id', 'name', 'code'],
        ]);
        $this->assertTrue($response->json('success'));
    }

    // 3. Validation error response structure (422 Unprocessable Entity)
    public function test_standard_validation_error_response_structure_for_422(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/organizations', [])->assertUnprocessable();

        $response->assertJsonStructure([
            'success',
            'message',
            'errors' => ['name'],
        ]);
        $this->assertFalse($response->json('success'));
        $this->assertSame('The given data was invalid.', $response->json('message'));
    }

    // 4. Unauthenticated error response structure (401 Unauthorized)
    public function test_standard_unauthenticated_error_response_structure_for_401(): void
    {
        $response = $this->getJson('/api/v1/admin/organizations')->assertUnauthorized();

        $response->assertJsonStructure([
            'success',
            'message',
            'errors',
        ]);
        $this->assertFalse($response->json('success'));
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }

    // 5. Unauthorized / Forbidden error response structure (403 Forbidden)
    public function test_standard_unauthorized_error_response_structure_for_403(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/v1/admin/organizations')->assertForbidden();

        $response->assertJsonStructure([
            'success',
            'message',
            'errors',
        ]);
        $this->assertFalse($response->json('success'));
        $this->assertSame('You are not authorized to perform this action.', $response->json('message'));
    }

    // 6. Resource Not Found error response structure (404 Not Found)
    public function test_standard_not_found_error_response_structure_for_404(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/organizations/999999')->assertNotFound();

        $response->assertJsonStructure([
            'success',
            'message',
            'errors',
        ]);
        $this->assertFalse($response->json('success'));
        $this->assertSame('Resource not found.', $response->json('message'));
    }

    // 7. Standardized pagination structure
    public function test_standardized_pagination_response_structure(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $orgs = Organization::factory()->count(3)->create();

        foreach ($orgs as $org) {
            OrganizationAdmin::factory()->create([
                'organization_id' => $org->id,
                'user_id' => $admin->id,
                'status' => 'active',
            ]);
        }

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/organizations?per_page=2&page=1')->assertOk();

        $response->assertJsonPath('data.pagination.current_page', 1);
        $response->assertJsonPath('data.pagination.per_page', 2);
        $response->assertJsonPath('data.pagination.last_page', 2);
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    // 8. Unexpected server error response in production / non-debug mode (500)
    public function test_unexpected_error_does_not_leak_stack_traces_or_debug_info(): void
    {
        config(['app.debug' => false]);

        // Trigger a 500 error on an API route
        $this->app->bind(OrganizationService::class, function () {
            throw new \Exception('Database connection failed or sensitive internal error!');
        });

        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/organizations', [
            'name' => 'Valid Name',
            'code' => 'VAL',
        ])->assertServerError();

        $response->assertJsonStructure([
            'success',
            'message',
            'errors',
        ]);
        $this->assertFalse($response->json('success'));
        $this->assertSame('Server error.', $response->json('message'));
        $this->assertStringNotContainsString('Database connection failed', $response->getContent());
        $this->assertStringNotContainsString('Exception', $response->getContent());
    }
}
