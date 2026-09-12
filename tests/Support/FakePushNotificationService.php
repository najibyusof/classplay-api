<?php

namespace Tests\Support;

use App\Contracts\Notification\PushNotificationServiceInterface;
use App\Models\Notification;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Notification\FcmPushNotificationService;

class FakePushNotificationService implements PushNotificationServiceInterface
{
    /** @var array<int, array{device: UserDevice, notification: Notification, payload: array<string, mixed>}> */
    public array $sentPushes = [];

    /** @var array<string> List of device tokens that should simulate invalid token error */
    public array $invalidTokens = [];

    /** @var array<string> List of device tokens that should simulate push failure */
    public array $failingTokens = [];

    public function sendToUser(User $user, Notification $notification): array
    {
        $results = [];
        $devices = $user->devices()->where('status', 'active')->get();

        foreach ($devices as $device) {
            $results[$device->id] = $this->sendToDevice($device, $notification);
        }

        return $results;
    }

    public function sendToDevice(UserDevice $device, Notification $notification): bool
    {
        if ($device->status !== 'active') {
            return false;
        }

        $token = $device->device_token;

        if (in_array($token, $this->invalidTokens, true)) {
            $device->update(['status' => 'inactive']);

            NotificationLog::query()->create([
                'notification_id' => $notification->id,
                'channel' => 'push',
                'recipient' => $token,
                'status' => 'failed',
                'error_message' => 'Device token is invalid or expired.',
            ]);

            return false;
        }

        if (in_array($token, $this->failingTokens, true)) {
            NotificationLog::query()->create([
                'notification_id' => $notification->id,
                'channel' => 'push',
                'recipient' => $token,
                'status' => 'failed',
                'error_message' => 'Simulated push delivery failure.',
            ]);

            return false;
        }

        $fcmService = new FcmPushNotificationService;
        $payloadData = $fcmService->buildDataPayload($notification);

        $this->sentPushes[] = [
            'device' => $device,
            'notification' => $notification,
            'payload' => $payloadData,
        ];

        NotificationLog::query()->create([
            'notification_id' => $notification->id,
            'channel' => 'push',
            'recipient' => $token,
            'status' => 'sent',
            'provider_message_id' => 'fake-msg-'.count($this->sentPushes),
            'sent_at' => now(),
        ]);

        return true;
    }
}
