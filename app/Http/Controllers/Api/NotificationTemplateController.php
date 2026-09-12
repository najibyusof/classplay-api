<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\StoreNotificationTemplateRequest;
use App\Http\Requests\Notification\UpdateNotificationTemplateRequest;
use App\Http\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTemplateController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', NotificationTemplate::class);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $templates = NotificationTemplate::query()->paginate($perPage);

        return $this->successResponse([
            'notification_templates' => NotificationTemplateResource::collection($templates)->resolve(),
            'pagination' => [
                'current_page' => $templates->currentPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
                'last_page' => $templates->lastPage(),
            ],
        ], 'Notification templates retrieved successfully.');
    }

    public function store(StoreNotificationTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', NotificationTemplate::class);

        $template = NotificationTemplate::query()->create($request->validated());

        return $this->successResponse(
            new NotificationTemplateResource($template),
            'Notification template created successfully.',
            201
        );
    }

    public function show(NotificationTemplate $notificationTemplate): JsonResponse
    {
        $this->authorize('view', $notificationTemplate);

        return $this->successResponse(
            new NotificationTemplateResource($notificationTemplate),
            'Notification template retrieved successfully.'
        );
    }

    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        $this->authorize('update', $notificationTemplate);

        $notificationTemplate->update($request->validated());

        return $this->successResponse(
            new NotificationTemplateResource($notificationTemplate),
            'Notification template updated successfully.'
        );
    }

    public function destroy(NotificationTemplate $notificationTemplate): JsonResponse
    {
        $this->authorize('delete', $notificationTemplate);

        $notificationTemplate->delete();

        return $this->successResponse(null, 'Notification template deleted successfully.');
    }
}
