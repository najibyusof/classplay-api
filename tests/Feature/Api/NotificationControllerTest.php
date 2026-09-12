<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_only_their_own_notifications(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(2)->create(['user_id' => $user->id]);
        Notification::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications');

        $response->assertOk()->assertJsonCount(2, 'data.notifications');
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $notification = Notification::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')
            ->putJson("/api/v1/notifications/{$notification->id}", [])
            ->assertForbidden();
    }

    public function test_user_can_mark_their_own_notification_as_read(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id, 'read_at' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/notifications/{$notification->id}", []);

        $response->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }
}
