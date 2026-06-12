<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\ModuleBranchScope;

it('scopes RME branches by the is_rme_enabled flag', function () {
    $rme = Branch::factory()->create(['code' => 'RME-Y', 'is_rme_enabled' => true]);
    Branch::factory()->create(['code' => 'RME-N', 'is_rme_enabled' => false]);

    $codes = Branch::rmeEnabled()->pluck('code');

    expect($codes)->toContain('RME-Y');
    expect($codes)->not->toContain('RME-N');
});

it('scopes Inventory branches by the is_inventory_enabled flag', function () {
    $inv = Branch::factory()->create(['code' => 'INV-Y', 'is_inventory_enabled' => true]);
    Branch::factory()->create(['code' => 'INV-N', 'is_inventory_enabled' => false]);

    $codes = Branch::inventoryEnabled()->pluck('code');

    expect($codes)->toContain('INV-Y');
    expect($codes)->not->toContain('INV-N');
});

it('treats RME and Inventory as multi-branch and Lab as single-branch', function () {
    expect(ModuleBranchScope::isMultiBranch('rme'))->toBeTrue();
    expect(ModuleBranchScope::isMultiBranch('inventory'))->toBeTrue();
    expect(ModuleBranchScope::isSingleBranch('lab'))->toBeTrue();
    expect(ModuleBranchScope::usesBranchFilter('lab'))->toBeFalse();
});
