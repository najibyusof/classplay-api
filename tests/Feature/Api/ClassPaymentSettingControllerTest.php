<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassPaymentSetting;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClassPaymentSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_upload_qr_code(): void
    {
        Storage::fake('public');

        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['user_type' => 'admin']);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        ClassPaymentSetting::factory()->create(['class_id' => $class->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/classes/{$class->id}/payment-setting/qr-code", [
                'qr_code' => UploadedFile::fake()->image('qr.png'),
            ]);

        $response->assertOk();
        Storage::disk('public')->assertExists($response->json('data.qr_code_path'));
    }
}
