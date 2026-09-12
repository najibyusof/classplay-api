<?php

namespace App\Contracts\Notification;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserDevice;

interface PushNotificationServiceInterface
{
    /**
     * Send a push notification to a specific user device.
     */
    public function sendToDevice(UserDevice $device, Notification $notification): bool;

    /**
     * Send a push notification to all active devices belonging to a user.
     *
     * @return array<int, bool> Map of device ID to delivery success boolean
     */
    public function sendToUser(User $user, Notification $notification): array;
}
