<?php

/*
| FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 (rule 186) — role foundation.
|
| Pins: two independent read-only roles with an identical INITIAL permission
| set; the existing laboratory `Finance` role untouched; no mutation, consent,
| KTP or legacy-governance permission granted; idempotent seeding; nobody
| auto-assigned; every new permission classified.
*/

use App\Models\User;
use App\Modules\AccessControl\Services\PermissionGroupingService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => seedAccessControl());

it('creates Finance Klinik and Data Analyst as two separate web-guard roles', function () {
    $finance = Role::findByName('Finance Klinik', 'web');
    $analyst = Role::findByName('Data Analyst', 'web');

    expect($finance->id)->not->toBe($analyst->id)
        ->and(Role::where('name', 'Finance Klinik')->count())->toBe(1)
        ->and(Role::where('name', 'Data Analyst')->count())->toBe(1);
});

it('gives both roles an identical initial permission set of exactly the four oversight permissions', function () {
    $expected = ClinicOversightScope::ALL_PERMISSIONS;
    sort($expected);

    foreach (covRoles() as $roleName) {
        $granted = Role::findByName($roleName, 'web')->permissions->pluck('name')->sort()->values()->all();
        expect($granted)->toBe($expected);
    }

    expect(RoleSeeder::OVERSIGHT_INITIAL_PERMISSIONS)->toBe(ClinicOversightScope::ALL_PERMISSIONS);
});

it('keeps the two roles independent: changing one never changes the other', function () {
    $finance = Role::findByName('Finance Klinik', 'web');
    $analyst = Role::findByName('Data Analyst', 'web');

    $finance->revokePermissionTo('export_oversight_data');
    $analyst->refresh();
    expect($analyst->hasPermissionTo('export_oversight_data'))->toBeTrue()
        ->and($finance->fresh()->hasPermissionTo('export_oversight_data'))->toBeFalse();

    $analyst->revokePermissionTo('view_clinical_records_readonly');
    expect($finance->fresh()->hasPermissionTo('view_clinical_records_readonly'))->toBeTrue()
        ->and($analyst->fresh()->hasPermissionTo('view_clinical_records_readonly'))->toBeFalse();
});

it('leaves the existing laboratory Finance role exactly as it was', function () {
    $granted = Role::findByName('Finance', 'web')->permissions->pluck('name')->sort()->values()->all();

    $expected = [
        'view dashboard', 'manage invoices', 'manage payments', 'manage_invoice', 'view_invoice',
        'create_invoice', 'issue_invoice', 'void_invoice', 'manage_payment', 'view_payment',
        'create_payment', 'view reports', 'view_lab_orders', 'view_dashboard', 'view_invoice_report',
        'view_payment_report', 'export_report',
    ];
    sort($expected);

    expect($granted)->toBe($expected);

    foreach (ClinicOversightScope::ALL_PERMISSIONS as $permission) {
        expect(Role::findByName('Finance', 'web')->hasPermissionTo($permission))->toBeFalse();
    }
});

it('grants the oversight roles no mutation, consent, KTP, dashboard or legacy-governance permission', function () {
    $forbidden = [
        'view dashboard', 'view_owner_dashboard', 'view_clinic_visits', 'manage_clinic_visits',
        'manage_rme_billing', 'manage patients', 'view_rme_consents', 'manage_rme_consents',
        'view_cross_branch_patient_ktp_documents', 'manage invoices', 'manage payments',
        'complete_rme_examination', 'review_legacy_rme_imports', 'publish_legacy_rme_imports',
        'void_legacy_rme_imports', 'review_legacy_odontogram_imports', 'publish_legacy_odontogram_imports',
        'void_legacy_odontogram_records', 'view_legacy_rme_imports', 'view_legacy_rme_archive',
        'view_legacy_odontogram_imports', 'view_legacy_odontogram_archive',
    ];

    foreach (covRoles() as $roleName) {
        $user = covUser($roleName);
        foreach ($forbidden as $permission) {
            if (Permission::where('name', $permission)->exists()) {
                expect($user->can($permission))->toBeFalse("{$roleName} must not hold {$permission}");
            }
        }
        // Every effective permission is one of the four oversight permissions.
        expect($user->getAllPermissions()->pluck('name')->diff(ClinicOversightScope::ALL_PERMISSIONS)->all())->toBe([]);
    }
});

it('never puts an oversight permission into a legacy governance branch-scope list', function () {
    foreach ([
        LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS,
        LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS,
    ] as $list) {
        expect(array_intersect($list, ClinicOversightScope::ALL_PERMISSIONS))->toBe([]);
    }
});

it('seeds idempotently and assigns the roles to nobody', function () {
    $before = Role::whereIn('name', covRoles())->count();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    expect(Role::whereIn('name', covRoles())->count())->toBe($before)
        ->and(Permission::whereIn('name', ClinicOversightScope::ALL_PERMISSIONS)->count())->toBe(4)
        ->and(User::role(covRoles())->count())->toBe(0);
});

it('classifies every oversight permission in a permission group', function () {
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());
    $grouped = collect($groups)->where('key', '!=', 'other')->flatMap(fn ($g) => collect($g['permissions'])->pluck('name'))->all();

    foreach (ClinicOversightScope::ALL_PERMISSIONS as $permission) {
        expect($grouped)->toContain($permission);
    }
});
