<?php

use App\Modules\AccessControl\Services\PermissionGroupingService;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Sprint 53 — Permission Page Module Grouping Hotfix
|--------------------------------------------------------------------------
| UI grouping hotfix only: a dedicated RME group is added so Inventory, Lab,
| and RME are clearly separated on the role permission page. No slug rename,
| no permission deletion, no authorization/policy/middleware rewrite.
*/

const SPRINT_53_DOC = 'docs/sprint_53_permission_page_module_grouping_hotfix.md';

function sprint53Doc(): string
{
    return file_get_contents(base_path(SPRINT_53_DOC));
}

function sprint53History(): string
{
    return file_get_contents(base_path('docs/sprint_history.md'));
}

// ---------------------------------------------------------------------------
// Documentation regression
// ---------------------------------------------------------------------------

it('documents the Sprint 53 identity', function () {
    $doc = sprint53Doc();

    expect($doc)
        ->toContain('Sprint 53 — Permission Page Module Grouping Hotfix')
        ->toContain('feature/sprint-53-permission-page-module-grouping-hotfix')
        ->toContain('sprint-53-permission-page-module-grouping-hotfix')
        ->toContain('sprint-53-permission-page-module-grouping-hotfix-go')
        ->toContain('feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report')
        ->toContain('cf29f45')
        ->toContain('sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness-go');
});

it('documents the UI hotfix and module grouping intent', function () {
    $doc = sprint53Doc();

    expect($doc)
        ->toContain('permission page UI hotfix')
        ->toContain('grouped per module')
        ->toContain('Inventory')
        ->toContain('Lab')
        ->toContain('RME')
        ->toContain('Other / Uncategorized');
});

it('documents the preservation and safety guarantees', function () {
    $doc = sprint53Doc();

    expect($doc)
        ->toContain('No permission slug rename.')
        ->toContain('No permission deletion.')
        ->toContain('No authorization logic rewrite.')
        ->toContain('Policies and middleware are not rewritten.')
        ->toContain('Existing selected permissions remain checked.')
        ->toContain('Existing form submission behavior is preserved.')
        ->toContain('No production/VPS/server access.')
        ->toContain('No production database/log/file access.')
        ->toContain('No deployment.')
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

it('records Sprint 53 in the sprint history', function () {
    expect(sprint53History())
        ->toContain('## Sprint 53 — Permission Page Module Grouping Hotfix')
        ->toContain('RME / Rekam Medis')
        ->toContain('feature/sprint-53-permission-page-module-grouping-hotfix');
});

// ---------------------------------------------------------------------------
// Grouping behaviour
// ---------------------------------------------------------------------------

beforeEach(function () {
    seedAccessControl();
});

function sprint53Group(string $key): ?array
{
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());

    return collect($groups)->firstWhere('key', $key);
}

function sprint53GroupNames(string $key): array
{
    $group = sprint53Group($key);

    return $group === null
        ? []
        : collect($group['permissions'])->pluck('name')->all();
}

it('groups RME permissions under a dedicated RME bucket', function () {
    $rme = sprint53Group('rme');

    expect($rme)->not->toBeNull();
    expect($rme['label'])->toBe('RME / Rekam Medis');
    expect(sprint53GroupNames('rme'))->toContain(
        'view_clinic_visits',
        'manage_clinic_visits',
        'manage_rme_billing',
        'view_rme_patient_reports',
        'view_rme_payment_reports',
    );
});

it('keeps RME permissions out of the Other bucket', function () {
    expect(sprint53GroupNames('other'))->not->toContain(
        'view_clinic_visits',
        'manage_clinic_visits',
        'manage_rme_billing',
        'view_rme_patient_reports',
        'view_rme_payment_reports',
    );
});

it('keeps Inventory and Lab as separate groups from RME', function () {
    expect(sprint53Group('inventory'))->not->toBeNull();
    expect(sprint53Group('lab_order'))->not->toBeNull();
    expect(sprint53GroupNames('inventory'))->toContain('view_inventory', 'manage_inventory');
    expect(sprint53GroupNames('lab_order'))->toContain('view_lab_orders', 'manage_lab_orders');
});

it('groups access control permissions under access control', function () {
    expect(sprint53GroupNames('access_control'))->toContain(
        'manage users',
        'manage roles',
        'manage permissions',
    );
});

it('falls back unknown permissions to Other / Uncategorized', function () {
    Permission::firstOrCreate(['name' => 'totally_unknown_permission', 'guard_name' => 'web']);

    $other = sprint53Group('other');

    expect($other)->not->toBeNull();
    expect($other['label'])->toBe('Other / Uncategorized');
    expect(sprint53GroupNames('other'))->toContain('totally_unknown_permission');
});

it('preserves original slugs and covers every seeded permission', function () {
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());

    $groupedNames = collect($groups)
        ->flatMap(fn (array $group) => collect($group['permissions'])->pluck('name'))
        ->sort()
        ->values()
        ->all();

    expect($groupedNames)->toBe(collect(PermissionSeeder::PERMISSIONS)->sort()->values()->all());
});

// ---------------------------------------------------------------------------
// Permission page rendering + selected-state preservation
// ---------------------------------------------------------------------------

it('shows Inventory, Lab, and RME group headings on the create role page', function () {
    $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.create'))
        ->assertOk()
        ->assertSee('data-permission-group="inventory"', false)
        ->assertSee('data-permission-group="lab_order"', false)
        ->assertSee('data-permission-group="rme"', false)
        ->assertSee('RME / Rekam Medis');
});

it('keeps an assigned RME permission checked on the edit role page', function () {
    $role = Role::create(['name' => 'RME Cashier Pilot', 'guard_name' => 'web']);
    $role->givePermissionTo('manage_rme_billing');

    $response = $this->actingAs(userWith(['manage roles']))
        ->get(route('settings.roles.edit', $role))
        ->assertOk()
        ->assertSee('value="manage_rme_billing"', false);

    // The same checkbox input keeps its original slug as value and renders checked.
    expect($response->getContent())->toMatch('/value="manage_rme_billing"[^>]*?checked/s');
});
