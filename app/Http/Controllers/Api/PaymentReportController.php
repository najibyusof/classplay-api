<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Report\PaymentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReportController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly PaymentReportService $reportService) {}

    /**
     * Admin payment listing report.
     * GET /api/v1/admin/payments
     */
    public function index(Request $request): JsonResponse
    {
        $orgResolution = $this->resolveAuthorizedOrgIds($request);

        if ($orgResolution instanceof JsonResponse) {
            return $orgResolution;
        }

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $filters = $this->extractFilters($request);

        $payments = $this->reportService->getPayments($orgResolution, $filters, $perPage);

        $formatted = collect($payments->items())->map(
            fn ($payment) => $this->reportService->formatPaymentResource($payment)
        )->all();

        return $this->successResponse([
            'payments' => $formatted,
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ], 'Payments retrieved successfully.');
    }

    /**
     * Admin payment summary report.
     * GET /api/v1/admin/reports/payment-summary
     */
    public function summary(Request $request): JsonResponse
    {
        $orgResolution = $this->resolveAuthorizedOrgIds($request);

        if ($orgResolution instanceof JsonResponse) {
            return $orgResolution;
        }

        $filters = $this->extractFilters($request);
        $data = $this->reportService->getPaymentSummary($orgResolution, $filters);

        return $this->successResponse($data, 'Payment summary report retrieved successfully.');
    }

    /**
     * Admin outstanding payment schedules report.
     * GET /api/v1/admin/reports/outstanding
     */
    public function outstanding(Request $request): JsonResponse
    {
        $orgResolution = $this->resolveAuthorizedOrgIds($request);

        if ($orgResolution instanceof JsonResponse) {
            return $orgResolution;
        }

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $filters = $this->extractFilters($request);

        $schedules = $this->reportService->getOutstandingReport($orgResolution, $filters, $perPage);

        $formatted = collect($schedules->items())->map(
            fn ($schedule) => $this->reportService->formatScheduleReportItem($schedule, false)
        )->all();

        return $this->successResponse([
            'schedules' => $formatted,
            'pagination' => [
                'current_page' => $schedules->currentPage(),
                'per_page' => $schedules->perPage(),
                'total' => $schedules->total(),
                'last_page' => $schedules->lastPage(),
            ],
        ], 'Outstanding report retrieved successfully.');
    }

    /**
     * Admin overdue payment schedules report.
     * GET /api/v1/admin/reports/overdue
     */
    public function overdue(Request $request): JsonResponse
    {
        $orgResolution = $this->resolveAuthorizedOrgIds($request);

        if ($orgResolution instanceof JsonResponse) {
            return $orgResolution;
        }

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $filters = $this->extractFilters($request);

        $schedules = $this->reportService->getOverdueReport($orgResolution, $filters, $perPage);

        $formatted = collect($schedules->items())->map(
            fn ($schedule) => $this->reportService->formatScheduleReportItem($schedule, true)
        )->all();

        return $this->successResponse([
            'schedules' => $formatted,
            'pagination' => [
                'current_page' => $schedules->currentPage(),
                'per_page' => $schedules->perPage(),
                'total' => $schedules->total(),
                'last_page' => $schedules->lastPage(),
            ],
        ], 'Overdue report retrieved successfully.');
    }

    /**
     * Extract filter parameters from request.
     *
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        return [
            'class_id' => $request->query('class_id'),
            'participant_id' => $request->query('participant_id'),
            'student_id' => $request->query('student_id'),
            'sponsor_id' => $request->query('sponsor_id'),
            'status' => $request->query('status'),
            'payment_method' => $request->query('payment_method'),
            'from' => $request->query('from') ?? $request->query('date_from'),
            'to' => $request->query('to') ?? $request->query('date_to'),
        ];
    }

    /**
     * Authorize admin user and resolve allowed organization IDs.
     *
     * @return array<int, int>|JsonResponse
     */
    private function resolveAuthorizedOrgIds(Request $request): array|JsonResponse
    {
        $user = $request->user();

        if ($user->user_type === 'student' || $user->user_type === 'sponsor') {
            return $this->errorResponse('You are not authorized to access reports.', [], 403);
        }

        $activeAdminOrgIds = $user->organizationAdmins()
            ->where('status', 'active')
            ->pluck('organization_id')
            ->all();

        if (empty($activeAdminOrgIds) && $user->hasRole('ADMIN')) {
            $allowedOrgIds = Organization::query()->pluck('id')->all();
        } else {
            $allowedOrgIds = $activeAdminOrgIds;
        }

        if (! $user->hasRole('ADMIN') && ! $user->hasPermission('report.view') && ! $user->hasPermission('payment.view') && ! $user->hasPermission('organization.view') && empty($allowedOrgIds)) {
            return $this->errorResponse('You are not authorized to access reports.', [], 403);
        }

        if ($request->filled('organization_id')) {
            $requestedOrgId = (int) $request->query('organization_id');

            if (! in_array($requestedOrgId, $allowedOrgIds, true)) {
                return $this->errorResponse('You are not authorized to view reports for this organization.', [], 403);
            }

            return [$requestedOrgId];
        }

        return $allowedOrgIds;
    }
}
