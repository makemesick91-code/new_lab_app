<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\StockTransfer;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create();

    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherSource = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherDestination = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->transfer = StockTransfer::factory()->create([
        'branch_id' => $this->branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ]);

    $this->otherTransfer = StockTransfer::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'source_inventory_location_id' => $otherSource->id,
        'destination_inventory_location_id' => $otherDestination->id,
    ]);
});

it('allows view_inventory to list and view same branch transfers', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('viewAny', StockTransfer::class))->toBeTrue()
        ->and($viewer->can('view', $this->transfer))->toBeTrue();
});

it('denies view_inventory mutation and workflow abilities', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('create', StockTransfer::class))->toBeFalse()
        ->and($viewer->can('update', $this->transfer))->toBeFalse()
        ->and($viewer->can('delete', $this->transfer))->toBeFalse()
        ->and($viewer->can('submit', $this->transfer))->toBeFalse()
        ->and($viewer->can('ship', $this->transfer))->toBeFalse()
        ->and($viewer->can('receive', $this->transfer))->toBeFalse()
        ->and($viewer->can('cancel', $this->transfer))->toBeFalse();
});

it('allows manage_inventory to perform workflow actions on same branch transfers', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('viewAny', StockTransfer::class))->toBeTrue()
        ->and($manager->can('view', $this->transfer))->toBeTrue()
        ->and($manager->can('create', StockTransfer::class))->toBeTrue()
        ->and($manager->can('update', $this->transfer))->toBeTrue()
        ->and($manager->can('delete', $this->transfer))->toBeTrue()
        ->and($manager->can('submit', $this->transfer))->toBeTrue()
        ->and($manager->can('ship', $this->transfer))->toBeTrue()
        ->and($manager->can('receive', $this->transfer))->toBeTrue()
        ->and($manager->can('cancel', $this->transfer))->toBeTrue();
});

it('denies cross branch transfer view and mutation for inventory users', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('view', $this->otherTransfer))->toBeFalse();

    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('view', $this->otherTransfer))->toBeFalse()
        ->and($manager->can('update', $this->otherTransfer))->toBeFalse()
        ->and($manager->can('delete', $this->otherTransfer))->toBeFalse()
        ->and($manager->can('submit', $this->otherTransfer))->toBeFalse()
        ->and($manager->can('ship', $this->otherTransfer))->toBeFalse()
        ->and($manager->can('receive', $this->otherTransfer))->toBeFalse()
        ->and($manager->can('cancel', $this->otherTransfer))->toBeFalse();
});

it('denies unauthorized users all stock transfer abilities', function () {
    $user = userWith([]);
    $this->actingAs($user);

    expect($user->can('viewAny', StockTransfer::class))->toBeFalse()
        ->and($user->can('view', $this->transfer))->toBeFalse()
        ->and($user->can('create', StockTransfer::class))->toBeFalse()
        ->and($user->can('update', $this->transfer))->toBeFalse()
        ->and($user->can('delete', $this->transfer))->toBeFalse()
        ->and($user->can('submit', $this->transfer))->toBeFalse()
        ->and($user->can('ship', $this->transfer))->toBeFalse()
        ->and($user->can('receive', $this->transfer))->toBeFalse()
        ->and($user->can('cancel', $this->transfer))->toBeFalse();
});

it('preserves legacy manage master data access for inventory transfer actions', function () {
    $legacyManager = userWith(['manage master data']);
    $this->actingAs($legacyManager);

    expect($legacyManager->can('viewAny', StockTransfer::class))->toBeTrue()
        ->and($legacyManager->can('view', $this->transfer))->toBeTrue()
        ->and($legacyManager->can('create', StockTransfer::class))->toBeTrue()
        ->and($legacyManager->can('submit', $this->transfer))->toBeTrue();
});
