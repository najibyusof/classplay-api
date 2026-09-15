<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // WithoutModelEvents suppresses the User::saved role-sync hook during
        // seeding; DemoDataSeeder syncs roles for its accounts explicitly.
        $this->call(DemoDataSeeder::class);
    }
}
