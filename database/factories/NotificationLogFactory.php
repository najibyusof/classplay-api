<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'channel' => 'push',
            'recipient' => fake()->phoneNumber(),
            'status' => 'pending',
            'provider_message_id' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'error_message' => null,
        ];
    }
}
