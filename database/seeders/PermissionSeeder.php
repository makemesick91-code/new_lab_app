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
        'manage clinics',
        'manage doctors',
        'manage patients',
        'manage lab services',
        'manage technicians',
        // Sprint 3 — Lab Order (underscore convention per sprint_3_technical_design.md)
        'manage_lab_orders',
        'view_lab_orders',
        'create_lab_orders',
        'update_lab_orders',
        'cancel_lab_orders',
        // Sprint 4 — Production Workflow
        'manage_production',
        'view_production',
        'assign_technicians',
        'reassign_technicians',
        'start_production_work',
        'pause_production_work',
        'resume_production_work',
        'complete_production_work',
        'send_to_qc',
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
