<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'payment.reminder',
            'title' => fake()->sentence(3),
            'message' => fake()->sentence(),
            'data' => null,
            'related_type' => null,
            'related_id' => null,
            'read_at' => null,
            'sent_at' => now(),
        ];
    }
}
