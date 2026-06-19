<?php

use App\Modules\AccessControl\Services\PermissionGroupingService;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Sprint 55 — Permission Group Classification Cleanup
|--------------------------------------------------------------------------
| UI classification cleanup only: operational permissions are moved out of
| Other / Uncategorized into clearer module groups. No slug rename, no
| permission deletion, no role assignment change, no authorization/policy/
| middleware rewrite.
*/

const SPRINT_55_DOC = 'docs/sprint_55_permission_group_classification_cleanup.md';

function sprint55Doc(): string
{
    return file_get_contents(base_path(SPRINT_55_DOC));
}

function sprint55History(): string
{
    return file_get_contents(base_path('docs/sprint_history.md'));
}

// ---------------------------------------------------------------------------
// Documentation regression
// ---------------------------------------------------------------------------

it('documents the Sprint 55 identity', function () {
    $doc = sprint55Doc();

    expect($doc)
        ->toContain('Sprint 55 — Permission Group Classification Cleanup')
        ->toContain('feature/sprint-55-permission-group-classification-cleanup')
        ->toContain('sprint-55-permission-group-classification-cleanup')
        ->toContain('sprint-55-permission-group-classification-cleanup-go')
        ->toContain('0501e77')
        ->toContain('permission group classification cleanup');
});

it('documents the Doctor/Kasir/Perawat business approval', function () {
    $doc = sprint55Doc();

    expect($doc)
        ->toContain('Doctor `manage_clinic_visits` is business-approved and unchanged.')
        ->toContain('Kasir `manage_rme_billing` is business-approved and unchanged.')
        ->toContain('Perawat `manage_clinic_visits` is business-approved and unchanged.');
});

it('documents the preservation and safety guarantees', function () {
    $doc = sprint55Doc();

    expect($doc)
        ->toContain('Role permission assignments are not changed.')
        ->toContain('Permission slugs are not renamed.')
        ->toContain('Permissions are not deleted.')
        ->toContain('Authorization logic is not rewritten.')
        ->toContain('Policies and middleware are not rewritten.')
        ->toContain('Policies/middleware are not rewritten.')
        ->toContain('Other / Uncategorized fallback remains available.')
        ->toContain('No production/VPS/server access during local implementation.')
        ->toContain('No deployment')
        ->toContain('KTP hidden')
        ->toContain('WhatsApp manual-only')
        ->toContain('zero-remaining receivable preserved')
        ->toContain('overpayment guard preserved')
        ->toContain('Sprint 54 UI polish is preserved.');
});

it('records Sprint 55 in the sprint history', function () {
    expect(sprint55History())
        ->toContain('## Sprint 55 — Permission Group Classification Cleanup')
        ->toContain('feature/sprint-55-permission-group-classification-cleanup');
});

// ---------------------------------------------------------------------------
// Grouping behaviour
// ---------------------------------------------------------------------------

beforeEach(function () {
    seedAccessControl();
});

function sprint55GroupLabelOf(string $slug): ?string
{
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());

    foreach ($groups as $group) {
        foreach ($group['permissions'] as $permission) {
            if ($permission['name'] === $slug) {
                return $group['label'];
            }
        }
    }

    return null;
}

it('groups delivery permissions under Delivery / Pengiriman', function () {
    expect(sprint55GroupLabelOf('assign_courier'))->toBe('Delivery / Pengiriman');
    expect(sprint55GroupLabelOf('manage deliveries'))->toBe('Delivery / Pengiriman');
    expect(sprint55GroupLabelOf('mark_delivered'))->toBe('Delivery / Pengiriman');
});

it('groups technician/assignment permissions under Technician / Assignment', function () {
    expect(sprint55GroupLabelOf('assign_technicians'))->toBe('Technician / Assignment');
    expect(sprint55GroupLabelOf('reassign_technicians'))->toBe('Technician / Assignment');
    expect(sprint55GroupLabelOf('manage technicians'))->toBe('Technician / Assignment');
    expect(sprint55GroupLabelOf('manage assignments'))->toBe('Technician / Assignment');
});

it('groups quality control permissions under Quality Control', function () {
    expect(sprint55GroupLabelOf('manage_quality_control'))->toBe('Quality Control');
    expect(sprint55GroupLabelOf('view_quality_control'))->toBe('Quality Control');
    expect(sprint55GroupLabelOf('request_remake'))->toBe('Quality Control');
});

it('groups purchase request permissions under Purchase / Procurement', function () {
    expect(sprint55GroupLabelOf('manage_purchase_request'))->toBe('Purchase / Procurement');
    expect(sprint55GroupLabelOf('view_purchase_request'))->toBe('Purchase / Procurement');
});

it('groups branch master data permissions under Branch Master Data', function () {
    expect(sprint55GroupLabelOf('manage_branch_master_data'))->toBe('Branch Master Data');
    expect(sprint55GroupLabelOf('view_branch_master_data'))->toBe('Branch Master Data');
});

it('groups the legacy master data permission under Master Data', function () {
    expect(sprint55GroupLabelOf('manage master data'))->toBe('Master Data');
});

it('keeps the cleaned-up permissions out of Other / Uncategorized', function () {
    $cleaned = [
        'assign_courier', 'manage deliveries', 'mark_delivered',
        'assign_technicians', 'reassign_technicians', 'manage technicians', 'manage assignments',
        'manage_quality_control', 'view_quality_control', 'request_remake',
        'manage_purchase_request', 'view_purchase_request',
        'manage_branch_master_data', 'view_branch_master_data',
        'manage master data',
    ];

    foreach ($cleaned as $slug) {
        expect(sprint55GroupLabelOf($slug))->not->toBe('Other / Uncategorized');
        expect(sprint55GroupLabelOf($slug))->not->toBeNull();
    }
});

it('keeps preserved module groups intact', function () {
    expect(sprint55GroupLabelOf('view_inventory'))->toBe('Inventory / Persediaan');
    expect(sprint55GroupLabelOf('view_lab_orders'))->toBe('Lab Order');
    expect(sprint55GroupLabelOf('view_rme_patient_reports'))->toBe('RME / Rekam Medis');
});

it('falls back unknown permissions to Other / Uncategorized', function () {
    Permission::firstOrCreate(['name' => 'unknown_future_permission', 'guard_name' => 'web']);

    expect(sprint55GroupLabelOf('unknown_future_permission'))->toBe('Other / Uncategorized');
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

it('exposes a non-empty description for every rendered group', function () {
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());

    foreach ($groups as $group) {
        expect($group['description'])->toBeString()->not->toBe('');
    }
});
