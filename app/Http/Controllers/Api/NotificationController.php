<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\UpdateNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100) ?: 20;

        $filters = [
            'unread' => $request->query('unread'),
            'type' => $request->query('type'),
        ];

        $notifications = $this->notificationService->getNotifications($request->user(), $filters, $perPage);

        return $this->successResponse([
            'notifications' => NotificationResource::collection($notifications)->resolve(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        ], 'Notifications retrieved successfully.');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->getUnreadCount($request->user());

        return $this->successResponse([
            'count' => $count,
        ], 'Unread notification count retrieved successfully.');
    }

    public function show(Notification $notification): JsonResponse
    {
        $this->authorize('view', $notification);

        return $this->successResponse([
            'notification' => new NotificationResource($notification),
        ], 'Notification retrieved successfully.');
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        $this->authorize('update', $notification);

        $notification = $this->notificationService->markAsRead($notification);

        return $this->successResponse([
            'notification' => new NotificationResource($notification),
        ], 'Notification marked as read.');
    }

    public function markAsUnread(Notification $notification): JsonResponse
    {
        $this->authorize('update', $notification);

        $notification = $this->notificationService->markAsUnread($notification);

        return $this->successResponse([
            'notification' => new NotificationResource($notification),
        ], 'Notification marked as unread.');
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = $this->notificationService->markAllAsRead($request->user());

        return $this->successResponse([
            'updated_count' => $count,
        ], 'All notifications marked as read.');
    }

    public function update(UpdateNotificationRequest $request, Notification $notification): JsonResponse
    {
        $this->authorize('update', $notification);

        $readAt = $request->validated('read_at');

        if ($readAt !== null) {
            $notification->update(['read_at' => $readAt]);
        } else {
            $notification = $this->notificationService->markAsRead($notification);
        }

        return $this->successResponse([
            'notification' => new NotificationResource($notification),
        ], 'Notification updated successfully.');
    }

    public function destroy(Notification $notification): JsonResponse
    {
        $this->authorize('delete', $notification);

        $notification->delete();

        return $this->successResponse(null, 'Notification deleted successfully.');
    }
}
