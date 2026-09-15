<?php

namespace Database\Factories;

use App\Models\ClassModel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassModel>
 */
class ClassModelFactory extends Factory
{
    protected $model = ClassModel::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'teacher_name' => fake()->name(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => fake()->time('H:i'),
            'frequency' => fake()->randomElement(['weekly', 'fortnightly', 'monthly']),
            'payment_amount' => fake()->randomElement([30, 50, 80, 100, 150]),
            'status' => 'active',
            'start_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'end_date' => null,
            'created_by' => User::factory()->state(['user_type' => 'admin']),
        ];
    }
}
