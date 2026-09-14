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
            ->assertJsonPath('paths./auth/login.post.responses.200.content.application/json.schema.$ref', '#/components/schemas/AuthResponse')
            ->assertJsonPath('paths./auth/forgot-password.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/ForgotPasswordRequest')
            ->assertJsonPath('paths./auth/forgot-password.post.responses.200.content.application/json.schema.$ref', '#/components/schemas/ApiNullSuccessResponse')
            ->assertJsonPath('paths./auth/reset-password.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/ResetPasswordRequest')
            ->assertJsonPath('paths./auth/reset-password.post.responses.200.content.application/json.schema.$ref', '#/components/schemas/ApiNullSuccessResponse')
            ->assertJsonPath('paths./auth/register/{userType}.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/RegisterRequest')
            ->assertJsonPath('paths./auth/register/{userType}.post.responses.201.content.application/json.schema.$ref', '#/components/schemas/AuthResponse')
            ->assertJsonPath('paths./payment-schedules/{paymentSchedule}/payments.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/StorePaymentRequest')
            ->assertJsonPath('paths./payments/{payment}/proofs.post.requestBody.content.multipart/form-data.schema.$ref', '#/components/schemas/PaymentProofUploadRequest')
            ->assertJsonPath('paths./admin/dashboard.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/ApiSuccessResponse')
            ->assertJsonPath('paths./admin/payments.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/PaginatedResponse')
            ->assertJsonPath('paths./organizations/{organization}/classes/{class}.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/ClassResponse')
            ->assertJsonPath('paths./organizations/{organization}/classes/{class}.patch.requestBody.content.application/json.schema.$ref', '#/components/schemas/UpdateClassRequest')
            ->assertJsonPath('paths./organizations/{organization}/classes/{class}.patch.responses.200.content.application/json.schema.$ref', '#/components/schemas/ClassResponse')
            ->assertJsonPath('paths./organizations/{organization}/classes/{class}/activate.post.responses.200.content.application/json.schema.$ref', '#/components/schemas/ClassResponse')
            ->assertJsonStructure([
                'servers',
                'info',
                'paths',
                'components' => ['securitySchemes'],
            ]);

        $this->assertArrayHasKey('/health', $response->json('paths'));
        $this->assertArrayHasKey('/auth/login', $response->json('paths'));
        $this->assertArrayHasKey('/auth/register/{userType}', $response->json('paths'));
        $this->assertArrayHasKey('/admin/dashboard', $response->json('paths'));
        $this->assertArrayHasKey('/organizations/{organization}/classes/{class}', $response->json('paths'));
        $this->assertArrayHasKey('LoginRequest', $response->json('components.schemas'));
        $this->assertArrayHasKey('ClassResponse', $response->json('components.schemas'));
        $this->assertArrayHasKey('AuthResponse', $response->json('components.schemas'));
        $this->assertArrayHasKey('ResetPasswordRequest', $response->json('components.schemas'));
        $this->assertArrayHasKey('ApiErrorResponse', $response->json('components.schemas'));
        $this->assertArrayHasKey('PaymentWebhookRequest', $response->json('components.schemas'));
        $this->assertTrue($response->json('paths./admin/dashboard.get.security.0.sanctumBearer') === []);
    }
}
