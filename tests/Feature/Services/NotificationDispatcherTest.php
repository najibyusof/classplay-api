<?php

namespace Tests\Feature\Services;

use App\Mail\PaymentNotificationMail;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fcm.server_key' => 'test-fcm-key',
            'services.telegram.bot_token' => 'test-bot-token',
        ]);
    }

    public function test_dispatch_sends_email_push_and_telegram_and_logs_each_channel(): void
    {
        Mail::fake();
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['message_id' => 'fcm-1']),
            'api.telegram.org/*' => Http::response(['result' => ['message_id' => 42]]),
        ]);

        $user = User::factory()->create(['email' => 'student@example.com', 'telegram_chat_id' => '12345']);
        UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        app(NotificationDispatcher::class)->dispatch($notification);

        Mail::assertSent(PaymentNotificationMail::class);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'push', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'telegram', 'status' => 'sent']);
    }

    public function test_push_channel_is_marked_failed_when_fcm_key_missing(): void
    {
        config(['services.fcm.server_key' => null]);

        $user = User::factory()->create(['email' => null, 'telegram_chat_id' => null]);
        UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        app(NotificationDispatcher::class)->dispatch($notification);

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'push',
            'status' => 'failed',
            'error_message' => 'FCM server key is not configured.',
        ]);
    }

    public function test_inactive_devices_are_not_sent_push_notifications(): void
    {
        $user = User::factory()->create(['email' => null, 'telegram_chat_id' => null]);
        UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'inactive']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        app(NotificationDispatcher::class)->dispatch($notification);

        $this->assertDatabaseMissing('notification_logs', ['channel' => 'push']);
    }
}
