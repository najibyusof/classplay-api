<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserDevice>
 */
class UserDeviceFactory extends Factory
{
    protected $model = UserDevice::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_token' => fake()->uuid(),
            'platform' => fake()->randomElement(['android', 'ios']),
            'device_name' => fake()->word(),
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
            'status' => 'active',
        ];
    }
}
