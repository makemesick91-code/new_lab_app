<?php

use App\Modules\Branch\Models\Branch;
use Database\Seeders\BranchSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

/**
 * Every permission that gates an RME route. Supervisor RME must hold all of them.
 */
const SUPERVISOR_RME_PERMISSIONS = [
    'view dashboard',
    'manage patients',
    'view_clinic_visits',
    'manage_clinic_visits',
    'complete_rme_examination',
    'send_prescription_whatsapp',
    'view_treatment_worklist',
    'manage_rme_billing',
    'view_rme_patient_reports',
    'view_rme_payment_reports',
    // FIX-PRE-68-45 Scope C — Supervisor RME (executive) sees doctor income.
    'view_doctor_performance_report',
    // SATUSEHAT-1 — RME operational owner of the controlled submission filter
    // + mapping/identifier governance. (The exact-list pin below was stale
    // since SATUSEHAT-1; repinned in SATUSEHAT-4A.)
    'view_satusehat_submissions',
    'review_satusehat_submissions',
    'send_satusehat_submissions',
    'manage_satusehat_mappings',
    'manage_satusehat_settings',
    // SATUSEHAT-4A — readiness/data-quality workspace ownership.
    'view_satusehat_readiness',
    'manage_satusehat_remediation',
    'manage_satusehat_readiness_waivers',
    'manage_structured_diagnoses',
    // SATUSEHAT-4B — terminology reviewer + rollout owner + adoption analytics
    // + emergency override authority (separation of duties stays server-side).
    'review_clinical_terminology',
    'configure_diagnosis_rollout',
    'view_diagnosis_adoption',
    'override_diagnosis_requirement',
    // SATUSEHAT-4C — RME operational owner of branch readiness & internal pilot.
    'view_satusehat_branch_readiness',
    'manage_satusehat_branch_remediation',
    'configure_satusehat_internal_pilot',
    'approve_satusehat_internal_pilot',
    'run_satusehat_pilot_rehearsal',
    'view_satusehat_pilot_metrics',
    // SATUSEHAT-4D
    'view_satusehat_multi_branch_readiness',
    'view_satusehat_executive_readiness',
    'manage_satusehat_rollout_waves',
    'approve_satusehat_rollout_wave',
    'promote_satusehat_branch',
    'manage_satusehat_change_control',
    'record_satusehat_uat_signoff',
    // LEGACY-RME-PDF-ROLL-4-WAVE-1 — the checker half of the legacy archive
    // maker-checker pair, plus wave approval authority. Note what is absent:
    // `create_legacy_rme_imports` (that is the intake operator's) and
    // `manage_legacy_rme_migration_operations` (a wave creator may not also
    // approve their own wave). Both omissions are load-bearing.
    'view_legacy_rme_imports',
    'review_legacy_rme_imports',
    'publish_legacy_rme_imports',
    'view_legacy_rme_migration_operations',
    'approve_legacy_rme_migration_wave',
];

it('creates the Supervisor RME role after seeding', function () {
    expect(Role::where('name', 'Supervisor RME')->where('guard_name', 'web')->exists())->toBeTrue();
});

it('is idempotent — re-running the seeder keeps a single role with the same permissions', function () {
    seedAccessControl();
    seedAccessControl();

    expect(Role::where('name', 'Supervisor RME')->count())->toBe(1);

    $role = Role::findByName('Supervisor RME');

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(SUPERVISOR_RME_PERMISSIONS)->sort()->values()->all());
});

it('grants Supervisor RME every RME-related permission', function () {
    $role = Role::findByName('Supervisor RME');

    foreach (SUPERVISOR_RME_PERMISSIONS as $permission) {
        expect($role->hasPermissionTo($permission))->toBeTrue("missing RME permission: {$permission}");
    }
});

it('does not grant Supervisor RME unrelated Lab module permissions', function () {
    $role = Role::findByName('Supervisor RME');

    foreach (['view_lab_orders', 'manage_lab_orders', 'create_lab_orders', 'manage_production', 'manage_quality_control'] as $permission) {
        expect($role->hasPermissionTo($permission))->toBeFalse("unexpected lab permission: {$permission}");
    }
});

it('does not grant Supervisor RME unrelated Inventory or Procurement permissions', function () {
    $role = Role::findByName('Supervisor RME');

    foreach (['view_inventory', 'manage_inventory', 'view_inventory_executive_dashboard', 'manage_purchase_request', 'manage_purchase_order', 'manage_goods_receipt'] as $permission) {
        expect($role->hasPermissionTo($permission))->toBeFalse("unexpected inventory/procurement permission: {$permission}");
    }
});

it('does not grant Supervisor RME Access Control, Owner dashboard, or branch master data', function () {
    $role = Role::findByName('Supervisor RME');

    foreach (['manage users', 'manage roles', 'manage permissions', 'view_owner_dashboard', 'manage_branch_master_data'] as $permission) {
        expect($role->hasPermissionTo($permission))->toBeFalse("unexpected admin permission: {$permission}");
    }
});

it('lets a Supervisor RME user reach representative RME pages', function () {
    $user = userInRole('Supervisor RME');

    $this->actingAs($user)->get(route('rme.dashboard'))->assertOk();
    $this->actingAs($user)->get(route('rme.visits.index'))->assertOk();
    $this->actingAs($user)->get(route('rme.visits.create'))->assertOk();
    $this->actingAs($user)->get(route('rme.patient-queue.index'))->assertOk();
    $this->actingAs($user)->get(route('rme.treatment-room-worklist.index'))->assertOk();
    $this->actingAs($user)->get(route('rme.medical-records.index'))->assertOk();
    $this->actingAs($user)->get(route('rme.cashier.index'))->assertOk();
    $this->actingAs($user)->get(route('rme.cashier.receivables'))->assertOk();
    $this->actingAs($user)->get(route('rme.reports.patients'))->assertOk();
    $this->actingAs($user)->get(route('rme.reports.payments'))->assertOk();
    $this->actingAs($user)->get(route('settings.patients.index'))->assertOk();
    $this->actingAs($user)->get(route('settings.patients.create'))->assertOk();
    $this->actingAs($user)->get(route('rme.patients.audit'))->assertOk();
});

it('forbids a Supervisor RME user from unrelated Lab and Inventory pages', function () {
    $user = userInRole('Supervisor RME');

    $this->actingAs($user)->get(route('lab-orders.index'))->assertForbidden();
    $this->actingAs($user)->get(route('lab-case-candidates.index'))->assertForbidden();
});
