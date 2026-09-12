<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationAdmin>
 */
class OrganizationAdminFactory extends Factory
{
    protected $model = OrganizationAdmin::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory()->state(['user_type' => 'admin']),
            'is_primary' => false,
            'status' => 'active',
        ];
    }
}
