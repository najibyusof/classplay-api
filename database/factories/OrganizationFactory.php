<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => fake()->unique()->bothify('ORG-####'),
            'description' => fake()->sentence(),
            'logo_path' => null,
            'status' => 'active',
            'created_by' => User::factory()->state(['user_type' => 'admin']),
        ];
    }
}
