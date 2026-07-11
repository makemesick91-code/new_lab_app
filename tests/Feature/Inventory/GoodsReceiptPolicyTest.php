<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\PurchaseOrder;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);

    $purchaseOrder = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
    $otherPurchaseOrder = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->otherBranch->id]);

    $this->draft = GoodsReceipt::factory()->forPurchaseOrder($purchaseOrder)->draft()->create([
        'branch_id' => $this->branch->id,
    ]);
    $this->submitted = GoodsReceipt::factory()->forPurchaseOrder($purchaseOrder)->submitted()->create([
        'branch_id' => $this->branch->id,
    ]);
    $this->posted = GoodsReceipt::factory()->forPurchaseOrder($purchaseOrder)->posted()->create([
        'branch_id' => $this->branch->id,
    ]);
    $this->cancelled = GoodsReceipt::factory()->forPurchaseOrder($purchaseOrder)->cancelled()->create([
        'branch_id' => $this->branch->id,
    ]);
    $this->otherDraft = GoodsReceipt::factory()->forPurchaseOrder($otherPurchaseOrder)->draft()->create([
        'branch_id' => $this->otherBranch->id,
    ]);
});

it('allows view_inventory to list and view same branch goods receipts', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('viewAny', GoodsReceipt::class))->toBeTrue()
        ->and($viewer->can('view', $this->draft))->toBeTrue();
});

it('denies view_inventory mutation and workflow abilities', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('create', GoodsReceipt::class))->toBeFalse()
        ->and($viewer->can('update', $this->draft))->toBeFalse()
        ->and($viewer->can('submit', $this->draft))->toBeFalse()
        ->and($viewer->can('post', $this->draft))->toBeFalse()
        ->and($viewer->can('cancel', $this->draft))->toBeFalse()
        ->and($viewer->can('void', $this->posted))->toBeFalse();
});

it('allows manage_inventory to create update submit post draft or submitted and cancel draft or submitted', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('create', GoodsReceipt::class))->toBeTrue()
        ->and($manager->can('update', $this->draft))->toBeTrue()
        ->and($manager->can('submit', $this->draft))->toBeTrue()
        ->and($manager->can('post', $this->draft))->toBeTrue()
        ->and($manager->can('post', $this->submitted))->toBeTrue()
        ->and($manager->can('cancel', $this->draft))->toBeTrue()
        ->and($manager->can('cancel', $this->submitted))->toBeTrue()
        ->and($manager->can('void', $this->posted))->toBeTrue();
});

it('denies manage_inventory update submit post cancel and void for terminal goods receipts', function () {
    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);
    $voided = GoodsReceipt::factory()->forPurchaseOrder($this->posted->purchaseOrder)->voided()->create([
        'branch_id' => $this->branch->id,
    ]);

    expect($manager->can('update', $this->posted))->toBeFalse()
        ->and($manager->can('update', $this->cancelled))->toBeFalse()
        ->and($manager->can('submit', $this->posted))->toBeFalse()
        ->and($manager->can('post', $this->posted))->toBeFalse()
        ->and($manager->can('post', $this->cancelled))->toBeFalse()
        ->and($manager->can('cancel', $this->posted))->toBeFalse()
        ->and($manager->can('cancel', $this->cancelled))->toBeFalse()
        ->and($manager->can('void', $voided))->toBeFalse()
        ->and($manager->can('void', $this->cancelled))->toBeFalse();
});

it('denies cross branch goods receipt access', function () {
    $viewer = userWith(['view_inventory']);
    $this->actingAs($viewer);

    expect($viewer->can('view', $this->otherDraft))->toBeFalse();

    $manager = userWith(['manage_inventory']);
    $this->actingAs($manager);

    expect($manager->can('view', $this->otherDraft))->toBeFalse()
        ->and($manager->can('update', $this->otherDraft))->toBeFalse()
        ->and($manager->can('submit', $this->otherDraft))->toBeFalse()
        ->and($manager->can('post', $this->otherDraft))->toBeFalse()
        ->and($manager->can('cancel', $this->otherDraft))->toBeFalse()
        ->and($manager->can('void', $this->otherDraft))->toBeFalse();
});

it('denies unauthorized user without inventory permissions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect($user->can('viewAny', GoodsReceipt::class))->toBeFalse()
        ->and($user->can('view', $this->draft))->toBeFalse()
        ->and($user->can('create', GoodsReceipt::class))->toBeFalse()
        ->and($user->can('update', $this->draft))->toBeFalse()
        ->and($user->can('submit', $this->draft))->toBeFalse()
        ->and($user->can('post', $this->draft))->toBeFalse()
        ->and($user->can('cancel', $this->draft))->toBeFalse();
});

it('allows Admin Warehouse full goods receipt workflow', function () {
    // FIX-ADMIN-LAB-LAB-ONLY-ACCESS — goods receipt is an inventory workflow owned
    // by Admin Warehouse; the Lab-only Admin Lab role no longer has access.
    $admin = User::factory()->create();
    $admin->assignRole('Admin Warehouse');
    $this->actingAs($admin);

    expect($admin->can('viewAny', GoodsReceipt::class))->toBeTrue()
        ->and($admin->can('view', $this->draft))->toBeTrue()
        ->and($admin->can('create', GoodsReceipt::class))->toBeTrue()
        ->and($admin->can('update', $this->draft))->toBeTrue()
        ->and($admin->can('submit', $this->draft))->toBeTrue()
        ->and($admin->can('post', $this->draft))->toBeTrue()
        ->and($admin->can('cancel', $this->draft))->toBeTrue();
});
