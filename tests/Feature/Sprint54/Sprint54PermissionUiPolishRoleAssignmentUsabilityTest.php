<?php

use App\Modules\AccessControl\Services\PermissionGroupingService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Sprint 54 — Permission UI Polish & Role Assignment Usability
|--------------------------------------------------------------------------
| UI usability polish on top of the Sprint 53 module grouping. Module helper
| descriptions, role assignment guidance copy, per-module selected counts,
| and an Other / Uncategorized warning. No slug rename, no permission
| deletion, no authorization/policy/middleware rewrite, no schema change.
*/

const SPRINT_54_DOC = 'docs/sprint_54_permission_ui_polish_role_assignment_usability.md';

function sprint54Doc(): string
{
    return file_get_contents(base_path(SPRINT_54_DOC));
}

function sprint54History(): string
{
    return file_get_contents(base_path('docs/sprint_history.md'));
}

// ---------------------------------------------------------------------------
// Documentation regression
// ---------------------------------------------------------------------------

it('documents the Sprint 54 identity', function () {
    $doc = sprint54Doc();

    expect($doc)
        ->toContain('Sprint 54 — Permission UI Polish & Role Assignment Usability')
        ->toContain('feature/sprint-54-permission-ui-polish-role-assignment-usability')
        ->toContain('sprint-54-permission-ui-polish-role-assignment-usability')
        ->toContain('sprint-54-permission-ui-polish-role-assignment-usability-go')
        ->toContain('feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report')
        ->toContain('6b7e977')
        ->toContain('sprint-53-permission-page-module-grouping-hotfix-go');
});

it('documents the UI polish and role assignment usability intent', function () {
    $doc = sprint54Doc();

    expect($doc)
        ->toContain('permission UI polish and role assignment usability sprint')
        ->toContain('Permission grouping from Sprint 53 is preserved.')
        ->toContain('Inventory, Lab, and RME groups remain visible.')
        ->toContain('Other / Uncategorized fallback remains available.')
        ->toContain('Role assignment helper text added')
        ->toContain('Permission group descriptions')
        ->toContain('Selected permissions remain checked.')
        ->toContain('Existing form submission behavior is preserved.');
});

it('documents the preservation and safety guarantees', function () {
    $doc = sprint54Doc();

    expect($doc)
        ->toContain('Permission slugs are not renamed.')
        ->toContain('Permissions are not deleted.')
        ->toContain('Authorization logic is not rewritten.')
        ->toContain('Policies and middleware are not rewritten.')
        ->toContain('No production/VPS/server access during Sprint 54 local implementation.')
        ->toContain('No deployment during Sprint 54 local implementation.')
        ->toContain('VPS update must be performed only after Sprint 54 is merged and GO tagged through a separate supervised')
        ->toContain('No production database/log/file access.')
        ->toContain('No production command execution.')
        ->toContain('No production backup.')
        ->toContain('No production restore.')
        ->toContain('No rollback execution.')
        ->toContain('No `.env` change.')
        ->toContain('No dependency/package install.')
        ->toContain('No migration/schema change.')
        ->toContain('No financial logic rewrite.')
        ->toContain('KTP / `ktp_number` remains hidden')
        ->toContain('WhatsApp remains manual-only')
        ->toContain('Zero-remaining receivable rule remains preserved.')
        ->toContain('Overpayment guard remains preserved.')
        ->toContain('RME remains complete/closed for current planning.')
        ->toContain('Pilot Health Check governance loop remains stopped.');
});

it('records Sprint 54 in the sprint history', function () {
    expect(sprint54History())
        ->toContain('## Sprint 54 — Permission UI Polish & Role Assignment Usability')
        ->toContain('Permission grouping from Sprint 53 preserved')
        ->toContain('feature/sprint-54-permission-ui-polish-role-assignment-usability');
});

// ---------------------------------------------------------------------------
// Grouping behaviour (descriptions are additive; slugs/buckets preserved)
// ---------------------------------------------------------------------------

beforeEach(function () {
    seedAccessControl();
});

function sprint54Group(string $key): ?array
{
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());

    return collect($groups)->firstWhere('key', $key);
}

function sprint54GroupNames(string $key): array
{
    $group = sprint54Group($key);

    return $group === null
        ? []
        : collect($group['permissions'])->pluck('name')->all();
}

it('exposes a non-empty description for every rendered group', function () {
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());

    expect($groups)->not->toBeEmpty();

    foreach ($groups as $group) {
        expect($group)->toHaveKey('description');
        expect($group['description'])->toBeString()->not->toBe('');
    }
});

it('describes the RME, Inventory, and Lab groups while keeping them separate', function () {
    expect(sprint54Group('rme')['description'])->toContain('rekam medis');
    expect(sprint54Group('inventory')['description'])->toContain('persediaan');
    expect(sprint54Group('lab_order')['description'])->toContain('order lab');

    expect(sprint54GroupNames('rme'))->toContain('manage_rme_billing');
    expect(sprint54GroupNames('inventory'))->toContain('view_inventory', 'manage_inventory');
    expect(sprint54GroupNames('lab_order'))->toContain('view_lab_orders', 'manage_lab_orders');
});

it('keeps the Other / Uncategorized fallback with a review description', function () {
    Permission::firstOrCreate(['name' => 'totally_unknown_permission_54', 'guard_name' => 'web']);

    $other = sprint54Group('other');

    expect($other)->not->toBeNull();
    expect($other['label'])->toBe('Other / Uncategorized');
    expect($other['description'])->toContain('Review sebelum diberikan ke role');
    expect(sprint54GroupNames('other'))->toContain('totally_unknown_permission_54');
});

it('preserves original permission slugs (no rename) in grouped output', function () {
    expect(sprint54GroupNames('rme'))->toContain(
        'view_clinic_visits',
        'manage_clinic_visits',
        'manage_rme_billing',
        'view_rme_patient_reports',
        'view_rme_payment_reports',
    );
});

// ---------------------------------------------------------------------------
// Rendered UI polish + selected-state preservation
// ---------------------------------------------------------------------------

it('renders the role assignment heading, helper copy, and module descriptions', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.create'))
        ->assertOk()
        ->assertSee('Permission Role')
        ->assertSee('Kelompok permission berdasarkan modul agar admin lebih mudah memilih akses role.')
        ->assertSee('Centang permission yang ingin diberikan pada role ini.')
        ->assertSee('Other / Uncategorized berisi permission yang belum masuk klasifikasi modul utama.')
        ->assertSee('Akses workflow kunjungan klinik, rekam medis, billing RME, dan laporan RME.')
        ->assertSee('data-permission-group="rme"', false)
        ->assertSee('data-permission-group="inventory"', false)
        ->assertSee('data-permission-group="lab_order"', false);
});

it('keeps an assigned permission checked on the edit role page', function () {
    $role = Role::create(['name' => 'Sprint 54 RME Cashier', 'guard_name' => 'web']);
    $role->givePermissionTo('manage_rme_billing');

    $response = $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.edit', $role))
        ->assertOk()
        ->assertSee('value="manage_rme_billing"', false)
        ->assertSee('permission dipilih');

    expect($response->getContent())->toMatch('/value="manage_rme_billing"[^>]*?checked/s');
});
