<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly DashboardService $dashboardService) {}

    /**
     * Get global admin dashboard metrics for accessible organizations.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type === 'student' || $user->user_type === 'sponsor') {
            return $this->errorResponse('You are not authorized to access the dashboard.', [], 403);
        }

        $activeAdminOrgIds = $user->organizationAdmins()
            ->where('status', 'active')
            ->pluck('organization_id')
            ->all();

        if (empty($activeAdminOrgIds) && $user->hasRole('ADMIN')) {
            $orgIds = Organization::query()->pluck('id')->all();
        } else {
            $orgIds = $activeAdminOrgIds;
        }

        if (! $user->hasRole('ADMIN') && ! $user->hasPermission('report.view') && ! $user->hasPermission('organization.view') && empty($orgIds)) {
            return $this->errorResponse('You are not authorized to access the dashboard.', [], 403);
        }

        $fromDate = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : ($request->filled('date_from') ? Carbon::parse($request->query('date_from'))->startOfDay() : null);
        $toDate = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : ($request->filled('date_to') ? Carbon::parse($request->query('date_to'))->endOfDay() : null);
        $recentLimit = max(1, min((int) $request->query('recent_limit', 10), 50));

        $data = $this->dashboardService->getGlobalDashboard($orgIds, $fromDate, $toDate, $recentLimit);

        return $this->successResponse($data, 'Dashboard retrieved successfully.');
    }

    /**
     * Get organization-specific dashboard metrics.
     */
    public function organizationDashboard(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        $fromDate = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : ($request->filled('date_from') ? Carbon::parse($request->query('date_from'))->startOfDay() : null);
        $toDate = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : ($request->filled('date_to') ? Carbon::parse($request->query('date_to'))->endOfDay() : null);
        $recentLimit = max(1, min((int) $request->query('recent_limit', 10), 50));

        $data = $this->dashboardService->getOrganizationDashboard($organization, $fromDate, $toDate, $recentLimit);

        return $this->successResponse($data, 'Organization dashboard retrieved successfully.');
    }
}
