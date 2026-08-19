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
        // LAB-WORKFLOW-V2 Phase 2 — Cabang lab request + courier pickup
        'create_lab_branch_requests',
        'manage_lab_pickups',
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
        // LAB-PROD-2 — Operational Analytics & KPI (read-only)
        'view_lab_operational_analytics',
        'view_own_lab_operational_analytics',
        // LAB-PROD-3 — Technician Capacity Planning
        'view_lab_technician_capacity',
        'view_own_lab_technician_capacity',
        'manage_lab_technician_capacity',
        'export_lab_technician_capacity',
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
        'view_inventory_executive_dashboard',
        'view_inventory_cross_branch_analytics',
        'view_inventory_activity_log',
        'view_stock_transfer',
        'manage_stock_transfer',
        'download_stock_transfer_checklist',
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
        // Sprint 19 - Clinic Master Data
        'view_clinic_master_data',
        'manage_clinic_master_data',
        // Sprint 20 - RME: Clinic Visit Queue
        'view_clinic_visits',
        'manage_clinic_visits',
        // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-05) — "Selesai Pemeriksaan".
        // Separated from manage_clinic_visits so the front office keeps
        // registration and room placement while only clinically responsible
        // roles may close a doctor's examination and hand it to the cashier.
        'complete_rme_examination',
        // Sprint 20 Phase 1.10 — RME Cashier Billing
        'manage_rme_billing',
        // Sprint 58.6 — Doctor/Perawat treatment room worklist
        'view_treatment_worklist',
        // Sprint 22 Phase 22.1 — Pilot dashboard hardening
        'view_owner_dashboard',
        'view_branch_dashboard',
        // Sprint 23 Phase 23.5 — Separated RME report access
        'view_rme_patient_reports',
        'view_rme_payment_reports',
        // FIX-PRE-68-45 Scope C — Doctor Performance / Income report. Executive
        // tier sees all doctors; own tier is a linked doctor scoped to self.
        'view_doctor_performance_report',
        'view_own_doctor_performance_report',
        // Sprint 23 Phase 23.7 — Master Data Cabang (RME + Inventory branches)
        'view_branch_master_data',
        'manage_branch_master_data',
        'manage lab orders',
        'manage assignments',
        'manage qc',
        'manage deliveries',
        'manage invoices',
        'manage payments',
        'view reports',
        // ENT-7 — Developer Assistance Console (Super Admin only by default)
        'view_developer_console',
        // SATUSEHAT-1 — Readiness foundation & controlled submission filter.
        // Separate view/review/send + mapping/settings governance permissions.
        // send is intentionally very restricted (no auto-send exists yet).
        'view_satusehat_submissions',
        'review_satusehat_submissions',
        'send_satusehat_submissions',
        'manage_satusehat_mappings',
        'manage_satusehat_settings',
        // SATUSEHAT-4A — credential-independent operational readiness &
        // data-quality workspace. Waivers deliberately have their own
        // permission (hard issues can never be waived at all).
        'view_satusehat_readiness',
        'manage_satusehat_remediation',
        'manage_satusehat_readiness_waivers',
        'manage_structured_diagnoses',
        // SATUSEHAT-4B — structured diagnosis adoption & clinical terminology
        // rollout. Terminology review is separated from master data entry
        // (separation of duties); rollout configuration and the emergency
        // override each have a dedicated permission.
        'review_clinical_terminology',
        'configure_diagnosis_rollout',
        'view_diagnosis_adoption',
        'override_diagnosis_requirement',
        // SATUSEHAT-4C — branch readiness remediation & internal pilot
        // operations. Branch remediation, pilot configuration, pilot approval,
        // and rehearsal each have a dedicated permission (least privilege).
        // NONE of these grant external send / production activation.
        'view_satusehat_branch_readiness',
        'manage_satusehat_branch_remediation',
        'configure_satusehat_internal_pilot',
        'approve_satusehat_internal_pilot',
        'run_satusehat_pilot_rehearsal',
        'view_satusehat_pilot_metrics',

        // SATUSEHAT-4D — multi-branch readiness scale-up & operational governance.
        // Credential-independent; NONE grant external send / production.
        'view_satusehat_multi_branch_readiness',
        'view_satusehat_executive_readiness',
        'manage_satusehat_rollout_waves',
        'approve_satusehat_rollout_wave',
        'promote_satusehat_branch',
        'manage_satusehat_change_control',
        'record_satusehat_uat_signoff',

        // LEGACY-RME-PDF-1A — legacy (historical) RME PDF archive.
        // Deliberately granted to NO role by default: the archive is operated by
        // Super Admin from Master Data RME (Super Admin passes via Gate::before).
        // A later sprint may grant them explicitly once the runtime ships.
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'review_legacy_rme_imports',
        'publish_legacy_rme_imports',
        'void_legacy_rme_imports',

        // LEGACY-RME-PDF-1D — clinical READ of a published archive.
        //
        // A separate permission on purpose. The five above are the INTAKE side
        // (upload, review, publish, retract) held by the Super Admin operator;
        // granting one of them to a doctor just so the archive is readable
        // would hand out archive management with it. This one grants reading
        // and nothing else, and it is deliberately NOT in
        // LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS, so its holder stays
        // pinned to their own BranchContext branch instead of widening to
        // every RME branch.
        'view_legacy_rme_archive',

        // LEGACY-RME-PDF-ROLL-4 — migration OPERATIONS (the rollout control
        // plane), deliberately separate from the import lifecycle above.
        //
        // Running a wave is a governance duty, not an intake duty. Keeping them
        // apart lets an owner read the operational picture — counts, quota,
        // backlog, reconciliation — without gaining any ability to upload,
        // review or publish a clinical document, and conversely lets an intake
        // operator do their job without being able to open a branch or close a
        // wave.
        //
        // `approve` is split from `manage` so separation of duties has
        // something to enforce: with `require_separate_approver` on, the
        // approver must also differ from the wave's creator.
        //
        // Granted to NO role by default, like the 1A set: the pilot is operated
        // by Super Admin (who passes via Gate::before), and widening this is an
        // explicit later decision rather than a side effect of shipping.
        'view_legacy_rme_migration_operations',
        'manage_legacy_rme_migration_operations',
        'approve_legacy_rme_migration_wave',
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
