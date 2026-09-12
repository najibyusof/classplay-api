<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $auditLogs = AuditLog::query()->latest()->paginate($perPage);

        return $this->successResponse([
            'audit_logs' => AuditLogResource::collection($auditLogs)->resolve(),
            'pagination' => [
                'current_page' => $auditLogs->currentPage(),
                'per_page' => $auditLogs->perPage(),
                'total' => $auditLogs->total(),
                'last_page' => $auditLogs->lastPage(),
            ],
        ], 'Audit logs retrieved successfully.');
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $this->authorize('view', $auditLog);

        return $this->successResponse(
            new AuditLogResource($auditLog),
            'Audit log retrieved successfully.'
        );
    }
}
