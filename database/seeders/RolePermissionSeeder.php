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
        'templates.manage',
        'faqs.manage',
        'reviews.manage',
        'ticketing.view',
        'ticketing.approve',
        'ticketing.payouts.manage',
        'settings.manage',
        'analytics.view',
        'payments.view',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['web', 'admin'] as $guardName) {
            $this->seedRolesAndPermissionsForGuard($guardName);
        }
    }

    private function seedRolesAndPermissionsForGuard(string $guardName): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => $guardName]);
        }

        $support = Role::query()->firstOrCreate(['name' => 'support', 'guard_name' => $guardName]);
        $admin = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => $guardName]);
        $superAdmin = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => $guardName]);

        $support->syncPermissions([
            'users.view',
            'events.view',
            'guests.view',
            'rsvps.view',
            'notifications.view',
            'reports.view',
            'reports.manage',
            'analytics.view',
            'payments.view',
            'ticketing.view',
        ]);

        $adminPermissions = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $p): bool => $p !== 'users.delete'
        ));

        $admin->syncPermissions($adminPermissions);

        $superAdmin->syncPermissions(
            Permission::query()->where('guard_name', $guardName)->pluck('name')->all()
        );
    }
}
