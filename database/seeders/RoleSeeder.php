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
            'manage clinics',
            'manage doctors',
            'manage patients',
            'manage lab services',
            'manage technicians',
            'manage lab orders',
            'manage_lab_orders',
            'view_lab_orders',
            'create_lab_orders',
            'update_lab_orders',
            'cancel_lab_orders',
            'manage_production',
            'view_production',
            'assign_technicians',
            'reassign_technicians',
            'start_production_work',
            'pause_production_work',
            'resume_production_work',
            'complete_production_work',
            'send_to_qc',
            'manage_quality_control',
            'view_quality_control',
            'start_qc',
            'pass_qc',
            'reject_qc',
            'request_remake',
            'update_qc_checklist',
            'upload_qc_evidence',
            'manage_delivery',
            'view_delivery',
            'create_delivery',
            'assign_courier',
            'start_delivery',
            'mark_delivered',
            'complete_delivery',
            'upload_pod',
            'manage_invoice',
            'view_invoice',
            'create_invoice',
            'issue_invoice',
            'void_invoice',
            'manage_payment',
            'view_payment',
            'create_payment',
            'manage_report',
            'view_dashboard',
            'view_order_report',
            'view_production_report',
            'view_qc_report',
            'view_delivery_report',
            'view_invoice_report',
            'view_payment_report',
            'export_report',
            'manage assignments',
            'view reports',
        ],
        'Technician' => [
            'view dashboard',
            'manage assignments',
            'view_lab_orders',
            'view_production',
            'start_production_work',
            'pause_production_work',
            'resume_production_work',
            'complete_production_work',
            'send_to_qc',
            'view_quality_control',
            'view_delivery',
            'view_invoice',
            'view_payment',
            'view_dashboard',
            'view_production_report',
        ],
        'Quality Control' => [
            'view dashboard',
            'manage qc',
            'view_lab_orders',
            'view_production',
            'view_quality_control',
            'start_qc',
            'pass_qc',
            'reject_qc',
            'request_remake',
            'update_qc_checklist',
            'upload_qc_evidence',
            'view_delivery',
            'view_invoice',
            'view_payment',
            'view_dashboard',
            'view_qc_report',
        ],
        'Delivery Coordinator' => [
            'view dashboard',
            'view_lab_orders',
            'manage_delivery',
            'view_delivery',
            'create_delivery',
            'assign_courier',
            'start_delivery',
            'mark_delivered',
            'complete_delivery',
            'upload_pod',
            'view_invoice',
            'view_payment',
            'view_dashboard',
            'view_delivery_report',
        ],
        'Courier' => [
            'view dashboard',
            'manage deliveries',
            'view_lab_orders',
            'view_delivery',
            'start_delivery',
            'mark_delivered',
            'upload_pod',
        ],
        'Finance' => [
            'view dashboard',
            'manage invoices',
            'manage payments',
            'manage_invoice',
            'view_invoice',
            'create_invoice',
            'issue_invoice',
            'void_invoice',
            'manage_payment',
            'view_payment',
            'create_payment',
            'view reports',
            'view_lab_orders',
            'view_dashboard',
            'view_invoice_report',
            'view_payment_report',
            'export_report',
        ],
        'Doctor' => [
            'view dashboard',
            'view_lab_orders',
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
