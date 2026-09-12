<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Payment;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    // 1. Notification can be created by NotificationService.
    public function test_notification_can_be_created_by_notification_service(): void
    {
        $service = app(NotificationService::class);
        $user = User::factory()->create();

        $notification = $service->createNotification(
            $user,
            NotificationType::PAYMENT_SUCCESS,
            'Payment Successful',
            'Your payment of RM50.00 was received.'
        );

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'type' => 'payment.success',
            'title' => 'Payment Successful',
        ]);
    }

    // 2. User can list own notifications.
    public function test_user_can_list_own_notifications(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(3)->create(['user_id' => $user->id]);

        $otherUser = User::factory()->create();
        Notification::factory()->count(2)->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $response->assertJsonPath('data.pagination.total', 3);
        $this->assertCount(3, $response->json('data.notifications'));
    }

    // 3. User cannot view another user's notification.
    public function test_user_cannot_view_another_users_notification(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $notificationB = Notification::factory()->create(['user_id' => $userB->id]);

        Sanctum::actingAs($userA);
        $this->getJson("/api/v1/notifications/{$notificationB->id}")->assertForbidden();
    }

    // 4. Pagination works.
    public function test_notification_pagination_works(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(5)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/notifications?per_page=2&page=1')->assertOk();

        $response->assertJsonPath('data.pagination.per_page', 2);
        $response->assertJsonPath('data.pagination.total', 5);
        $this->assertCount(2, $response->json('data.notifications'));
    }

    // 5. Unread filter works.
    public function test_unread_filter_returns_only_unread_notifications(): void
    {
        $user = User::factory()->create();
        Notification::factory()->create(['user_id' => $user->id, 'read_at' => now()]);
        Notification::factory()->count(2)->create(['user_id' => $user->id, 'read_at' => null]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/notifications?unread=true')->assertOk();

        $response->assertJsonPath('data.pagination.total', 2);
    }

    // 6. Type filter works.
    public function test_type_filter_returns_only_matching_notifications(): void
    {
        $user = User::factory()->create();
        Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::PAYMENT_SUCCESS]);
        Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::PAYMENT_REMINDER]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/notifications?type='.NotificationType::PAYMENT_REMINDER)->assertOk();

        $response->assertJsonPath('data.pagination.total', 1);
        $response->assertJsonPath('data.notifications.0.type', NotificationType::PAYMENT_REMINDER);
    }

    // 7. User can mark own notification read.
    public function test_user_can_mark_own_notification_read(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id, 'read_at' => null]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $response->assertJsonPath('success', true);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    // 8. User cannot mark another user's notification read.
    public function test_user_cannot_mark_another_users_notification_read(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $notificationB = Notification::factory()->create(['user_id' => $userB->id, 'read_at' => null]);

        Sanctum::actingAs($userA);
        $this->postJson("/api/v1/notifications/{$notificationB->id}/read")->assertForbidden();
    }

    // 9. Read operation is idempotent.
    public function test_read_operation_is_idempotent(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id, 'read_at' => null]);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $firstReadAt = $notification->fresh()->read_at;

        // Second call
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $this->assertEquals($firstReadAt, $notification->fresh()->read_at);
    }

    // 10. Mark all read only affects current user.
    public function test_mark_all_read_only_affects_current_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Notification::factory()->count(3)->create(['user_id' => $userA->id, 'read_at' => null]);
        $notificationB = Notification::factory()->create(['user_id' => $userB->id, 'read_at' => null]);

        Sanctum::actingAs($userA);
        $response = $this->postJson('/api/v1/notifications/read-all')->assertOk();

        $response->assertJsonPath('data.updated_count', 3);
        $this->assertSame(0, $userA->appNotifications()->unread()->count());
        $this->assertNull($notificationB->fresh()->read_at);
    }

    // 11. Unread count is correct.
    public function test_unread_count_endpoint_returns_correct_number(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(4)->create(['user_id' => $user->id, 'read_at' => null]);
        Notification::factory()->count(2)->create(['user_id' => $user->id, 'read_at' => now()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/notifications/unread-count')->assertOk();

        $response->assertJsonPath('data.count', 4);
    }

    // 12. Notification template rendering works.
    public function test_notification_template_rendering_works(): void
    {
        NotificationTemplate::factory()->create([
            'notification_type' => NotificationType::PAYMENT_REMINDER,
            'subject' => 'Payment Reminder for {{class_name}}',
            'body' => 'Dear student, your payment of RM{{amount}} is due on {{due_date}}.',
            'status' => 'active',
        ]);

        $service = app(NotificationService::class);
        $user = User::factory()->create();

        $notification = $service->createFromTemplate(
            $user,
            NotificationType::PAYMENT_REMINDER,
            [
                'class_name' => 'Form 5 Physics',
                'amount' => '75.00',
                'due_date' => '2026-10-15',
            ]
        );

        $this->assertSame('Payment Reminder for Form 5 Physics', $notification->title);
        $this->assertSame('Dear student, your payment of RM75.00 is due on 2026-10-15.', $notification->message);
    }

    // 13. Unsupported/missing template variables are handled safely.
    public function test_missing_template_variables_are_handled_safely(): void
    {
        $service = app(NotificationService::class);

        $rendered = $service->renderTemplate(
            'Hello {{name}}, your balance is {{balance}} and status is {{status}}',
            ['name' => 'Ahmad']
        );

        $this->assertSame('Hello Ahmad, your balance is {{balance}} and status is {{status}}', $rendered);
    }

    // 14. Related payment information is correctly referenced.
    public function test_related_model_is_correctly_referenced(): void
    {
        $service = app(NotificationService::class);
        $user = User::factory()->create();
        $payment = Payment::factory()->create();

        $notification = $service->createNotification(
            $user,
            NotificationType::PAYMENT_SUCCESS,
            'Payment Received',
            'Payment complete.',
            null,
            $payment
        );

        $this->assertSame(Payment::class, $notification->related_type);
        $this->assertSame($payment->id, $notification->related_id);
        $this->assertInstanceOf(Payment::class, $notification->related);
        $this->assertSame($payment->id, $notification->related->id);
    }

    // 15. Sensitive information is not exposed in notification resource.
    public function test_notification_resource_does_not_expose_sensitive_user_fields(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/v1/notifications/{$notification->id}")->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('remember_token', $content);
    }

    // Extra test: User can mark notification as unread.
    public function test_user_can_mark_notification_as_unread(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id, 'read_at' => now()]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/v1/notifications/{$notification->id}/unread")->assertOk();

        $this->assertNull($notification->fresh()->read_at);
    }
}
