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
        // FIX-ADMIN-LAB-LAB-ONLY-ACCESS (2026-07-11) — Admin Lab is a LAB-ONLY
        // operational role. It receives ONLY permissions inside the Lab module and
        // Lab Workflow V2 (orders, cabang requests, pickup oversight, receive at
        // lab, model analysis, technician assignment, production, QC, external lab,
        // delivery, evidence, lab notifications) plus the legacy dental-lab billing
        // + reporting module (all Lab-scoped). It MUST NOT hold any RME, Inventory,
        // Procurement, master-data (patients/doctors/clinics/branches/treatments),
        // owner/branch-dashboard, dev-console, or system permission. Shared patient/
        // doctor/branch labels are surfaced through Lab services, not CRUD grants.
        // The removed non-Lab permissions are catalogued in ADMIN_LAB_REVOKED_NON_LAB
        // below so the repair/audit command can strip them from live accounts.
        // QC segregation of duty (a producing technician cannot pass/fail their own
        // QC) is enforced by the Lab Workflow V2 state machine, not by withholding
        // Admin Lab's QC permissions.
        'Admin Lab' => [
            // Legacy dental-lab reporting dashboard landing gate (reports.dashboard)
            'view_dashboard',
            // Master Lab data (Lab domain only)
            'manage lab services',
            'manage technicians',
            // Lab orders (legacy + V2)
            'manage lab orders',
            'manage_lab_orders',
            'view_lab_orders',
            'create_lab_orders',
            'update_lab_orders',
            'cancel_lab_orders',
            // LAB-WORKFLOW-V2 Phase 2 — cabang request + pickup oversight + receive
            'create_lab_branch_requests',
            'manage_lab_pickups',
            // Production
            'manage_production',
            'view_production',
            'assign_technicians',
            'reassign_technicians',
            'start_production_work',
            'pause_production_work',
            'resume_production_work',
            'complete_production_work',
            'send_to_qc',
            // Quality Control (segregation enforced by the V2 state machine)
            'manage_quality_control',
            'view_quality_control',
            'start_qc',
            'pass_qc',
            'reject_qc',
            'request_remake',
            'update_qc_checklist',
            'upload_qc_evidence',
            // Delivery
            'manage_delivery',
            'view_delivery',
            'create_delivery',
            'assign_courier',
            'start_delivery',
            'mark_delivered',
            'complete_delivery',
            'upload_pod',
            // Legacy dental-lab billing (Lab-domain invoice/payment — NOT RME cashier)
            'manage_invoice',
            'view_invoice',
            'create_invoice',
            'issue_invoice',
            'void_invoice',
            'manage_payment',
            'view_payment',
            'create_payment',
            // Legacy dental-lab reporting module (all Lab-scoped)
            'manage_report',
            'view_order_report',
            'view_production_report',
            'view_qc_report',
            'view_delivery_report',
            'view_invoice_report',
            'view_payment_report',
            'export_report',
            // Legacy coarse Lab permissions
            'manage assignments',
            'view reports',
        ],
        'Admin Klinik' => [
            'view dashboard',
            'view_branch_dashboard',
            'manage patients',
            // LAB-WORKFLOW-V2 Phase 2 — Cabang lab pickup request
            'create_lab_branch_requests',
            'view_clinic_master_data',
            // Sprint 20 - RME: Clinic Visit Queue
            'view_clinic_visits',
            'manage_clinic_visits',
            // Sprint 20 Phase 1.10 — RME Cashier Billing
            'manage_rme_billing',
            // Hotfix 66.2.6 — Admin Klinik may view/export/print patient reports only
            'view_rme_patient_reports',
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
            'view_inventory',
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
            'view_inventory',
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
            // LAB-WORKFLOW-V2 Phase 2 — courier pickup leg
            'manage_lab_pickups',
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
            // Sprint 20 Phase 1.12 — RME pilot: doctor workflow (no cashier billing)
            'view_clinic_visits',
            'manage_clinic_visits',
            // Sprint 58.6 — treatment room worklist
            'view_treatment_worklist',
            // FIX-PRE-68-45 Scope C — a doctor sees ONLY their own performance/income.
            'view_own_doctor_performance_report',
        ],
        // Sprint 22 Phase 22.1 — Pilot clinic roles (least-privilege hardening)
        'Owner' => [
            'view dashboard',
            'view_owner_dashboard',
            'manage_report',
            'view_dashboard',
            'view_order_report',
            'view_production_report',
            'view_qc_report',
            'view_delivery_report',
            'view_invoice_report',
            'view_payment_report',
            'view_inventory_executive_dashboard',
            'view_inventory_cross_branch_analytics',
            'view_clinic_visits',
            // Sprint 23 Phase 23.5 — Owner sees both RME report families
            'view_rme_patient_reports',
            'view_rme_payment_reports',
            // FIX-PRE-68-45 Scope C — Owner (executive) sees every doctor's income.
            'view_doctor_performance_report',
            // Sprint 23 Phase 23.7 — Owner manages Master Data Cabang
            'view_branch_master_data',
            'manage_branch_master_data',
        ],
        'Kasir' => [
            'view dashboard',
            'view_clinic_visits',
            'manage_rme_billing',
            // Sprint 23 Phase 23.5 — Kasir may view RME payment reports only
            'view_rme_payment_reports',
        ],
        'Perawat' => [
            'view dashboard',
            'manage patients',
            'view_clinic_visits',
            'manage_clinic_visits',
            // Sprint 58.6 — treatment room worklist
            'view_treatment_worklist',
            // LAB-WORKFLOW-V2 Phase 2 — Cabang lab pickup request
            'create_lab_branch_requests',
        ],
        // Sprint 58.1 — Admin Warehouse: inventory-focused role that lands on
        // the Inventory Executive Dashboard after login (least-privilege).
        'Admin Warehouse' => [
            'view dashboard',
            'view_inventory_executive_dashboard',
            'view_inventory_cross_branch_analytics',
            'view_inventory_activity_log',
            'manage_inventory',
            'view_inventory',
            'download_stock_transfer_checklist',
            'approve_inventory_purchase_request',
            'approve_inventory_purchase_order',
        ],
        // FIX-PRE-68-45 Scope G — Kepala Cabang: branch PR flow ONLY. Can create /
        // edit / submit a branch Purchase Request (Reguler or Darurat) and view PRs,
        // but CANNOT create Purchase Orders. Intentionally EXCLUDES
        // manage_purchase_order, manage_inventory, manage master data, and the
        // approve_* permissions so the PurchaseOrderPolicy::create chokepoint denies
        // PO creation server-side. Scoped to one branch via users.branch_id.
        'Kepala Cabang' => [
            'view dashboard',
            'view_inventory',
            'view_purchase_request',
            'manage_purchase_request',
        ],
        // Hotfix — Supervisor RME: full access to the entire RME module only.
        // Aggregates every permission that gates an RME route (clinic visits,
        // medical record, odontogram, treatment worklist, cashier/receivable/
        // follow-up billing, RME reports, patient audit) plus `manage patients`
        // for patient register/edit + KTP scan documents that feed the RME
        // workflow. Intentionally excludes Lab, Inventory, Procurement, Access
        // Control, Owner/branch dashboards, and system settings.
        'Supervisor RME' => [
            'view dashboard',
            // Patient registration/edit + KTP scan documents (RME entry point)
            'manage patients',
            // Clinic visit queue, RM, odontogram, print bundle, room assignment
            'view_clinic_visits',
            'manage_clinic_visits',
            // Doctor/Perawat treatment room worklist
            'view_treatment_worklist',
            // Cashier RME: billing, payment, receivables, follow-ups
            'manage_rme_billing',
            // RME reports + patient data completeness audit
            'view_rme_patient_reports',
            'view_rme_payment_reports',
            // FIX-PRE-68-45 Scope C — Supervisor RME (executive) sees doctor income.
            'view_doctor_performance_report',
        ],
        // Sprint 23 Phase 23.5 — Dedicated separated RME report viewers
        'Laporan Pasien RME' => [
            'view dashboard',
            'view_rme_patient_reports',
        ],
        'Laporan Pembayaran RME' => [
            'view dashboard',
            'view_rme_payment_reports',
        ],
    ];

    /**
     * FIX-ADMIN-LAB-LAB-ONLY-ACCESS — the non-Lab permissions that were historically
     * granted to Admin Lab and are now revoked. Kept as a canonical list so the
     * `rbac:admin-lab-lab-only-audit` command can strip these from any live Admin Lab
     * account (direct or role-inherited) without guessing. These permission
     * DEFINITIONS remain in PermissionSeeder — they are still used by other roles;
     * only Admin Lab's grant of them is removed.
     */
    public const ADMIN_LAB_REVOKED_NON_LAB = [
        'view dashboard',
        'view_branch_dashboard',
        'manage master data',
        'manage clinics',
        'manage doctors',
        'manage patients',
        'view_clinic_master_data',
        'manage_clinic_master_data',
        'view_clinic_visits',
        'manage_clinic_visits',
        'manage_rme_billing',
        'manage_inventory',
        'view_inventory',
        'view_inventory_executive_dashboard',
        'view_inventory_cross_branch_analytics',
        'view_inventory_activity_log',
        'download_stock_transfer_checklist',
        'approve_inventory_purchase_request',
        'approve_inventory_purchase_order',
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
