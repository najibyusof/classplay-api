<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiVersioningAndRoutesTest extends TestCase
{
    use RefreshDatabase;

    // 1. Verify /api/v1 route prefix is enforced for all API routes.
    public function test_all_api_routes_are_prefixed_with_v1(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $apiRoutes = $routes->filter(fn ($route) => str_starts_with($route->uri(), 'api/'));

        foreach ($apiRoutes as $route) {
            $this->assertStringStartsWith(
                'api/v1',
                $route->uri(),
                "Route URI '{$route->uri()}' is not properly versioned with /api/v1 prefix."
            );
        }
    }

    // 2. Verify all API routes use the 'v1.' name prefix.
    public function test_all_api_route_names_use_v1_prefix(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $apiRoutes = $routes->filter(fn ($route) => str_starts_with($route->uri(), 'api/'));

        foreach ($apiRoutes as $route) {
            $name = $route->getName();
            $this->assertNotNull($name, "Route '{$route->uri()}' is missing a route name.");
            $this->assertStringStartsWith(
                'v1.',
                $name,
                "Route name '{$name}' does not start with 'v1.' prefix."
            );
        }
    }

    // 3. Auth routes versioning and naming.
    public function test_auth_routes_work_under_v1(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123'), 'phone' => '60123456789']);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '60123456789',
            'password' => 'password123',
            'device_name' => 'PHPUnit Test',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // 4. Admin dashboard route versioning and naming.
    public function test_admin_dashboard_route_works_under_v1(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $org = Organization::factory()->create();
        OrganizationAdmin::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    // 5. Student and sponsor routes versioning.
    public function test_participant_routes_work_under_v1(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/student/payment-schedules')->assertOk();
        $this->getJson('/api/v1/student/payments')->assertOk();
    }

    // 6. Public payment webhook route versioning.
    public function test_payment_webhook_route_works_under_v1(): void
    {
        $this->postJson('/api/v1/payment/webhook/merchant', [])
            ->assertStatus(403); // Webhook verifier rejects missing signature header with 403 Forbidden
    }
}
