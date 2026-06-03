<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Official roles (PROJECT_RULES §17) mapped to their initial permissions.
     *
     * Super Admin is granted every permission. The remaining roles receive only
     * the coarse permissions relevant to their domain. Run after PermissionSeeder.
     */
    public const ROLE_PERMISSIONS = [
        'Super Admin' => '*', // all permissions
        'Admin Lab' => [
            'view dashboard',
            'manage master data',
            'manage lab orders',
            'manage assignments',
            'view reports',
        ],
        'Technician' => [
            'view dashboard',
            'manage assignments',
        ],
        'Quality Control' => [
            'view dashboard',
            'manage qc',
        ],
        'Courier' => [
            'view dashboard',
            'manage deliveries',
        ],
        'Finance' => [
            'view dashboard',
            'manage invoices',
            'manage payments',
            'view reports',
        ],
        'Doctor' => [
            'view dashboard',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            if ($permissions === '*') {
                $role->syncPermissions(PermissionSeeder::PERMISSIONS);
            } else {
                $role->syncPermissions($permissions);
            }
        }
    }
}
