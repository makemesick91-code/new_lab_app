<?php

use App\Support\AccessControl\AdminLabLabOnlyAuditor;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

/**
 * FIX-ADMIN-LAB-LAB-ONLY-ACCESS — the canonical contract that "Admin Lab" is a
 * Lab-module + Lab Workflow V2 role ONLY. Covers: the role permission matrix,
 * effective permissions / no Super Admin bypass, allowed Lab routes (200),
 * forbidden non-Lab direct routes (real 403 at route/policy layer), Lab-only
 * sidebar, the Lab landing redirect, and the audit/repair tooling.
 */
beforeEach(function () {
    seedAccessControl();
    // Lab workspace controllers resolve the active branch via BranchContext, which
    // requires a seeded MAIN branch.
    test()->seed(BranchSeeder::class);
});

// ── A. Role permission matrix ────────────────────────────────────────────────

it('grants Admin Lab every canonical Lab permission and no revoked non-Lab permission', function () {
    $role = Role::findByName('Admin Lab');

    foreach (RoleSeeder::ROLE_PERMISSIONS['Admin Lab'] as $permission) {
        expect($role->hasPermissionTo($permission))->toBeTrue("Admin Lab must keep Lab permission [{$permission}]");
    }

    foreach (RoleSeeder::ADMIN_LAB_REVOKED_NON_LAB as $permission) {
        expect($role->hasPermissionTo($permission))->toBeFalse("Admin Lab must NOT hold revoked non-Lab permission [{$permission}]");
    }
});

it('keeps core Lab workflow permissions on Admin Lab', function () {
    $role = Role::findByName('Admin Lab');

    foreach ([
        'manage_lab_orders', 'view_lab_orders', 'create_lab_branch_requests', 'manage_lab_pickups',
        'manage_production', 'assign_technicians', 'send_to_qc', 'manage_quality_control',
        'pass_qc', 'reject_qc', 'manage_delivery', 'upload_pod', 'manage lab services',
        'manage technicians',
    ] as $permission) {
        expect($role->hasPermissionTo($permission))->toBeTrue();
    }
});

it('removes RME, inventory, procurement, master-data and dashboard permissions from Admin Lab', function () {
    $role = Role::findByName('Admin Lab');

    foreach ([
        'manage_rme_billing', 'view_clinic_visits', 'manage_clinic_visits',
        'manage_inventory', 'view_inventory', 'view_inventory_executive_dashboard',
        'approve_inventory_purchase_request', 'approve_inventory_purchase_order',
        'download_stock_transfer_checklist', 'manage patients', 'manage doctors',
        'manage master data', 'view_clinic_master_data', 'view_branch_dashboard',
        'view dashboard', 'view_developer_console',
    ] as $permission) {
        expect($role->hasPermissionTo($permission))->toBeFalse();
    }
});

// ── B. Effective permissions + no Super Admin bypass ─────────────────────────

it('gives an Admin Lab user no effective non-Lab permission and no Gate::before bypass', function () {
    $user = userInRole('Admin Lab');

    expect($user->hasRole('Super Admin'))->toBeFalse();

    $effective = $user->getAllPermissions()->pluck('name')->all();
    foreach (RoleSeeder::ADMIN_LAB_REVOKED_NON_LAB as $permission) {
        expect(in_array($permission, $effective, true))->toBeFalse();
    }

    // Gate::before only bypasses for Super Admin — an Admin Lab user is fully denied.
    expect($user->can('manage_inventory'))->toBeFalse()
        ->and($user->can('manage_rme_billing'))->toBeFalse()
        ->and($user->can('view_developer_console'))->toBeFalse()
        // ...but keeps its Lab grants.
        ->and($user->can('manage_lab_orders'))->toBeTrue();
});

it('still bypasses every gate for the primary Super Admin', function () {
    $superAdmin = superAdmin();

    expect($superAdmin->can('manage_inventory'))->toBeTrue()
        ->and($superAdmin->can('manage_rme_billing'))->toBeTrue()
        ->and($superAdmin->can('view_developer_console'))->toBeTrue();
});

// ── C. Allowed Lab routes (200) ──────────────────────────────────────────────

it('lets an Admin Lab user open the Lab workspace routes', function () {
    $user = userInRole('Admin Lab');

    foreach ([
        'lab-orders.index',
        'lab-v2-orders.index',
        'lab-workflow-requests.index',
        'lab-pickup-tasks.index',
        'lab-case-candidates.index',
        'production.board',
        'quality-control.queue',
        'notifications.index',
    ] as $routeName) {
        $this->actingAs($user)->get(route($routeName))->assertOk();
    }
});

// ── D. Forbidden non-Lab direct routes (real 403, not sidebar hiding) ────────

it('forbids an Admin Lab user from every non-Lab module route at the authorization layer', function () {
    $user = userInRole('Admin Lab');

    foreach ([
        // RME
        'rme.dashboard', 'rme.visits.index', 'rme.patient-queue.index',
        'rme.medical-records.index', 'rme.cashier.index',
        // Owner / cross-module dashboard
        'dashboard',
        // Inventory + procurement (controller policy layer)
        'inventory.dashboard', 'inventory.products.index', 'inventory.stock.index',
        'inventory.purchase-requests.index', 'inventory.purchase-orders.index',
        'inventory.goods-receipts.index', 'inventory.stock-transfers.index',
        'inventory.stock-opnames.index', 'inventory.reports.index',
        'inventory.executive-dashboard',
        // Master data
        'settings.patients.index', 'settings.doctors.index', 'settings.branches.index',
        'settings.users.index', 'settings.roles.index', 'settings.permissions.index',
        // Developer console
        'developer-console.index',
    ] as $routeName) {
        $this->actingAs($user)->get(route($routeName))->assertForbidden();
    }
});

