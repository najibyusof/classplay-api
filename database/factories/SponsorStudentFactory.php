<?php

namespace Database\Factories;

use App\Models\SponsorStudent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SponsorStudent>
 */
class SponsorStudentFactory extends Factory
{
    protected $model = SponsorStudent::class;

    public function definition(): array
    {
        return [
            'sponsor_id' => User::factory()->state(['user_type' => 'sponsor']),
            'student_id' => User::factory()->state(['user_type' => 'student']),
            'relationship_type' => fake()->randomElement(['parent', 'guardian', 'relative']),
            'status' => 'active',
            'start_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'end_date' => null,
        ];
    }
}
