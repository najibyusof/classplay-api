<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\SponsorStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReportApiTest extends TestCase
{
    use RefreshDatabase;

    private function createOrgWithData(string $orgStatus = 'active'): array
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $org = Organization::factory()->create(['status' => $orgStatus]);

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
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        SponsorStudent::factory()->create([
            'sponsor_id' => $sponsor->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);

        $participant = ClassParticipant::factory()->create([
            'class_id' => $class->id,
            'user_id' => $student->id,
            'participant_type' => 'student',
            'status' => 'active',
        ]);

        // Schedule 1: Pending & partially paid
        $schedule1 = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 100.00,
            'due_date' => '2026-09-15',
            'status' => 'pending',
        ]);

        $payment1 = Payment::factory()->create([
            'payment_schedule_id' => $schedule1->id,
            'payer_id' => $student->id,
            'required_amount' => 100.00,
            'additional_infaq' => 10.00,
            'total_amount' => 110.00,
            'currency' => 'MYR',
            'status' => 'paid',
            'payment_method' => 'merchant',
            'paid_at' => Carbon::parse('2026-09-10 10:00:00'),
        ]);

        // Schedule 2: Overdue & unpaid
        $schedule2 = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 50.00,
            'due_date' => '2026-09-01',
            'status' => 'overdue',
        ]);

        return compact('admin', 'org', 'class', 'student', 'sponsor', 'participant', 'schedule1', 'payment1', 'schedule2');
    }

    // 1. Correct payment listing.
    public function test_authorized_admin_can_list_payments(): void
    {
        ['admin' => $admin, 'payment1' => $payment1] = $this->createOrgWithData();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/payments')->assertOk();

        $response->assertJsonPath('success', true);
        $this->assertCount(1, $response->json('data.payments'));
        $this->assertSame($payment1->id, $response->json('data.payments.0.id'));
    }

    // 2. Filters work on payment listing.
    public function test_payment_filters_work(): void
    {
        ['admin' => $admin, 'class' => $class, 'student' => $student, 'payment1' => $payment1] = $this->createOrgWithData();

        Sanctum::actingAs($admin);

        // Matching filter
        $this->getJson("/api/v1/admin/payments?status=paid&payment_method=merchant&class_id={$class->id}&student_id={$student->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.payments');

        // Non-matching filter
        $this->getJson('/api/v1/admin/payments?status=failed')
            ->assertOk()
            ->assertJsonCount(0, 'data.payments');
    }

    // 3. Pagination works.
    public function test_payment_listing_pagination_works(): void
    {
        ['admin' => $admin, 'schedule1' => $schedule, 'student' => $student] = $this->createOrgWithData();

        Payment::factory()->count(5)->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'status' => 'paid',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/payments?per_page=2&page=1')->assertOk();

        $response->assertJsonPath('data.pagination.per_page', 2);
        $response->assertJsonPath('data.pagination.total', 6);
        $this->assertCount(2, $response->json('data.payments'));
    }

    // 4. Organization isolation.
    public function test_admin_cannot_see_payments_from_unauthorized_organizations(): void
    {
        $dataA = $this->createOrgWithData();
        $dataB = $this->createOrgWithData();

        Sanctum::actingAs($dataA['admin']);
        $response = $this->getJson('/api/v1/admin/payments')->assertOk();

        $ids = collect($response->json('data.payments'))->pluck('id');
        $this->assertTrue($ids->contains($dataA['payment1']->id));
        $this->assertFalse($ids->contains($dataB['payment1']->id));
    }

    // 5. Summary calculations work correctly.
    public function test_payment_summary_report_returns_correct_aggregations(): void
    {
        ['admin' => $admin] = $this->createOrgWithData();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/reports/payment-summary')->assertOk();

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.total_payments', 1);
        $response->assertJsonPath('data.successful_payments', 1);
        $response->assertJsonPath('data.pending_payments', 0);
        $response->assertJsonPath('data.total_collected', '110.00');
        $response->assertJsonPath('data.total_overdue', '50.00');
    }

    // 6. Outstanding calculation works correctly.
    public function test_outstanding_report_returns_unpaid_schedules_with_balance(): void
    {
        ['admin' => $admin, 'schedule1' => $s1, 'schedule2' => $s2] = $this->createOrgWithData();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/reports/outstanding')->assertOk();

        // schedule2 is overdue & unpaid -> 50.00 outstanding
        // schedule1 had required_amount 100.00 and paid payment total_amount 110.00 -> 0.00 outstanding
        $this->assertCount(2, $response->json('data.schedules'));

        $schedule2Data = collect($response->json('data.schedules'))->firstWhere('id', $s2->id);
        $this->assertSame('50.00', $schedule2Data['outstanding_amount']);
    }

    // 7. Overdue calculation works correctly with days_overdue.
    public function test_overdue_report_returns_overdue_schedules_with_days_overdue(): void
    {
        ['admin' => $admin, 'schedule2' => $s2] = $this->createOrgWithData();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/reports/overdue')->assertOk();

        $this->assertCount(1, $response->json('data.schedules'));
        $item = $response->json('data.schedules.0');
        $this->assertSame($s2->id, $item['id']);
        $this->assertSame('50.00', $item['outstanding_amount']);
        $this->assertGreaterThan(0, $item['days_overdue']);
    }

    // 8. Date filters work.
    public function test_date_filters_work_on_reports(): void
    {
        ['admin' => $admin] = $this->createOrgWithData();

        Sanctum::actingAs($admin);

        // Date range matching 2026-09-10
        $matching = $this->getJson('/api/v1/admin/reports/payment-summary?from=2026-09-01&to=2026-09-12')->assertOk();
        $matching->assertJsonPath('data.total_collected', '110.00');

        // Date range not matching
        $nonMatching = $this->getJson('/api/v1/admin/reports/payment-summary?from=2026-01-01&to=2026-01-31')->assertOk();
        $nonMatching->assertJsonPath('data.total_collected', '0.00');
    }

    // 9. Unauthorized organization parameter is rejected with 403.
    public function test_unauthorized_organization_parameter_returns_403(): void
    {
        $dataA = $this->createOrgWithData();
        $dataB = $this->createOrgWithData();

        Sanctum::actingAs($dataA['admin']);
        $this->getJson("/api/v1/admin/reports/payment-summary?organization_id={$dataB['org']->id}")->assertForbidden();
    }

    // 10. Empty result handled correctly.
    public function test_empty_organization_returns_valid_zero_summary(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $emptyOrg = Organization::factory()->create(['status' => 'active']);
        OrganizationAdmin::factory()->create([
            'organization_id' => $emptyOrg->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/reports/payment-summary')->assertOk();

        $response->assertJsonPath('data.total_payments', 0);
        $response->assertJsonPath('data.total_collected', '0.00');
        $response->assertJsonPath('data.total_outstanding', '0.00');
    }

    // 11. Efficient queries (avoids N+1).
    public function test_reports_do_not_create_n_plus_1_queries(): void
    {
        ['admin' => $admin] = $this->createOrgWithData();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports/payment-summary')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/reports/payment-summary')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queryCount);
    }

    // 12. Student and sponsor access is forbidden.
    public function test_student_and_sponsor_cannot_access_reports(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/payment-summary')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/outstanding')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/overdue')->assertForbidden();

        Sanctum::actingAs($sponsor);
        $this->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/payment-summary')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/outstanding')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/overdue')->assertForbidden();
    }
}
