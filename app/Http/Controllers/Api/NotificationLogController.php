<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationLogResource;
use App\Models\Notification;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationLogController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request, Notification $notification): JsonResponse
    {
        $this->authorize('viewAny', NotificationLog::class);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $logs = $notification->logs()->paginate($perPage);

        return $this->successResponse([
            'logs' => NotificationLogResource::collection($logs)->resolve(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ], 'Notification logs retrieved successfully.');
    }
}
