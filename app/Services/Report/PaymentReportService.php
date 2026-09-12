<?php

namespace App\Services\Report;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentReportService
{
    /**
     * Get paginated payment records filtered by organization and other parameters.
     *
     * @param  array<int, int>  $orgIds
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Payment>
     */
    public function getPayments(array $orgIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        if (empty($orgIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $query = Payment::query()
            ->whereHas('paymentSchedule.classModel', fn ($q) => $q->whereIn('organization_id', $orgIds))
            ->with([
                'payer',
                'paymentSchedule.classModel.organization',
                'paymentSchedule.classParticipant.user',
            ]);

        if (! empty($filters['class_id'])) {
            $query->whereHas('paymentSchedule', fn ($q) => $q->where('class_id', (int) $filters['class_id']));
        }

        if (! empty($filters['participant_id'])) {
            $query->whereHas('paymentSchedule', fn ($q) => $q->where('class_participant_id', (int) $filters['participant_id']));
        }

        if (! empty($filters['student_id'])) {
            $query->whereHas('paymentSchedule.classParticipant', fn ($q) => $q->where('user_id', (int) $filters['student_id']));
        }

        if (! empty($filters['sponsor_id'])) {
            $query->where('payer_id', (int) $filters['sponsor_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', (string) $filters['payment_method']);
        }

        if (! empty($filters['from'])) {
            $fromDate = Carbon::parse($filters['from'])->toDateString();
            $query->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '>=', $fromDate);
        }

        if (! empty($filters['to'])) {
            $toDate = Carbon::parse($filters['to'])->toDateString();
            $query->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '<=', $toDate);
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    /**
     * Database-aggregated payment summary report.
     *
     * @param  array<int, int>  $orgIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getPaymentSummary(array $orgIds, array $filters = []): array
    {
        if (empty($orgIds)) {
            return $this->emptyPaymentSummary();
        }

        $paymentQuery = Payment::query()
            ->whereHas('paymentSchedule.classModel', fn ($q) => $q->whereIn('organization_id', $orgIds));

        if (! empty($filters['class_id'])) {
            $paymentQuery->whereHas('paymentSchedule', fn ($q) => $q->where('class_id', (int) $filters['class_id']));
        }

        if (! empty($filters['from'])) {
            $paymentQuery->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '>=', Carbon::parse($filters['from'])->toDateString());
        }

        if (! empty($filters['to'])) {
            $paymentQuery->whereDate(DB::raw('COALESCE(paid_at, created_at)'), '<=', Carbon::parse($filters['to'])->toDateString());
        }

        $paymentStats = $paymentQuery->selectRaw('
            COUNT(*) as total_payments,
            SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as successful_payments,
            SUM(CASE WHEN status IN ("pending", "initiated") THEN 1 ELSE 0 END) as pending_payments,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_payments,
            SUM(CASE WHEN status = "refunded" THEN 1 ELSE 0 END) as refunded_payments,
            SUM(CASE WHEN status = "paid" THEN total_amount ELSE 0 END) as total_collected
        ')->first();

        // Outstanding and overdue calculations using schedule due dates
        $scheduleQuery = PaymentSchedule::query()
            ->whereHas('classModel', fn ($q) => $q->whereIn('organization_id', $orgIds));

        if (! empty($filters['class_id'])) {
            $scheduleQuery->where('class_id', (int) $filters['class_id']);
        }

        if (! empty($filters['from'])) {
            $scheduleQuery->whereDate('due_date', '>=', Carbon::parse($filters['from'])->toDateString());
        }

        if (! empty($filters['to'])) {
            $scheduleQuery->whereDate('due_date', '<=', Carbon::parse($filters['to'])->toDateString());
        }

        // Outstanding: sum of unpaid schedules (required_amount - paid_amount)
        $unpaidSchedules = $scheduleQuery->clone()
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->withSum(['payments as paid_sum' => fn ($q) => $q->where('status', 'paid')], 'total_amount')
            ->get();

        $outstandingTotal = $unpaidSchedules->sum(fn ($s) => max(0.00, (float) $s->required_amount - (float) ($s->paid_sum ?? 0)));

        // Overdue: sum of overdue schedules (required_amount - paid_amount)
        $today = now('Asia/Kuala_Lumpur')->toDateString();
        $overdueSchedules = $scheduleQuery->clone()
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->where(fn ($q) => $q->where('status', 'overdue')->orWhereDate('due_date', '<', $today))
            ->withSum(['payments as paid_sum' => fn ($q) => $q->where('status', 'paid')], 'total_amount')
            ->get();

        $overdueTotal = $overdueSchedules->sum(fn ($s) => max(0.00, (float) $s->required_amount - (float) ($s->paid_sum ?? 0)));

        $collected = (float) ($paymentStats->total_collected ?? 0);

        return [
            'total_payments' => (int) ($paymentStats->total_payments ?? 0),
            'successful_payments' => (int) ($paymentStats->successful_payments ?? 0),
            'pending_payments' => (int) ($paymentStats->pending_payments ?? 0),
            'failed_payments' => (int) ($paymentStats->failed_payments ?? 0),
            'refunded_payments' => (int) ($paymentStats->refunded_payments ?? 0),
            'total_collected' => number_format($collected, 2, '.', ''),
            'total_outstanding' => number_format($outstandingTotal, 2, '.', ''),
            'total_overdue' => number_format($overdueTotal, 2, '.', ''),
            'payment_count' => (int) ($paymentStats->total_payments ?? 0),
        ];
    }

    /**
     * Outstanding payment schedules report (unpaid or partially paid).
     *
     * @param  array<int, int>  $orgIds
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<PaymentSchedule>
     */
    public function getOutstandingReport(array $orgIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        if (empty($orgIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $query = PaymentSchedule::query()
            ->whereHas('classModel', fn ($q) => $q->whereIn('organization_id', $orgIds))
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->with(['classModel.organization', 'classParticipant.user'])
            ->withSum(['payments as paid_sum' => fn ($q) => $q->where('status', 'paid')], 'total_amount');

        if (! empty($filters['class_id'])) {
            $query->where('class_id', (int) $filters['class_id']);
        }

        if (! empty($filters['participant_id'])) {
            $query->where('class_participant_id', (int) $filters['participant_id']);
        }

        if (! empty($filters['student_id'])) {
            $query->whereHas('classParticipant', fn ($q) => $q->where('user_id', (int) $filters['student_id']));
        }

        if (! empty($filters['from'])) {
            $query->whereDate('due_date', '>=', Carbon::parse($filters['from'])->toDateString());
        }

        if (! empty($filters['to'])) {
            $query->whereDate('due_date', '<=', Carbon::parse($filters['to'])->toDateString());
        }

        return $query->orderBy('due_date', 'asc')->paginate($perPage);
    }

    /**
     * Overdue payment schedules report.
     *
     * @param  array<int, int>  $orgIds
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<PaymentSchedule>
     */
    public function getOverdueReport(array $orgIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        if (empty($orgIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $today = now('Asia/Kuala_Lumpur')->toDateString();

        $query = PaymentSchedule::query()
            ->whereHas('classModel', fn ($q) => $q->whereIn('organization_id', $orgIds))
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->where(fn ($q) => $q->where('status', 'overdue')->orWhereDate('due_date', '<', $today))
            ->with(['classModel.organization', 'classParticipant.user'])
            ->withSum(['payments as paid_sum' => fn ($q) => $q->where('status', 'paid')], 'total_amount');

        if (! empty($filters['class_id'])) {
            $query->where('class_id', (int) $filters['class_id']);
        }

        if (! empty($filters['participant_id'])) {
            $query->where('class_participant_id', (int) $filters['participant_id']);
        }

        if (! empty($filters['student_id'])) {
            $query->whereHas('classParticipant', fn ($q) => $q->where('user_id', (int) $filters['student_id']));
        }

        if (! empty($filters['from'])) {
            $query->whereDate('due_date', '>=', Carbon::parse($filters['from'])->toDateString());
        }

        if (! empty($filters['to'])) {
            $query->whereDate('due_date', '<=', Carbon::parse($filters['to'])->toDateString());
        }

        return $query->orderBy('due_date', 'asc')->paginate($perPage);
    }

    /**
     * Format a Payment model into a standardized report array structure.
     *
     * @return array<string, mixed>
     */
    public function formatPaymentResource(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'reference_number' => $payment->reference_number,
            'required_amount' => number_format((float) $payment->required_amount, 2, '.', ''),
            'additional_infaq' => number_format((float) $payment->additional_infaq, 2, '.', ''),
            'total_amount' => number_format((float) $payment->total_amount, 2, '.', ''),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'payment_method' => $payment->payment_method,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'payer' => $payment->payer ? [
                'id' => $payment->payer->id,
                'name' => $payment->payer->name,
                'email' => $payment->payer->email,
            ] : null,
            'class' => $payment->paymentSchedule?->classModel ? [
                'id' => $payment->paymentSchedule->classModel->id,
                'name' => $payment->paymentSchedule->classModel->name,
            ] : null,
            'organization' => $payment->paymentSchedule?->classModel?->organization ? [
                'id' => $payment->paymentSchedule->classModel->organization->id,
                'name' => $payment->paymentSchedule->classModel->organization->name,
            ] : null,
        ];
    }

    /**
     * Format an outstanding/overdue PaymentSchedule model into a report array structure.
     *
     * @return array<string, mixed>
     */
    public function formatScheduleReportItem(PaymentSchedule $schedule, bool $isOverdueReport = false): array
    {
        $requiredAmount = (float) $schedule->required_amount;
        $amountPaid = (float) ($schedule->paid_sum ?? 0);
        $outstandingAmount = max(0.00, $requiredAmount - $amountPaid);

        $data = [
            'id' => $schedule->id,
            'organization' => $schedule->classModel?->organization ? [
                'id' => $schedule->classModel->organization->id,
                'name' => $schedule->classModel->organization->name,
            ] : null,
            'class' => $schedule->classModel ? [
                'id' => $schedule->classModel->id,
                'name' => $schedule->classModel->name,
            ] : null,
            'participant' => [
                'id' => $schedule->class_participant_id,
                'user_id' => $schedule->classParticipant?->user_id,
                'name' => $schedule->classParticipant?->user?->name,
                'email' => $schedule->classParticipant?->user?->email,
            ],
            'period' => [
                'start' => $schedule->period_start?->toDateString(),
                'end' => $schedule->period_end?->toDateString(),
            ],
            'due_date' => $schedule->due_date?->toDateString(),
            'required_amount' => number_format($requiredAmount, 2, '.', ''),
            'amount_paid' => number_format($amountPaid, 2, '.', ''),
            'outstanding_amount' => number_format($outstandingAmount, 2, '.', ''),
            'status' => $schedule->status,
        ];

        if ($isOverdueReport) {
            $today = now('Asia/Kuala_Lumpur')->startOfDay();
            $dueDate = $schedule->due_date ? Carbon::parse($schedule->due_date)->startOfDay() : $today;
            $daysOverdue = $dueDate->lt($today) ? $dueDate->diffInDays($today) : 0;
            $data['days_overdue'] = (int) $daysOverdue;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPaymentSummary(): array
    {
        return [
            'total_payments' => 0,
            'successful_payments' => 0,
            'pending_payments' => 0,
            'failed_payments' => 0,
            'refunded_payments' => 0,
            'total_collected' => '0.00',
            'total_outstanding' => '0.00',
            'total_overdue' => '0.00',
            'payment_count' => 0,
        ];
    }
}
