<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Services\InventoryAnalyticsSummaryRefreshService;
use App\Modules\Inventory\Services\InventoryBranchComparisonService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branchA = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branchB = Branch::factory()->create(['name' => 'Cabang B Analytics', 'code' => 'BRB-AN']);
    $this->comparison = app(InventoryBranchComparisonService::class);
});

it('seeds view_inventory_cross_branch_analytics permission', function () {
    expect(Permission::where('name', 'view_inventory_cross_branch_analytics')->count())->toBe(1);
});

it('assigns cross branch analytics to inventory roles but not the Lab-only Admin Lab role', function () {
    // FIX-ADMIN-LAB-LAB-ONLY-ACCESS — cross-branch inventory analytics is an
    // inventory concern (Admin Warehouse), never the Lab-only Admin Lab role.
    expect(Role::findByName('Admin Warehouse')->hasPermissionTo('view_inventory_cross_branch_analytics'))->toBeTrue()
        ->and(Role::findByName('Admin Lab')->hasPermissionTo('view_inventory_cross_branch_analytics'))->toBeFalse()
        ->and(Role::findByName('Technician')->hasPermissionTo('view_inventory_cross_branch_analytics'))->toBeFalse()
        ->and(Role::findByName('Quality Control')->hasPermissionTo('view_inventory_cross_branch_analytics'))->toBeFalse();
});

it('returns only active branch for regular view_inventory users', function () {
    $user = userWith(['view_inventory']);

    $rows = $this->comparison->getBranchComparison($user);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['branch_id'])->toBe($this->branchA->id)
        ->and($rows->pluck('branch_name'))->not->toContain('Cabang B Analytics');
});

it('hides branch comparison tab for regular view_inventory users', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertDontSee('Perbandingan Cabang')
        ->assertDontSee('id="section-branch-comparison"', false);
});

it('shows multiple branches for cross branch analytics users', function () {
    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $rows = $this->comparison->getBranchComparison($user);

    expect($rows->pluck('branch_id')->all())->toContain($this->branchA->id, $this->branchB->id);
});

it('shows branch comparison tab for cross branch analytics users', function () {
    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'branch-comparison']))
        ->assertOk()
        ->assertSee('Perbandingan Cabang')
        ->assertSee('Cabang B Analytics');
});

it('uses summary data for branch comparison when feature flag is enabled', function () {
    config(['inventory.analytics_summary_enabled' => true]);

    app(InventoryAnalyticsSummaryRefreshService::class)->refreshAll();

    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);
    $rows = $this->comparison->getBranchComparison($user);
    $mainRow = $rows->firstWhere('branch_id', $this->branchA->id);

    expect($mainRow)->not->toBeNull()
        ->and(DB::table('rpt_inventory_branch_summaries')->where('branch_id', $this->branchA->id)->exists())->toBeTrue();
});

it('returns safe empty row when summary is missing for a branch', function () {
    config(['inventory.analytics_summary_enabled' => true]);

    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);
    $row = $this->comparison->getBranchComparison($user)->firstWhere('branch_id', $this->branchB->id);

    expect($row)->not->toBeNull()
        ->and($row['inventory_value'])->toBe(0.0)
        ->and($row['active_sku_count'])->toBe(0)
        ->and($row['refreshed_at'])->toBeNull();
});

it('does not error when refreshed_at is null in branch comparison row', function () {
    $user = userWith(['view_inventory']);

    $row = $this->comparison->getBranchComparison($user)->first();

    expect($row['refreshed_at'])->toBeNull();

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'branch-comparison']))
        ->assertOk()
        ->assertSee('Belum ada data ringkasan');
});

it('denies unauthorized users from analytics branch comparison route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'branch-comparison']))
        ->assertForbidden();
});

it('still requires view_inventory for branch comparison page access', function () {
    $user = userWith(['view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'branch-comparison']))
        ->assertForbidden();
});
