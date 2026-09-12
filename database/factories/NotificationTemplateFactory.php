<?php

namespace Database\Factories;

use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'notification_type' => 'payment.reminder',
            'channel' => 'push',
            'subject' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'status' => 'active',
        ];
    }
}
