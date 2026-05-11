<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'users.view',
        'users.manage_status',
        'users.delete',
        'users.password_reset',
        'events.view',
        'events.publish_toggle',
        'events.delete',
        'guests.view',
        'rsvps.view',
        'notifications.view',
        'reports.view',
        'reports.manage',
        'settings.manage',
        'analytics.view',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $support = Role::query()->firstOrCreate(['name' => 'support', 'guard_name' => 'web']);
        $admin = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $superAdmin = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $support->syncPermissions([
            'users.view',
            'events.view',
            'guests.view',
            'rsvps.view',
            'notifications.view',
            'reports.view',
            'reports.manage',
            'analytics.view',
        ]);

        $adminPermissions = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $p): bool => $p !== 'users.delete'
        ));

        $admin->syncPermissions($adminPermissions);

        $superAdmin->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());
    }
}
