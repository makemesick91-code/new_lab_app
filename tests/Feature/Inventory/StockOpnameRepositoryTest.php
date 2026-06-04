<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Interfaces\StockOpnameRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Repositories\StockOpnameRepository;

beforeEach(function () {
    $this->repo = app(StockOpnameRepositoryInterface::class);
    $this->branch = Branch::factory()->create();
    $this->location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
});

it('binds the interface to the concrete repository', function () {
    expect($this->repo)->toBeInstanceOf(StockOpnameRepository::class);
});

it('paginates only the given branch (branch-safe)', function () {
    StockOpname::factory()->count(2)->create(['branch_id' => $this->branch->id, 'inventory_location_id' => $this->location->id]);
    StockOpname::factory()->create(); // different branch

    $result = $this->repo->paginate($this->branch->id);

    expect($result->total())->toBe(2)
        ->and($result->pluck('branch_id')->unique()->all())->toBe([$this->branch->id]);
});

it('filters pagination by status and location', function () {
    StockOpname::factory()->completed()->create(['branch_id' => $this->branch->id, 'inventory_location_id' => $this->location->id]);
    StockOpname::factory()->create(['branch_id' => $this->branch->id, 'inventory_location_id' => $this->location->id]); // DRAFT

    expect($this->repo->paginate($this->branch->id, ['status' => StockOpname::STATUS_COMPLETED])->total())->toBe(1)
        ->and($this->repo->paginate($this->branch->id, ['inventory_location_id' => $this->location->id])->total())->toBe(2);
});

it('creates and updates an opname', function () {
    $opname = $this->repo->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $this->location->id,
        'opname_number' => 'OPN-REPO-0001',
        'opname_date' => now()->toDateString(),
        'status' => StockOpname::STATUS_DRAFT,
    ]);

    expect($opname)->toBeInstanceOf(StockOpname::class);

    $updated = $this->repo->update($opname, ['status' => StockOpname::STATUS_COUNTING]);

    expect($updated->status)->toBe(StockOpname::STATUS_COUNTING);
});

it('finds by id only within the branch', function () {
    $opname = StockOpname::factory()->create(['branch_id' => $this->branch->id, 'inventory_location_id' => $this->location->id]);
    $other = StockOpname::factory()->create();

    expect($this->repo->findById($this->branch->id, $opname->id))->not->toBeNull()
        ->and($this->repo->findById($this->branch->id, $other->id))->toBeNull();
});

it('loads items with their products', function () {
    $opname = StockOpname::factory()
        ->has(StockOpnameItem::factory()->count(2), 'items')
        ->create(['branch_id' => $this->branch->id, 'inventory_location_id' => $this->location->id]);

    $loaded = $this->repo->loadItems($opname);

    expect($loaded->relationLoaded('items'))->toBeTrue()
        ->and($loaded->items)->toHaveCount(2)
        ->and($loaded->items->first()->relationLoaded('product'))->toBeTrue();
});

it('finalize lookup is branch-scoped and eager loads items and location', function () {
    $opname = StockOpname::factory()
        ->has(StockOpnameItem::factory()->count(1), 'items')
        ->create(['branch_id' => $this->branch->id, 'inventory_location_id' => $this->location->id]);
    $other = StockOpname::factory()->create();

    $found = $this->repo->finalizeLookup($this->branch->id, $opname->id);

    expect($found)->not->toBeNull()
        ->and($found->relationLoaded('items'))->toBeTrue()
        ->and($found->relationLoaded('inventoryLocation'))->toBeTrue()
        ->and($found->items->first()->relationLoaded('product'))->toBeTrue()
        ->and($this->repo->finalizeLookup($this->branch->id, $other->id))->toBeNull();
});
