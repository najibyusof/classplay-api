<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private function createOrgWithData(string $status = 'active'): array
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $org = Organization::factory()->create(['status' => $status]);
        OrganizationAdmin::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        $class = ClassModel::factory()->create([
            'organization_id' => $org->id,
            'status' => 'active',
        ]);

        ClassPaymentSetting::factory()->create([
            'class_id' => $class->id,
            'required_amount' => 100.00,
        ]);

        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'participant_type' => 'student',
            'status' => 'active',
        ]);

        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 100.00,
            'due_date' => '2026-09-15',
            'status' => 'pending',
        ]);

        $payment = Payment::factory()->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'required_amount' => 100.00,
            'total_amount' => 100.00,
            'status' => 'paid',
            'paid_at' => Carbon::parse('2026-09-10 10:00:00'),
        ]);

        return compact('admin', 'org', 'class', 'student', 'participant', 'schedule', 'payment');
    }

    // 1. Admin can access dashboard.
    public function test_authorized_admin_can_access_global_dashboard(): void
    {
        ['admin' => $admin] = $this->createOrgWithData();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();

        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'organizations',
                'active_organizations',
                'classes',
                'active_classes',
                'students',
                'sponsors',
                'participants',
                'payment_schedules',
                'payments',
                'financial' => ['collected', 'outstanding', 'overdue'],
                'recent_payments',
            ],
        ]);
    }

    // 2. Student cannot access dashboard.
    public function test_student_cannot_access_dashboard(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    // 3. Sponsor cannot access dashboard.
    public function test_sponsor_cannot_access_dashboard(): void
    {
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($sponsor);
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    // 4. Admin sees only authorized organizations.
    public function test_admin_sees_only_authorized_organizations_in_global_dashboard(): void
    {
        $dataA = $this->createOrgWithData();
        $dataB = $this->createOrgWithData();

        Sanctum::actingAs($dataA['admin']);
        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();

        // Admin A sees 1 organization, 1 class, 1 student
        $response->assertJsonPath('data.organizations', 1);
        $response->assertJsonPath('data.classes', 1);
        $response->assertJsonPath('data.students', 1);
    }

    // 5. Organization dashboard respects organization authorization.
    public function test_organization_dashboard_respects_authorization(): void
    {
        $dataA = $this->createOrgWithData();
        $dataB = $this->createOrgWithData();

        // Admin A can access Org A dashboard
        Sanctum::actingAs($dataA['admin']);
        $this->getJson("/api/v1/admin/organizations/{$dataA['org']->id}/dashboard")->assertOk();

        // Admin A CANNOT access Org B dashboard
        $this->getJson("/api/v1/admin/organizations/{$dataB['org']->id}/dashboard")->assertForbidden();
    }

    // 6 & 7. Statistics and payment totals are correct.
    public function test_statistics_and_financial_totals_are_correct(): void
    {
        ['admin' => $admin, 'org' => $org, 'class' => $class, 'student' => $student] = $this->createOrgWithData();

        // Add a second schedule that is overdue and unpaid
        $participant = ClassParticipant::query()->where('class_id', $class->id)->first();
        PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 50.00,
            'due_date' => '2026-09-01',
            'status' => 'overdue',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/admin/organizations/{$org->id}/dashboard")->assertOk();

        $response->assertJsonPath('data.organization.id', $org->id);
        $response->assertJsonPath('data.classes.total', 1);
        $response->assertJsonPath('data.classes.active', 1);
        $response->assertJsonPath('data.participants.students', 1);
        $response->assertJsonPath('data.payment_schedules.total', 2);
        $response->assertJsonPath('data.payment_schedules.overdue', 1);
        $response->assertJsonPath('data.financial.collected', '100.00');
        $response->assertJsonPath('data.financial.overdue', '50.00');
    }

    // 8. Date filters work.
    public function test_date_filters_work_on_dashboard(): void
    {
        ['admin' => $admin, 'org' => $org] = $this->createOrgWithData();

        Sanctum::actingAs($admin);

        // Date filter matching the payment (2026-09-10)
        $matching = $this->getJson("/api/v1/admin/organizations/{$org->id}/dashboard?from=2026-09-01&to=2026-09-11")->assertOk();
        $matching->assertJsonPath('data.financial.collected', '100.00');

        // Date filter not matching the payment (2026-01-01 to 2026-01-31)
        $nonMatching = $this->getJson("/api/v1/admin/organizations/{$org->id}/dashboard?from=2026-01-01&to=2026-01-31")->assertOk();
        $nonMatching->assertJsonPath('data.financial.collected', '0.00');
    }

    // 9. Unauthorized organization data never appears in metrics or recent payments.
    public function test_unauthorized_organization_data_never_appears(): void
    {
        $dataA = $this->createOrgWithData();
        $dataB = $this->createOrgWithData();

        Sanctum::actingAs($dataA['admin']);
        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();

        $recentPaymentIds = collect($response->json('data.recent_payments'))->pluck('id');
        $this->assertTrue($recentPaymentIds->contains($dataA['payment']->id));
        $this->assertFalse($recentPaymentIds->contains($dataB['payment']->id));
    }

    // 10. Empty organization produces valid zero statistics.
    public function test_empty_organization_produces_valid_zero_statistics(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $emptyOrg = Organization::factory()->create(['status' => 'active']);
        OrganizationAdmin::factory()->create([
            'organization_id' => $emptyOrg->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/admin/organizations/{$emptyOrg->id}/dashboard")->assertOk();

        $response->assertJsonPath('data.classes.total', 0);
        $response->assertJsonPath('data.participants.total', 0);
        $response->assertJsonPath('data.financial.collected', '0.00');
        $response->assertJsonPath('data.financial.outstanding', '0.00');
        $response->assertJsonPath('data.financial.overdue', '0.00');
        $response->assertJsonPath('data.recent_payments', []);
    }

    // 11. Dashboard avoids N+1 queries.
    public function test_dashboard_does_not_create_n_plus_1_queries(): void
    {
        ['admin' => $admin] = $this->createOrgWithData();

        Sanctum::actingAs($admin);

        // Warm up authentication & Sanctum user resolution
        $this->getJson('/api/v1/admin/dashboard')->assertOk();

        // Count DB queries for actual dashboard calculation
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/dashboard')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Ensure fixed, low query count (< 25 queries total, no loop queries per item)
        $this->assertLessThan(25, $queryCount);
    }

    // 12. Recent payment limit works.
    public function test_recent_payment_limit_works(): void
    {
        ['admin' => $admin, 'org' => $org, 'class' => $class, 'student' => $student, 'schedule' => $schedule] = $this->createOrgWithData();

        // Create 15 payments
        Payment::factory()->count(14)->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'total_amount' => 10.00,
            'status' => 'paid',
        ]);

        Sanctum::actingAs($admin);

        // Default limit (10)
        $responseDefault = $this->getJson("/api/v1/admin/organizations/{$org->id}/dashboard")->assertOk();
        $this->assertCount(10, $responseDefault->json('data.recent_payments'));

        // Custom limit (5)
        $responseCustom = $this->getJson("/api/v1/admin/organizations/{$org->id}/dashboard?recent_limit=5")->assertOk();
        $this->assertCount(5, $responseCustom->json('data.recent_payments'));
    }
}
