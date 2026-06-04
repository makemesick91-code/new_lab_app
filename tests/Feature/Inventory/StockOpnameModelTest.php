<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use Illuminate\Support\Carbon;

it('relates a stock opname to its branch, location, and users', function () {
    $opname = StockOpname::factory()->create();

    expect($opname->branch)->toBeInstanceOf(Branch::class)
        ->and($opname->inventoryLocation)->toBeInstanceOf(InventoryLocation::class)
        ->and($opname->countedBy)->toBeInstanceOf(User::class)
        ->and($opname->createdBy)->toBeInstanceOf(User::class);
});

it('relates a stock opname to its items', function () {
    $opname = StockOpname::factory()
        ->has(StockOpnameItem::factory()->count(3), 'items')
        ->create();

    expect($opname->items)->toHaveCount(3)
        ->and($opname->items->first())->toBeInstanceOf(StockOpnameItem::class)
        ->and($opname->items->first()->stockOpname->id)->toBe($opname->id);
});

it('relates a stock opname item to its opname and product', function () {
    $item = StockOpnameItem::factory()->create();

    expect($item->stockOpname)->toBeInstanceOf(StockOpname::class)
        ->and($item->product)->toBeInstanceOf(Product::class);
});

it('keeps the counted product in the same branch as the opname', function () {
    $item = StockOpnameItem::factory()->create();

    expect($item->product->branch_id)->toBe($item->stockOpname->branch_id);
});

it('casts opname dates and item decimals', function () {
    $opname = StockOpname::factory()->completed()->create();
    $item = StockOpnameItem::factory()->balanced()->create(['system_quantity' => 5]);

    expect($opname->opname_date)->toBeInstanceOf(Carbon::class)
        ->and($opname->completed_at)->toBeInstanceOf(Carbon::class)
        ->and($item->system_quantity)->toBe('5.00')
        ->and($item->variance_quantity)->toBe('0.00');
});

it('defaults a new opname to DRAFT and supports status states', function () {
    expect(StockOpname::factory()->create()->status)->toBe(StockOpname::STATUS_DRAFT)
        ->and(StockOpname::factory()->counting()->create()->status)->toBe(StockOpname::STATUS_COUNTING)
        ->and(StockOpname::factory()->cancelled()->create()->status)->toBe(StockOpname::STATUS_CANCELLED);
});

it('records variance as counted minus system on the item factory', function () {
    $item = StockOpnameItem::factory()->create([
        'system_quantity' => 10,
        'counted_quantity' => 7,
        'variance_quantity' => -3,
    ]);

    expect((float) $item->variance_quantity)->toBe(-3.0);
});

it('mass-assigns all fillable attributes', function () {
    $branch = Branch::factory()->create();
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create();

    $opname = StockOpname::create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'opname_number' => 'OPN-TEST-0001',
        'opname_date' => now()->toDateString(),
        'status' => StockOpname::STATUS_DRAFT,
        'notes' => 'Quarterly count',
        'counted_by' => $user->id,
        'created_by' => $user->id,
    ]);

    expect($opname->opname_number)->toBe('OPN-TEST-0001')
        ->and($opname->branch_id)->toBe($branch->id)
        ->and($opname->inventory_location_id)->toBe($location->id);
});
