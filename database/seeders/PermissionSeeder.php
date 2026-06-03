<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Initial permission set for Sprint 0 (Foundation).
     *
     * Kept intentionally minimal/coarse-grained. Fine-grained permissions are
     * introduced alongside their features in later sprints.
     */
    public const PERMISSIONS = [
        'view dashboard',
        'manage users',
        'manage roles',
        'manage permissions',
        'manage master data',
        'manage lab orders',
        'manage assignments',
        'manage qc',
        'manage deliveries',
        'manage invoices',
        'manage payments',
        'view reports',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }
}
