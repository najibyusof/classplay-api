<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\PaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassPaymentScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_payment_schedules_include_participant_name_and_phone(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['user_type' => 'admin']);
        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $student = User::factory()->create([
            'user_type' => 'student',
            'name' => 'Siti Zubaidah binti Mokhtar',
            'phone' => '+60123456789',
        ]);
        $participant = ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
        ]);
        PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/classes/{$class->id}/payment-schedules");

        $response->assertOk()
            ->assertJsonPath('data.0.participant.name', 'Siti Zubaidah binti Mokhtar')
            ->assertJsonPath('data.0.participant.phone', '+60123456789');
    }
}
