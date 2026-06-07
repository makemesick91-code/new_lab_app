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
        // Sprint 5 — Quality Control
        'manage_quality_control',
        'view_quality_control',
        'start_qc',
        'pass_qc',
        'reject_qc',
        'request_remake',
        'update_qc_checklist',
        'upload_qc_evidence',
        // Sprint 6 - Delivery & POD
        'manage_delivery',
        'view_delivery',
        'create_delivery',
        'assign_courier',
        'start_delivery',
        'mark_delivered',
        'complete_delivery',
        'upload_pod',
        // Sprint 7 - Invoice & Payment
        'manage_invoice',
        'view_invoice',
        'create_invoice',
        'issue_invoice',
        'void_invoice',
        'manage_payment',
        'view_payment',
        'create_payment',
        // Sprint 8 - Reporting & Dashboard
        'manage_report',
        'view_dashboard',
        'view_order_report',
        'view_production_report',
        'view_qc_report',
        'view_delivery_report',
        'view_invoice_report',
        'view_payment_report',
        'export_report',
        // Sprint 12 - Inventory Core
        'manage_inventory',
        'view_inventory',
        // Sprint 13+ - Inventory granular permissions (role assignment UI)
        'view_stock_opname',
        'manage_stock_opname',
        'view_inventory_batch_lot',
        'manage_inventory_batch_lot',
        'view_stock_alert',
        'manage_stock_alert',
        'view_inventory_analytics',
        'manage_inventory_analytics',
        'view_stock_transfer',
        'manage_stock_transfer',
        // Sprint 16.1 - Purchase Request
        'approve_inventory_purchase_request',
        'view_purchase_request',
        'manage_purchase_request',
        // Sprint 16.2 - Purchase Order
        'approve_inventory_purchase_order',
        'view_purchase_order',
        'manage_purchase_order',
        // Sprint 16.3 - Goods Receipt
        'view_goods_receipt',
        'manage_goods_receipt',
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
