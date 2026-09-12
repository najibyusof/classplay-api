<?php

namespace App\Services\Notification;

use App\Contracts\Notification\PushNotificationServiceInterface;
use App\Models\Notification;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class FcmPushNotificationService implements PushNotificationServiceInterface
{
    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'secret',
        'api_key',
        'authorization',
        'credit_card',
    ];

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

        $log = NotificationLog::query()->create([
            'notification_id' => $notification->id,
            'channel' => 'push',
            'recipient' => $device->device_token,
            'status' => 'pending',
        ]);

        $serverKey = config('notifications.fcm.server_key')
            ?: config('services.fcm.server_key');

        if (empty($serverKey)) {
            $log->update([
                'status' => 'failed',
                'error_message' => 'FCM server key is not configured.',
            ]);

            return false;
        }

        $apiUrl = config('notifications.fcm.api_url', 'https://fcm.googleapis.com/fcm/send');
        $timeout = (int) config('notifications.fcm.timeout', 10);

        $payload = [
            'to' => $device->device_token,
            'notification' => [
                'title' => $notification->title,
                'body' => $notification->message,
            ],
            'data' => $this->buildDataPayload($notification),
        ];

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Authorization' => "key={$serverKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post($apiUrl, $payload);

            if ($this->isInvalidTokenResponse($response)) {
                $device->update(['status' => 'inactive']);

                $log->update([
                    'status' => 'failed',
                    'error_message' => 'Device token is invalid or expired.',
                ]);

                return false;
            }

            if ($response->successful()) {
                $messageId = $response->json('message_id')
                    ?? $response->json('results.0.message_id');

                $log->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'provider_message_id' => $messageId,
                ]);

                return true;
            }

            $log->update([
                'status' => 'failed',
                'error_message' => $response->body(),
            ]);

            return false;
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build non-sensitive structured data payload for deep-linking in Flutter.
     *
     * @return array<string, mixed>
     */
    public function buildDataPayload(Notification $notification): array
    {
        $data = [
            'type' => $notification->type,
            'notification_id' => (string) $notification->id,
            'related_type' => $notification->related_type,
            'related_id' => $notification->related_id ? (string) $notification->related_id : null,
        ];

        // Payment reminder / schedule deep-linking contract for Flutter
        $customData = is_array($notification->data) ? $notification->data : [];
        $scheduleId = $customData['payment_schedule_id'] ?? null;

        if (! $scheduleId && $notification->related_type === 'App\\Models\\PaymentSchedule') {
            $scheduleId = $notification->related_id;
        }

        if ($scheduleId) {
            $data['payment_schedule_id'] = (string) $scheduleId;
            $data['route'] = "/payment-schedules/{$scheduleId}";
            $data['action'] = 'open_payment';
        }

        foreach ($customData as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                continue;
            }
            if (! isset($data[$key]) && is_scalar($value)) {
                $data[$key] = (string) $value;
            }
        }

        return $data;
    }

    private function isInvalidTokenResponse(Response $response): bool
    {
        if (in_array($response->status(), [400, 404], true)) {
            return true;
        }

        $body = $response->body();
        if (str_contains($body, 'NotRegistered') || str_contains($body, 'InvalidRegistration') || str_contains($body, 'MismatchSenderId')) {
            return true;
        }

        $error = $response->json('results.0.error');

        return in_array($error, ['NotRegistered', 'InvalidRegistration', 'MismatchSenderId'], true);
    }
}
