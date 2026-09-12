<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests using RefreshDatabase get roles/permissions seeded automatically,
     * since policies authorize via RBAC rather than user_type alone.
     */
    protected $seed = true;

    protected string $seeder = RolePermissionSeeder::class;
}
