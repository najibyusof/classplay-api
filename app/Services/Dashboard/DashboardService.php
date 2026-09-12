<?php

namespace App\Services\Dashboard;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\SponsorStudent;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Build dashboard metrics for a list of organization IDs.
     *
     * @param  array<int, int>  $orgIds
     * @return array<string, mixed>
     */
    public function getGlobalDashboard(array $orgIds, ?DateTimeInterface $fromDate = null, ?DateTimeInterface $toDate = null, int $recentLimit = 10): array
    {
        if (empty($orgIds)) {
            return $this->emptyDashboardMetrics($recentLimit);
        }

        $orgStats = $this->getOrganizationStats($orgIds);
        $classStats = $this->getClassStats($orgIds);
        $classIds = ClassModel::query()->whereIn('organization_id', $orgIds)->pluck('id')->all();
        $participantStats = $this->getParticipantStats($classIds);
        $scheduleStats = $this->getPaymentScheduleStats($classIds, $fromDate, $toDate);
        $paymentStats = $this->getPaymentStats($classIds, $fromDate, $toDate);
        $financialStats = $this->getFinancialStats($classIds, $fromDate, $toDate);
        $recentPayments = $this->getRecentPayments($classIds, $fromDate, $toDate, $recentLimit);

        return [
            'organizations' => $orgStats['total'],
            'active_organizations' => $orgStats['active'],
            'classes' => $classStats['total'],
            'active_classes' => $classStats['active'],
            'students' => $participantStats['students'],
            'sponsors' => $participantStats['sponsors'],
            'participants' => $participantStats['total'],
            'payment_schedules' => $scheduleStats,
            'payments' => $paymentStats,
            'financial' => $financialStats,
            'recent_payments' => $recentPayments,
        ];
    }

    /**
     * Build dashboard metrics for a single organization.
     *
     * @return array<string, mixed>
     */
    public function getOrganizationDashboard(Organization $organization, ?DateTimeInterface $fromDate = null, ?DateTimeInterface $toDate = null, int $recentLimit = 10): array
    {
        $orgIds = [$organization->id];
        $classStats = $this->getClassStats($orgIds);
        $classIds = ClassModel::query()->whereIn('organization_id', $orgIds)->pluck('id')->all();
        $participantStats = $this->getParticipantStats($classIds);
        $scheduleStats = $this->getPaymentScheduleStats($classIds, $fromDate, $toDate);
        $paymentStats = $this->getPaymentStats($classIds, $fromDate, $toDate);
        $financialStats = $this->getFinancialStats($classIds, $fromDate, $toDate);
        $recentPayments = $this->getRecentPayments($classIds, $fromDate, $toDate, $recentLimit);

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'code' => $organization->code,
                'status' => $organization->status,
            ],
            'classes' => $classStats,
            'participants' => $participantStats,
            'payment_schedules' => $scheduleStats,
            'payments' => $paymentStats,
            'financial' => $financialStats,
            'recent_payments' => $recentPayments,
        ];
    }

    /**
     * @param  array<int, int>  $orgIds
     * @return array{total: int, active: int}
     */
    private function getOrganizationStats(array $orgIds): array
    {
        $stats = Organization::query()
            ->whereIn('id', $orgIds)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
        ];
    }

    /**
     * @param  array<int, int>  $orgIds
     * @return array{total: int, active: int, inactive: int}
     */
    private function getClassStats(array $orgIds): array
    {
        $stats = ClassModel::query()
            ->whereIn('organization_id', $orgIds)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status != "active" THEN 1 ELSE 0 END) as inactive')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'inactive' => (int) ($stats->inactive ?? 0),
        ];
    }

    /**
     * @param  array<int, int>  $classIds
     * @return array{students: int, sponsors: int, total: int}
     */
    private function getParticipantStats(array $classIds): array
    {
        if (empty($classIds)) {
            return ['students' => 0, 'sponsors' => 0, 'total' => 0];
        }

        $studentIds = ClassParticipant::query()
            ->whereIn('class_id', $classIds)
            ->where('participant_type', 'student')
            ->where('status', 'active')
            ->pluck('user_id')
            ->unique();

        $directSponsorIds = ClassParticipant::query()
            ->whereIn('class_id', $classIds)
            ->where('participant_type', 'sponsor')
            ->where('status', 'active')
            ->pluck('user_id');

        $linkedSponsorIds = SponsorStudent::query()
            ->whereIn('student_id', $studentIds)
            ->where('status', 'active')
            ->pluck('sponsor_id');

        $allSponsorIds = $directSponsorIds->merge($linkedSponsorIds)->unique();

        $allParticipantIds = ClassParticipant::query()
            ->whereIn('class_id', $classIds)
            ->where('status', 'active')
            ->pluck('user_id')
            ->unique();

        return [
            'students' => $studentIds->count(),
            'sponsors' => $allSponsorIds->count(),
            'total' => $allParticipantIds->count(),
        ];
    }

    /**
     * @param  array<int, int>  $classIds
     * @return array{total: int, upcoming: int, pending: int, overdue: int, paid: int}
     */
    private function getPaymentScheduleStats(array $classIds, ?DateTimeInterface $fromDate = null, ?DateTimeInterface $toDate = null): array
    {
        if (empty($classIds)) {
            return ['total' => 0, 'upcoming' => 0, 'pending' => 0, 'overdue' => 0, 'paid' => 0];
        }

        $query = PaymentSchedule::query()->whereIn('class_id', $classIds);

        if ($fromDate) {
            $query->whereDate('due_date', '>=', Carbon::parse($fromDate)->toDateString());
        }

        if ($toDate) {
            $query->whereDate('due_date', '<=', Carbon::parse($toDate)->toDateString());
        }

        $stats = $query->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "upcoming" THEN 1 ELSE 0 END) as upcoming,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = "overdue" THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as paid
        ')->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'upcoming' => (int) ($stats->upcoming ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'overdue' => (int) ($stats->overdue ?? 0),
            'paid' => (int) ($stats->paid ?? 0),
        ];
    }

    /**
     * @param  array<int, int>  $classIds
     * @return array{total: int, paid: int, pending: int, overdue: int, failed: int, refunded: int}
     */
    private function getPaymentStats(array $classIds, ?DateTimeInterface $fromDate = null, ?DateTimeInterface $toDate = null): array
    {
        if (empty($classIds)) {
            return ['total' => 0, 'paid' => 0, 'pending' => 0, 'overdue' => 0, 'failed' => 0, 'refunded' => 0];
        }

        $query = Payment::query()->whereHas('paymentSchedule', fn ($q) => $q->whereIn('class_id', $classIds));

        if ($fromDate) {
            $query->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '>=', Carbon::parse($fromDate)->toDateString());
        }

        if ($toDate) {
            $query->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '<=', Carbon::parse($toDate)->toDateString());
        }

        $stats = $query->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as paid,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = "overdue" THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = "refunded" THEN 1 ELSE 0 END) as refunded
        ')->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'paid' => (int) ($stats->paid ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'overdue' => (int) ($stats->overdue ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'refunded' => (int) ($stats->refunded ?? 0),
        ];
    }

    /**
     * @param  array<int, int>  $classIds
     * @return array{collected: string, outstanding: string, overdue: string}
     */
    private function getFinancialStats(array $classIds, ?DateTimeInterface $fromDate = null, ?DateTimeInterface $toDate = null): array
    {
        if (empty($classIds)) {
            return ['collected' => '0.00', 'outstanding' => '0.00', 'overdue' => '0.00'];
        }

        // Collected: sum total_amount of paid payments
        $collectedQuery = Payment::query()
            ->whereHas('paymentSchedule', fn ($q) => $q->whereIn('class_id', $classIds))
            ->where('status', 'paid');

        if ($fromDate) {
            $collectedQuery->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '>=', Carbon::parse($fromDate)->toDateString());
        }

        if ($toDate) {
            $collectedQuery->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '<=', Carbon::parse($toDate)->toDateString());
        }

        $collected = (float) $collectedQuery->sum('total_amount');

        // Outstanding: non-paid & non-cancelled payment schedules required_amount minus paid payments
        $unpaidSchedulesQuery = PaymentSchedule::query()
            ->whereIn('class_id', $classIds)
            ->whereNotIn('status', ['paid', 'cancelled']);

        if ($fromDate) {
            $unpaidSchedulesQuery->whereDate('due_date', '>=', Carbon::parse($fromDate)->toDateString());
        }

        if ($toDate) {
            $unpaidSchedulesQuery->whereDate('due_date', '<=', Carbon::parse($toDate)->toDateString());
        }

        $unpaidScheduleIds = $unpaidSchedulesQuery->pluck('id')->all();
        $totalRequiredForUnpaid = (float) $unpaidSchedulesQuery->sum('required_amount');

        $paidTotalOnUnpaidSchedules = (float) Payment::query()
            ->whereIn('payment_schedule_id', $unpaidScheduleIds)
            ->where('status', 'paid')
            ->sum('total_amount');

        $outstanding = max(0.00, $totalRequiredForUnpaid - $paidTotalOnUnpaidSchedules);

        // Overdue: overdue payment schedules required_amount minus paid payments
        $overdueSchedulesQuery = PaymentSchedule::query()
            ->whereIn('class_id', $classIds)
            ->where('status', 'overdue');

        if ($fromDate) {
            $overdueSchedulesQuery->whereDate('due_date', '>=', Carbon::parse($fromDate)->toDateString());
        }

        if ($toDate) {
            $overdueSchedulesQuery->whereDate('due_date', '<=', Carbon::parse($toDate)->toDateString());
        }

        $overdueScheduleIds = $overdueSchedulesQuery->pluck('id')->all();
        $totalRequiredForOverdue = (float) $overdueSchedulesQuery->sum('required_amount');

        $paidTotalOnOverdueSchedules = (float) Payment::query()
            ->whereIn('payment_schedule_id', $overdueScheduleIds)
            ->where('status', 'paid')
            ->sum('total_amount');

        $overdue = max(0.00, $totalRequiredForOverdue - $paidTotalOnOverdueSchedules);

        return [
            'collected' => number_format($collected, 2, '.', ''),
            'outstanding' => number_format($outstanding, 2, '.', ''),
            'overdue' => number_format($overdue, 2, '.', ''),
        ];
    }

    /**
     * @param  array<int, int>  $classIds
     * @return array<int, array<string, mixed>>
     */
    private function getRecentPayments(array $classIds, ?DateTimeInterface $fromDate = null, ?DateTimeInterface $toDate = null, int $limit = 10): array
    {
        if (empty($classIds)) {
            return [];
        }

        $limit = max(1, min($limit, 50));

        $query = Payment::query()
            ->whereHas('paymentSchedule', fn ($q) => $q->whereIn('class_id', $classIds))
            ->with(['payer', 'paymentSchedule.classModel']);

        if ($fromDate) {
            $query->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '>=', Carbon::parse($fromDate)->toDateString());
        }

        if ($toDate) {
            $query->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '<=', Carbon::parse($toDate)->toDateString());
        }

        $payments = $query->latest('created_at')->limit($limit)->get();

        return $payments->map(fn (Payment $p) => [
            'id' => $p->id,
            'reference_number' => $p->reference_number,
            'required_amount' => number_format((float) $p->required_amount, 2, '.', ''),
            'additional_infaq' => number_format((float) $p->additional_infaq, 2, '.', ''),
            'total_amount' => number_format((float) $p->total_amount, 2, '.', ''),
            'currency' => $p->currency,
            'status' => $p->status,
            'payment_method' => $p->payment_method,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            'payer' => $p->payer ? [
                'id' => $p->payer->id,
                'name' => $p->payer->name,
                'email' => $p->payer->email,
            ] : null,
            'class' => $p->paymentSchedule?->classModel ? [
                'id' => $p->paymentSchedule->classModel->id,
                'name' => $p->paymentSchedule->classModel->name,
            ] : null,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDashboardMetrics(int $recentLimit = 10): array
    {
        return [
            'organizations' => 0,
            'active_organizations' => 0,
            'classes' => 0,
            'active_classes' => 0,
            'students' => 0,
            'sponsors' => 0,
            'participants' => 0,
            'payment_schedules' => [
                'total' => 0,
                'upcoming' => 0,
                'pending' => 0,
                'overdue' => 0,
                'paid' => 0,
            ],
            'payments' => [
                'total' => 0,
                'paid' => 0,
                'pending' => 0,
                'overdue' => 0,
                'failed' => 0,
                'refunded' => 0,
            ],
            'financial' => [
                'collected' => '0.00',
                'outstanding' => '0.00',
                'overdue' => '0.00',
            ],
            'recent_payments' => [],
        ];
    }
}