// ── E. Lab-only sidebar ──────────────────────────────────────────────────────

it('renders only Lab menus in the sidebar for an Admin Lab user', function () {
    $user = userInRole('Admin Lab');
    $this->actingAs($user);

    $html = view('layouts.partials.sidebar')->render();

    expect($html)->toContain('Laboratorium')
        ->and($html)->toContain('Order Lab')
        ->and($html)->toContain('Pipeline Lab V2');

    // Non-Lab groups/items must be absent because their gating permissions were removed.
    expect($html)->not->toContain('Klinik & RME')
        ->and($html)->not->toContain('Inventaris & Pembelian')
        ->and($html)->not->toContain('Dashboard RME')
        ->and($html)->not->toContain('Developer Console');
});

// ── F. Landing redirect ──────────────────────────────────────────────────────

it('lands an Admin Lab user on the Lab Workflow V2 orders workspace after login', function () {
    $user = userInRole('Admin Lab');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('lab-v2-orders.index', absolute: false));
});

// ── G. Audit + repair tooling ────────────────────────────────────────────────

it('reports a clean GO audit for a seeded Lab-only Admin Lab role', function () {
    $auditor = app(AdminLabLabOnlyAuditor::class);
    $report = $auditor->audit();

    expect($report['role_exists'])->toBeTrue()
        ->and($report['role_extra_non_lab'])->toBe([])
        ->and($report['role_missing_lab'])->toBe([])
        ->and($report['summary']['decision'])->toBe('GO');
});

it('detects and strips a revoked non-Lab direct permission from an Admin Lab account', function () {
    $user = userInRole('Admin Lab');
    $user->givePermissionTo('manage_inventory');

    $auditor = app(AdminLabLabOnlyAuditor::class);

    expect($auditor->audit()['summary']['anomaly_codes'])
        ->toContain('admin_lab_user_holds_revoked_direct_permissions');

    $changes = $auditor->stripDirectRevokedFromAdminLabUsers();
    expect($changes)->toHaveCount(1)
        ->and($changes[0]['revoked_direct_permissions'])->toContain('manage_inventory');

    expect($user->fresh()->can('manage_inventory'))->toBeFalse()
        ->and($auditor->audit()['summary']['anomaly_codes'])
        ->not->toContain('admin_lab_user_holds_revoked_direct_permissions');
});

it('flags an Admin Lab user that still holds Super Admin and demotes it safely', function () {
    // A separate primary Super Admin must exist so demotion is allowed.
    $primary = superAdmin();

    $operational = userInRole('Admin Lab');
    $operational->assignRole('Super Admin');

    $auditor = app(AdminLabLabOnlyAuditor::class);
    $report = $auditor->audit();

    expect($report['summary']['critical_codes'])->toContain('admin_lab_user_also_super_admin')
        ->and($report['summary']['decision'])->toBe('WATCH');

    $result = $auditor->demoteSuperAdminToAdminLab($operational->id);

    expect($result['roles_after'])->toContain('Admin Lab')
        ->and($result['roles_after'])->not->toContain('Super Admin');

    expect($operational->fresh()->hasRole('Super Admin'))->toBeFalse();
    expect($primary->fresh()->hasRole('Super Admin'))->toBeTrue();
});

it('refuses to demote the only Super Admin account', function () {
    $onlySuper = userInRole('Admin Lab');
    $onlySuper->assignRole('Super Admin');

    $auditor = app(AdminLabLabOnlyAuditor::class);

    expect(fn () => $auditor->demoteSuperAdminToAdminLab($onlySuper->id))
        ->toThrow(RuntimeException::class);

    expect($onlySuper->fresh()->hasRole('Super Admin'))->toBeTrue();
});

it('re-syncs a drifted Admin Lab role back to the canonical Lab-only set', function () {
    $role = Role::findByName('Admin Lab');
    $role->givePermissionTo('manage_inventory');

    expect($role->fresh()->hasPermissionTo('manage_inventory'))->toBeTrue();

    $auditor = app(AdminLabLabOnlyAuditor::class);
    $result = $auditor->syncRole();

    expect($result['removed'])->toContain('manage_inventory');
    expect(Role::findByName('Admin Lab')->hasPermissionTo('manage_inventory'))->toBeFalse();
});

// ── H. Command ───────────────────────────────────────────────────────────────

it('exits 0 for a clean audit and 2 under --strict when an anomaly exists', function () {
    $this->artisan('rbac:admin-lab-lab-only-audit --json --strict')->assertExitCode(0);

    $user = userInRole('Admin Lab');
    $user->givePermissionTo('manage_inventory');

    $this->artisan('rbac:admin-lab-lab-only-audit --strict')->assertExitCode(2);
});

it('strips a revoked direct permission through the command --strip-direct flag', function () {
    $user = userInRole('Admin Lab');
    $user->givePermissionTo('view_inventory');

    $this->artisan('rbac:admin-lab-lab-only-audit --strip-direct')->assertExitCode(0);

    expect($user->fresh()->can('view_inventory'))->toBeFalse();
});
