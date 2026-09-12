<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const PERMISSIONS = [
        'organization.view',
        'organization.create',
        'organization.update',
        'organization.delete',
        'organization.manage_admins',
        'class.view',
        'class.create',
        'class.update',
        'class.delete',
        'class.activate',
        'class.deactivate',
        'participant.view',
        'participant.create',
        'participant.update',
        'participant.delete',
        'payment.view',
        'payment.create',
        'payment.update',
        'payment.verify',
        'notification.view',
        'notification.send',
        'report.view',
    ];

    /**
     * @var array<int, string>
     */
    private const PARTICIPANT_PERMISSIONS = [
        'organization.view',
        'class.view',
        'participant.view',
        'payment.view',
        'payment.create',
        'notification.view',
    ];

    /**
     * Seed the default roles, permissions, and role-permission mappings.
     */
    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(
            fn (string $name): array => [$name => Permission::query()->firstOrCreate(['name' => $name])]
        );

        $admin = Role::query()->firstOrCreate(['name' => 'ADMIN'], ['description' => 'Full system access']);
        $student = Role::query()->firstOrCreate(['name' => 'STUDENT'], ['description' => 'Student participant access']);
        $sponsor = Role::query()->firstOrCreate(['name' => 'SPONSOR'], ['description' => 'Sponsor participant access']);

        $admin->permissions()->sync($permissions->pluck('id'));

        $participantPermissionIds = $permissions->only(self::PARTICIPANT_PERMISSIONS)->pluck('id');
        $student->permissions()->sync($participantPermissionIds);
        $sponsor->permissions()->sync($participantPermissionIds);
    }
}
