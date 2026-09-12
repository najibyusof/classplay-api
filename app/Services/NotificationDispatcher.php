<?php

namespace App\Services;

use App\Contracts\Notification\PushNotificationServiceInterface;
use App\Mail\PaymentNotificationMail;
use App\Models\Notification;
use App\Models\NotificationLog;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationDispatcher
{
    public function __construct(
        private readonly ?PushNotificationServiceInterface $pushService = null
    ) {}

    /**
     * Deliver a notification through every channel the user has configured.
     */
    public function dispatch(Notification $notification): void
    {
        $user = $notification->user;

        if ($user->email) {
            $this->sendEmail($notification, $user->email);
        }

        $pushService = $this->pushService ?? app(PushNotificationServiceInterface::class);
        $pushService->sendToUser($user, $notification);

        if ($user->telegram_chat_id) {
            $this->sendTelegram($notification, $user->telegram_chat_id);
        }
    }

    private function sendEmail(Notification $notification, string $email): void
    {
        $log = $this->createLog($notification, 'email', $email);

        try {
            Mail::to($email)->send(new PaymentNotificationMail($notification));

            $log->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            $log->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
        }
    }

    private function sendPush(Notification $notification, UserDevice $device): void
    {
        $log = $this->createLog($notification, 'push', $device->device_token);
        $serverKey = config('services.fcm.server_key');

        if (! $serverKey) {
            $log->update(['status' => 'failed', 'error_message' => 'FCM server key is not configured.']);

            return;
        }

        try {
            $response = Http::withHeaders(['Authorization' => "key={$serverKey}"])
                ->post('https://fcm.googleapis.com/fcm/send', [
                    'to' => $device->device_token,
                    'notification' => [
                        'title' => $notification->title,
                        'body' => $notification->message,
                    ],
                ]);

            $log->update([
                'status' => $response->successful() ? 'sent' : 'failed',
                'sent_at' => $response->successful() ? now() : null,
                'provider_message_id' => $response->json('message_id'),
                'error_message' => $response->successful() ? null : $response->body(),
            ]);
        } catch (Throwable $exception) {
            $log->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
        }
    }

    private function sendTelegram(Notification $notification, string $chatId): void
    {
        $log = $this->createLog($notification, 'telegram', $chatId);
        $botToken = config('services.telegram.bot_token');

        if (! $botToken) {
            $log->update(['status' => 'failed', 'error_message' => 'Telegram bot token is not configured.']);

            return;
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "{$notification->title}\n\n{$notification->message}",
            ]);

            $log->update([
                'status' => $response->successful() ? 'sent' : 'failed',
                'sent_at' => $response->successful() ? now() : null,
                'provider_message_id' => $response->json('result.message_id'),
                'error_message' => $response->successful() ? null : $response->body(),
            ]);
        } catch (Throwable $exception) {
            $log->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
        }
    }

    private function createLog(Notification $notification, string $channel, string $recipient): NotificationLog
    {
        return $notification->logs()->create([
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => 'pending',
        ]);
    }
}
