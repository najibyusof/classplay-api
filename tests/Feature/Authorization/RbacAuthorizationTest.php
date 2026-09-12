<?php

namespace Tests\Feature\Authorization;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Role;
use App\Models\SponsorStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function organizationAdmin(Organization $organization, bool $active = true): User
    {
        $admin = User::factory()->create(['user_type' => 'admin']);

        OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => $active ? 'active' : 'inactive',
        ]);

        return $admin;
    }

    // 1. Admin can access assigned organization.
    public function test_admin_can_access_assigned_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/organizations/{$organization->id}")->assertOk();
    }

    // 2. Admin cannot access unassigned organization.
    public function test_admin_cannot_access_unassigned_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->organizationAdmin($organizationA);

        Sanctum::actingAs($adminA);
        $this->getJson("/api/v1/admin/organizations/{$organizationB->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to perform this action.');
    }

    // 3. Admin can access classes in assigned organization.
    public function test_admin_can_access_classes_in_assigned_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization);
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/classes/{$class->id}")->assertOk();
    }

    // 4. Admin cannot access classes in another organization.
    public function test_admin_cannot_access_classes_in_another_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $adminA = $this->organizationAdmin($organizationA);
        $classInB = ClassModel::factory()->create(['organization_id' => $organizationB->id]);

        Sanctum::actingAs($adminA);
        $this->getJson("/api/v1/classes/{$classInB->id}")->assertForbidden();
    }

    // 5. Student can access own data (own payment schedule).
    public function test_student_can_access_own_payment_schedule(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $participant->class_id,
            'class_participant_id' => $participant->id,
        ]);

        Sanctum::actingAs($student);
        $this->getJson("/api/v1/payment-schedules/{$schedule->id}")->assertOk();
    }

    // 6. Student cannot access another student's data.
    public function test_student_cannot_access_another_students_payment_schedule(): void
    {
        $studentA = User::factory()->create(['user_type' => 'student']);
        $participantB = ClassParticipant::factory()->create();
        $scheduleB = PaymentSchedule::factory()->create([
            'class_id' => $participantB->class_id,
            'class_participant_id' => $participantB->id,
        ]);

        Sanctum::actingAs($studentA);
        $this->getJson("/api/v1/payment-schedules/{$scheduleB->id}")->assertForbidden();
    }

    // 6b. Student A cannot access Student B's payment.
    public function test_student_cannot_access_another_students_payment(): void
    {
        $studentA = User::factory()->create(['user_type' => 'student']);
        $paymentB = Payment::factory()->create();

        Sanctum::actingAs($studentA);
        $this->getJson("/api/v1/payments/{$paymentB->id}")->assertForbidden();
    }

    // 7. Sponsor can access own payment data.
    public function test_sponsor_can_access_own_payment(): void
    {
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);
        $payment = Payment::factory()->create(['payer_id' => $sponsor->id]);

        Sanctum::actingAs($sponsor);
        $this->getJson("/api/v1/payments/{$payment->id}")->assertOk();
    }

    // 8. Sponsor cannot access unrelated payment data.
    public function test_sponsor_cannot_access_unrelated_payment(): void
    {
        $sponsorA = User::factory()->create(['user_type' => 'sponsor']);
        $unrelatedPayment = Payment::factory()->create();

        Sanctum::actingAs($sponsorA);
        $this->getJson("/api/v1/payments/{$unrelatedPayment->id}")->assertForbidden();
    }

    // 9. Student cannot create an organization.
    public function test_student_cannot_create_organization(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->postJson('/api/v1/admin/organizations', ['name' => 'Al-Huda Education'])->assertForbidden();
    }

    // 10. Student cannot create a class.
    public function test_student_cannot_create_class(): void
    {
        $organization = Organization::factory()->create();
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/organizations/{$organization->id}/classes", ['name' => 'Quran Class'])
            ->assertForbidden();
    }

    // 11. Student cannot verify a payment.
    public function test_student_cannot_verify_payment(): void
    {
        $organization = Organization::factory()->create();
        $class = ClassModel::factory()->create(['organization_id' => $organization->id]);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
        ]);
        $payment = Payment::factory()->create(['payment_schedule_id' => $schedule->id]);
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->putJson("/api/v1/payments/{$payment->id}", ['status' => 'paid'])->assertForbidden();
    }

    // 12. Sponsor cannot manage organizations.
    public function test_sponsor_cannot_manage_organizations(): void
    {
        $organization = Organization::factory()->create();
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($sponsor);
        $this->postJson('/api/v1/admin/organizations', ['name' => 'Taman Ilmu'])->assertForbidden();
        $this->putJson("/api/v1/admin/organizations/{$organization->id}", ['name' => 'Renamed'])->assertForbidden();
    }

    // 13. Unauthenticated user receives 401.
    public function test_unauthenticated_user_receives_401(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson("/api/v1/admin/organizations/{$organization->id}")->assertUnauthorized();
    }

    // 14. Authenticated unauthorized user receives 403 (student hitting an admin-only RBAC endpoint).
    public function test_authenticated_unauthorized_user_receives_403(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    // 15. Permission checks work correctly independently of organization/role membership.
    public function test_permission_removal_revokes_access_even_with_matching_role_and_org_scope(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization);

        Role::query()->where('name', 'ADMIN')->first()->permissions()->detach();

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/organizations/{$organization->id}")->assertForbidden();
    }

    // 16. Role checks work correctly.
    public function test_role_check_grants_access_to_role_gated_endpoint(): void
    {
        $user = User::factory()->create(['user_type' => 'student']);
        $adminRole = Role::query()->where('name', 'ADMIN')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/roles')->assertOk();
    }

    // 17. Multiple admins assigned to the same organization work correctly.
    public function test_multiple_admins_can_access_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $adminA = $this->organizationAdmin($organization);
        $adminB = $this->organizationAdmin($organization);

        Sanctum::actingAs($adminA);
        $this->getJson("/api/v1/admin/organizations/{$organization->id}")->assertOk();

        Sanctum::actingAs($adminB);
        $this->getJson("/api/v1/admin/organizations/{$organization->id}")->assertOk();
    }

    // 18. Removing an admin's organization assignment removes access.
    public function test_removing_organization_assignment_revokes_access(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->organizationAdmin($organization);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/organizations/{$organization->id}")->assertOk();

        OrganizationAdmin::query()->where('organization_id', $organization->id)
            ->where('user_id', $admin->id)
            ->update(['status' => 'inactive']);

        $this->getJson("/api/v1/admin/organizations/{$organization->id}")->assertForbidden();
    }

    // Security test: sponsor cannot see another sponsor's linked students.
    public function test_sponsor_cannot_access_unrelated_sponsor_student_record(): void
    {
        $sponsorA = User::factory()->create(['user_type' => 'sponsor']);
        $unrelated = SponsorStudent::factory()->create();

        Sanctum::actingAs($sponsorA);
        $this->getJson("/api/v1/admin/sponsors/{$unrelated->sponsor_id}/students")->assertForbidden();
    }

    // Security test: sponsor hitting an admin-only endpoint is forbidden, not merely unauthenticated.
    public function test_sponsor_cannot_access_admin_only_endpoint(): void
    {
        $sponsor = User::factory()->create(['user_type' => 'sponsor']);

        Sanctum::actingAs($sponsor);
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}
