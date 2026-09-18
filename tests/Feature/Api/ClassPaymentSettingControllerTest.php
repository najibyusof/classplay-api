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

    public function test_qr_code_url_is_included_after_upload(): void
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

        $response->assertOk()
            ->assertJsonPath('data.qr_code_url', route('v1.classes.payment-setting.qr-code-file', $class->id));
    }

    public function test_qr_code_url_is_null_when_no_qr_code_uploaded(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['user_type' => 'admin']);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        ClassPaymentSetting::factory()->create(['class_id' => $class->id, 'qr_code_path' => null]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/classes/{$class->id}/payment-setting")
            ->assertOk()
            ->assertJsonPath('data.qr_code_url', null);
    }

    public function test_qr_code_file_streams_without_authentication(): void
    {
        Storage::fake('public');
        $class = ClassModel::factory()->create();
        $path = 'qr-codes/qr.png';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('qr.png')->getContent());
        ClassPaymentSetting::factory()->create(['class_id' => $class->id, 'qr_code_path' => $path]);

        $this->get("/api/v1/classes/{$class->id}/payment-setting/qr-code-file")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_qr_code_file_returns_404_when_no_qr_code_uploaded(): void
    {
        $class = ClassModel::factory()->create();
        ClassPaymentSetting::factory()->create(['class_id' => $class->id, 'qr_code_path' => null]);

        $this->get("/api/v1/classes/{$class->id}/payment-setting/qr-code-file")
            ->assertNotFound();
    }

    public function test_qr_code_file_returns_404_when_no_payment_setting_exists(): void
    {
        $class = ClassModel::factory()->create();

        $this->get("/api/v1/classes/{$class->id}/payment-setting/qr-code-file")
            ->assertNotFound();
    }
}
