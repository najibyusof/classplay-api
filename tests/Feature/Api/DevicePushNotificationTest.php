<?php

namespace Tests\Feature\Api;

use App\Contracts\Notification\PushNotificationServiceInterface;
use App\Models\Notification;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Notification\FcmPushNotificationService;
use App\Services\Notification\NotificationType;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePushNotificationService;
use Tests\TestCase;

class DevicePushNotificationTest extends TestCase
{
    use RefreshDatabase;

    // 1. Register device.
    public function test_authenticated_user_can_register_device(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/devices', [
            'device_token' => 'token-abc-123',
            'platform' => 'android',
            'device_name' => 'Galaxy S23',
            'app_version' => '1.0.0',
        ])->assertCreated();

        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_token' => 'token-abc-123',
            'platform' => 'android',
            'status' => 'active',
        ]);
    }

    // 2. Update existing device token instead of creating duplicates.
    public function test_registering_existing_token_updates_device(): void
    {
        $user1 = User::factory()->create();
        $device = UserDevice::factory()->create([
            'user_id' => $user1->id,
            'device_token' => 'token-shared-xyz',
            'status' => 'inactive',
        ]);

        $user2 = User::factory()->create();
        Sanctum::actingAs($user2);

        $response = $this->postJson('/api/v1/devices', [
            'device_token' => 'token-shared-xyz',
            'platform' => 'ios',
            'device_name' => 'iPhone 15',
            'app_version' => '2.0.0',
        ])->assertCreated();

        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseHas('user_devices', [
            'id' => $device->id,
            'user_id' => $user2->id,
            'platform' => 'ios',
            'status' => 'active',
        ]);
    }

    // 3. Multiple devices per user supported.
    public function test_user_can_have_multiple_active_devices(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/devices', [
            'device_token' => 'token-device-1',
            'platform' => 'android',
        ])->assertCreated();

        $this->postJson('/api/v1/devices', [
            'device_token' => 'token-device-2',
            'platform' => 'ios',
        ])->assertCreated();

        $this->assertCount(2, $user->devices()->where('status', 'active')->get());
    }

    // 4. List own devices.
    public function test_user_can_list_own_devices(): void
    {
        $user = User::factory()->create();
        UserDevice::factory()->count(2)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/devices')->assertOk();

        $response->assertJsonCount(2, 'data.devices');
    }

    // 5. Cannot list another user's devices.
    public function test_user_cannot_see_another_users_devices(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $userB->id]);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/v1/devices')->assertOk();

        $response->assertJsonCount(0, 'data.devices');
    }

    // 6. Remove own device.
    public function test_user_can_deactivate_own_device(): void
    {
        $user = User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/devices/{$device->id}")->assertOk();

        $this->assertSame('inactive', $device->fresh()->status);
    }

    // 7. Cannot remove another user's device.
    public function test_user_cannot_deactivate_another_users_device(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $deviceB = UserDevice::factory()->create(['user_id' => $userB->id, 'status' => 'active']);

        Sanctum::actingAs($userA);
        $this->deleteJson("/api/v1/devices/{$deviceB->id}")->assertForbidden();
    }

    // 8. Push notification sent to active devices.
    public function test_push_notification_is_sent_to_active_devices(): void
    {
        $fakePush = new FakePushNotificationService;
        $this->app->instance(PushNotificationServiceInterface::class, $fakePush);

        $user = User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        app(NotificationDispatcher::class)->dispatch($notification);

        $this->assertCount(1, $fakePush->sentPushes);
        $this->assertSame($device->id, $fakePush->sentPushes[0]['device']->id);
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notification->id,
            'channel' => 'push',
            'status' => 'sent',
        ]);
    }

    // 9. Inactive devices are skipped.
    public function test_inactive_devices_are_skipped(): void
    {
        $fakePush = new FakePushNotificationService;
        $this->app->instance(PushNotificationServiceInterface::class, $fakePush);

        $user = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'inactive']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        app(NotificationDispatcher::class)->dispatch($notification);

        $this->assertCount(0, $fakePush->sentPushes);
    }

    // 10. Invalid token is deactivated.
    public function test_invalid_device_token_is_deactivated_on_delivery(): void
    {
        $fakePush = new FakePushNotificationService;
        $fakePush->invalidTokens[] = 'invalid-token-123';
        $this->app->instance(PushNotificationServiceInterface::class, $fakePush);

        $user = User::factory()->create();
        $device = UserDevice::factory()->create([
            'user_id' => $user->id,
            'device_token' => 'invalid-token-123',
            'status' => 'active',
        ]);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        app(NotificationDispatcher::class)->dispatch($notification);

        $this->assertSame('inactive', $device->fresh()->status);
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notification->id,
            'channel' => 'push',
            'status' => 'failed',
            'error_message' => 'Device token is invalid or expired.',
        ]);
    }

    // 11. Notification log is created.
    public function test_notification_log_is_created_for_push_delivery(): void
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['message_id' => 'msg-999']),
        ]);
        config(['notifications.fcm.server_key' => 'test-key']);

        $user = User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        $service = app(FcmPushNotificationService::class);
        $service->sendToDevice($device, $notification);

        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notification->id,
            'channel' => 'push',
            'recipient' => $device->device_token,
            'status' => 'sent',
            'provider_message_id' => 'msg-999',
        ]);
    }

    // 12. Push failure is logged.
    public function test_push_failure_is_logged_when_fcm_returns_error(): void
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response('Internal Server Error', 500),
        ]);
        config(['notifications.fcm.server_key' => 'test-key']);

        $user = User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        $service = app(FcmPushNotificationService::class);
        $result = $service->sendToDevice($device, $notification);

        $this->assertFalse($result);
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notification->id,
            'channel' => 'push',
            'status' => 'failed',
        ]);
    }

    // 13. Sensitive credentials are not exposed in API response or logs.
    public function test_sensitive_credentials_are_not_exposed(): void
    {
        Log::spy();
        config(['notifications.fcm.server_key' => 'super-secret-fcm-key']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/devices', [
            'device_token' => 'my-device-token',
            'platform' => 'android',
        ])->assertCreated();

        $this->assertStringNotContainsString('super-secret-fcm-key', $response->getContent());
        Log::shouldNotHaveReceived('info', fn (...$args) => str_contains(json_encode($args), 'super-secret-fcm-key'));
    }

    // 14. Push payload contains expected structured data.
    public function test_push_payload_contains_expected_structured_data(): void
    {
        $notification = Notification::factory()->create([
            'type' => NotificationType::PAYMENT_SUCCESS,
            'related_type' => 'App\\Models\\Payment',
            'related_id' => 88,
        ]);

        $service = new FcmPushNotificationService;
        $data = $service->buildDataPayload($notification);

        $this->assertSame(NotificationType::PAYMENT_SUCCESS, $data['type']);
        $this->assertSame('App\\Models\\Payment', $data['related_type']);
        $this->assertSame('88', $data['related_id']);
    }

    // 15. Payment reminder payload contains payment schedule ID and deep-link route.
    public function test_payment_reminder_payload_contains_schedule_id_and_route(): void
    {
        $schedule = PaymentSchedule::factory()->create();
        $notification = Notification::factory()->create([
            'type' => NotificationType::PAYMENT_REMINDER,
            'related_type' => PaymentSchedule::class,
            'related_id' => $schedule->id,
            'data' => [
                'payment_schedule_id' => $schedule->id,
            ],
        ]);

        $service = new FcmPushNotificationService;
        $data = $service->buildDataPayload($notification);

        $this->assertSame((string) $schedule->id, $data['payment_schedule_id']);
        $this->assertSame("/payment-schedules/{$schedule->id}", $data['route']);
        $this->assertSame('open_payment', $data['action']);
    }

    // Bonus: FCM invalid token response automatically marks device as inactive
    public function test_fcm_not_registered_response_deactivates_device(): void
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response([
                'results' => [
                    ['error' => 'NotRegistered'],
                ],
            ], 200),
        ]);
        config(['notifications.fcm.server_key' => 'test-key']);

        $user = User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        $service = app(FcmPushNotificationService::class);
        $result = $service->sendToDevice($device, $notification);

        $this->assertFalse($result);
        $this->assertSame('inactive', $device->fresh()->status);
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notification->id,
            'channel' => 'push',
            'status' => 'failed',
            'error_message' => 'Device token is invalid or expired.',
        ]);
    }
}
