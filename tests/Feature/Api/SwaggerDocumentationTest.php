<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwaggerDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_swagger_ui_page_is_available(): void
    {
        $response = $this->get('/swagger');

        $response->assertOk()
            ->assertSee('SwaggerUIBundle')
            ->assertSee('swagger\\/openapi.json', false);
    }

    public function test_openapi_spec_is_generated_from_registered_api_routes(): void
    {
        $response = $this->getJson('/swagger/openapi.json');

        $response->assertOk()
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('servers.0.url', 'http://localhost:8000/api/v1')
            ->assertJsonPath('components.securitySchemes.sanctumBearer.type', 'http')
            ->assertJsonPath('paths./auth/login.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/LoginRequest')
            ->assertJsonPath('paths./payment-schedules/{paymentSchedule}/payments.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/StorePaymentRequest')
            ->assertJsonPath('paths./payments/{payment}/proofs.post.requestBody.content.multipart/form-data.schema.$ref', '#/components/schemas/PaymentProofUploadRequest')
            ->assertJsonStructure([
                'servers',
                'info',
                'paths',
                'components' => ['securitySchemes'],
            ]);

        $this->assertArrayHasKey('/health', $response->json('paths'));
        $this->assertArrayHasKey('/auth/login', $response->json('paths'));
        $this->assertArrayHasKey('/admin/dashboard', $response->json('paths'));
        $this->assertArrayHasKey('LoginRequest', $response->json('components.schemas'));
        $this->assertArrayHasKey('PaymentWebhookRequest', $response->json('components.schemas'));
        $this->assertTrue($response->json('paths./admin/dashboard.get.security.0.sanctumBearer') === []);
    }
}
