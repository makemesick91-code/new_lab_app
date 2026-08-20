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
            // LAB-PROD-2 — Operational Analytics & KPI (Lab-scoped, read-only)
            'view_lab_operational_analytics',
            // LAB-PROD-3 — Technician Capacity Planning (Lab-scoped)
            'view_lab_technician_capacity',
            'manage_lab_technician_capacity',
            'export_lab_technician_capacity',
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
            // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — capture and read the
            // signed PERSETUJUAN TINDAKAN MEDIS that gates RME payment.
            'view_rme_consents',
            'manage_rme_consents',
            // Hotfix 66.2.6 — Admin Klinik may view/export/print patient reports only
            'view_rme_patient_reports',
            // SATUSEHAT-4A — limited branch-scoped remediation (patient data
            // completeness) — no waiver, no mapping governance, no send.
            'view_satusehat_readiness',
            'manage_satusehat_remediation',
            // SATUSEHAT-4C — branch-scoped readiness + remediation only.
            // No pilot configuration/approval/rehearsal (those are governance).
            'view_satusehat_branch_readiness',
            'manage_satusehat_branch_remediation',
            // SATUSEHAT-4D — read the comparative multi-branch matrix (own scope).
            'view_satusehat_multi_branch_readiness',
            // LEGACY-RME-PDF-ROLL-4-WAVE-1 — the branch INTAKE OPERATOR for a
            // legacy archive migration wave. Upload the historical PDF and see
            // the imports for the branch this account is pinned to; nothing more.
            //
            // Deliberately NOT granted here: review, publish, void, and every
            // migration-operations permission. Intake is the "maker" half of a
            // maker-checker pair — the account that files a document must not
            // also be the one that certifies and publishes it, nor the one that
            // approves the wave it is filing under.
            //
            // This is not the security boundary on its own. A holder still has
            // to pass, server-side: the feature flag, ROLL-3 branch admission,
            // the wave operator assignment for the RM-DERIVED branch, the
            // workspace branch scope, and the date/duplicate rules.
            'view_legacy_rme_imports',
            'create_legacy_rme_imports',
        ],
        'Technician' => [
            'view dashboard',
            'manage assignments',
            'view_lab_orders',
            // LAB-PROD-2 — technician self-scope operational KPI (own data only)
            'view_own_lab_operational_analytics',
            // LAB-PROD-3 — technician self-scope capacity planning (own data only)
            'view_own_lab_technician_capacity',
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
            'complete_rme_examination',
            'send_prescription_whatsapp',
            // Sprint 58.6 — treatment room worklist
            'view_treatment_worklist',
            // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — capture and read the
            // signed PERSETUJUAN TINDAKAN MEDIS that gates RME payment.
            'view_rme_consents',
            'manage_rme_consents',
            // FIX-PRE-68-45 Scope C — a doctor sees ONLY their own performance/income.
            'view_own_doctor_performance_report',
            // SATUSEHAT-4B — a doctor may use the reasoned, audited emergency
            // override on a pilot_enforced branch so patient care is never
            // blocked; the missing-diagnosis issue stays open for review.
            'override_diagnosis_requirement',
            // LEGACY-RME-PDF-1D — a doctor may READ a patient's published
            // historical archive while treating them, scoped to the branches
            // they practise in. Read only: this grants no upload, review,
            // publish or void, and it is not a governance permission.
            //
            // LEGACY-RME-PDF-HISTORY-1B — that branch scope is the doctor's
            // assigned practice set (mst_doctor_branches ∩ active RME-enabled
            // branches), not a single BranchContext branch: doctors are
            // multi-branch, and resolving them through the operator's current
            // working branch both denied real reads (no online session falls
            // back to MAIN) and ignored their other practice branches. The
            // treating-relationship gate still applies on top, so this is never
            // wider than the patient's native record.
            'view_legacy_rme_archive',
        ],
        // Sprint 22 Phase 22.1 — Pilot clinic roles (least-privilege hardening)
        'Owner' => [
            'view dashboard',
            'view_owner_dashboard',
            // LAB-PROD-2 — executive cross-branch operational KPI (read-only)
            'view_lab_operational_analytics',
            // LAB-PROD-3 — executive cross-branch capacity planning (read-only)
            'view_lab_technician_capacity',
            'export_lab_technician_capacity',
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
            // SATUSEHAT-1 — executive oversight of the controlled submission
            // filter: view + review only (no send, no mapping/settings governance).
            'view_satusehat_submissions',
            'review_satusehat_submissions',
            // SATUSEHAT-4A — executive read-only view of operational readiness.
            'view_satusehat_readiness',
            // SATUSEHAT-4B — executive read-only view of diagnosis adoption.
            'view_diagnosis_adoption',
            // SATUSEHAT-4C — executive read-only view of branch readiness +
            // pilot operations metrics (no pilot configuration/approval).
            'view_satusehat_branch_readiness',
            'view_satusehat_pilot_metrics',
            // SATUSEHAT-4D — read-only executive/owner aggregate + matrix.
            'view_satusehat_multi_branch_readiness',
            'view_satusehat_executive_readiness',
            // LEGACY-RME-PDF-ROLL-4-WAVE-1 — read-only oversight of a migration
            // wave: counts, quotas, branch labels and reconciliation codes. The
            // operations dashboard carries no clinical content, so this gives an
            // owner visibility into a rollout without any ability to change it.
            //
            // Read only by construction: no upload, no review, no publish, no
            // void, no wave management, and no approval authority.
            'view_legacy_rme_migration_operations',
        ],
        'Kasir' => [
            'view dashboard',
            'view_clinic_visits',
            'manage_rme_billing',
            // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — capture and read the
            // signed PERSETUJUAN TINDAKAN MEDIS that gates RME payment.
            'view_rme_consents',
            'manage_rme_consents',
            // Sprint 23 Phase 23.5 — Kasir may view RME payment reports only
            'view_rme_payment_reports',
        ],
        'Perawat' => [
            'view dashboard',
            'manage patients',
            'view_clinic_visits',
            'manage_clinic_visits',
            'complete_rme_examination',
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
            'complete_rme_examination',
            'send_prescription_whatsapp',
            // Doctor/Perawat treatment room worklist
            'view_treatment_worklist',
            // Cashier RME: billing, payment, receivables, follow-ups
            'manage_rme_billing',
            // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — capture and read the
            // signed PERSETUJUAN TINDAKAN MEDIS that gates RME payment.
            'view_rme_consents',
            'manage_rme_consents',
            // RME reports + patient data completeness audit
            'view_rme_patient_reports',
            'view_rme_payment_reports',
            // FIX-PRE-68-45 Scope C — Supervisor RME (executive) sees doctor income.
            'view_doctor_performance_report',
            // SATUSEHAT-1 — RME operational owner of the controlled submission
            // filter + mapping/identifier governance (view/review/send/manage).
            'view_satusehat_submissions',
            'review_satusehat_submissions',
            'send_satusehat_submissions',
            'manage_satusehat_mappings',
            'manage_satusehat_settings',
            // SATUSEHAT-4A — RME operational owner of the readiness/data-quality
            // workspace: remediation + waivers + structured diagnosis master.
            'view_satusehat_readiness',
            'manage_satusehat_remediation',
            'manage_satusehat_readiness_waivers',
            'manage_structured_diagnoses',
            // SATUSEHAT-4B — clinical terminology reviewer + rollout owner +
            // adoption analytics + emergency override authority. Self-approval
            // stays impossible: separation of duties is enforced server-side.
            'review_clinical_terminology',
            'configure_diagnosis_rollout',
            'view_diagnosis_adoption',
            'override_diagnosis_requirement',
            // SATUSEHAT-4C — RME operational owner of branch readiness & the
            // internal pilot: full remediation, pilot configuration/approval,
            // rehearsal, and metrics. Still NO external send / production.
            'view_satusehat_branch_readiness',
            'manage_satusehat_branch_remediation',
            'configure_satusehat_internal_pilot',
            'approve_satusehat_internal_pilot',
            'run_satusehat_pilot_rehearsal',
            'view_satusehat_pilot_metrics',
            // SATUSEHAT-4D — full multi-branch scale-up & operational governance.
            // Still NO external send / production.
            'view_satusehat_multi_branch_readiness',
            'view_satusehat_executive_readiness',
            'manage_satusehat_rollout_waves',
            'approve_satusehat_rollout_wave',
            'promote_satusehat_branch',
            'manage_satusehat_change_control',
            'record_satusehat_uat_signoff',
            // LEGACY-RME-PDF-ROLL-4-WAVE-1 — the CHECKER half of the legacy
            // migration maker-checker pair, and the wave's governance approver.
            //
            // Reviews and publishes what an intake operator filed, and signs a
            // wave as governance-approved. `approve_legacy_rme_migration_wave`
            // is deliberately split from `manage_legacy_rme_migration_operations`
            // (which this role does NOT get) so the separation-of-duties rule
            // has something to enforce: with LEGACY_RME_REQUIRE_SEPARATE_APPROVER
            // on, the approver must differ from the wave's creator, and that is
            // checked server-side in LegacyRmeWaveGovernanceService::approve().
            //
            // Withholding `manage` is the point, not an oversight — a role that
            // could both create and approve a wave would satisfy the letter of
            // the rule while defeating it.
            'view_legacy_rme_imports',
            'review_legacy_rme_imports',
            'publish_legacy_rme_imports',
            'view_legacy_rme_migration_operations',
            'approve_legacy_rme_migration_wave',
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
