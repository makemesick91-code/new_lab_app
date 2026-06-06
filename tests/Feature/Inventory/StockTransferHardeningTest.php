<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->unauthorized = userWith([]);
    $this->stock = app(InventoryStockService::class);
    $this->service = app(StockTransferService::class);
});

function hardeningTransferPayload(int $sourceId, int $destinationId, int $productId, float $quantity = 2): array
{
    return [
        'source_inventory_location_id' => $sourceId,
        'destination_inventory_location_id' => $destinationId,
        'transfer_date' => now()->toDateString(),
        'notes' => 'Hardening integration transfer',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => $quantity,
            ],
        ],
    ];
}

function hardeningSubmittedTransfer(Branch $branch, float $quantity = 3): StockTransfer
{
    $source = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create(['branch_id' => $branch->id]);

    $transfer = StockTransfer::factory()->submitted()->create([
        'branch_id' => $branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ]);

    StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
    ]);

    return $transfer->refresh();
}

it('does not list transfers from another branch on the index page', function () {
    $visible = hardeningSubmittedTransfer($this->branch);
    $hidden = hardeningSubmittedTransfer($this->otherBranch);

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.index'))
        ->assertOk()
        ->assertSee($visible->transfer_number)
        ->assertDontSee($hidden->transfer_number);
});

it('denies viewing and completing another branch transfer through HTTP', function () {
    $transfer = hardeningSubmittedTransfer($this->otherBranch);

    $this->actingAs($this->manager)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.complete', $transfer))
        ->assertForbidden();

    expect($transfer->refresh()->status)->toBe(StockTransfer::STATUS_SUBMITTED);
});

it('denies completing another branch transfer at the service layer', function () {
    $this->actingAs($this->manager);

    $transfer = hardeningSubmittedTransfer($this->otherBranch);

    expect(fn () => $this->service->completeTransfer($transfer->id))
        ->toThrow(ValidationException::class);
});

it('requires source and destination locations to belong to the active branch', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherDestination = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->post(
            route('inventory.stock-transfers.store'),
            hardeningTransferPayload($source->id, $otherDestination->id, $product->id),
        )
        ->assertSessionHasErrors('destination_inventory_location_id');

    $this->assertDatabaseCount('trx_stock_transfers', 0);
});

it('blocks completion when source stock is insufficient via HTTP and leaves status submitted', function () {
    $transfer = hardeningSubmittedTransfer($this->branch, 8);
    $productId = $transfer->items->first()->product_id;

    $this->stock->createOpeningStock($productId, $transfer->source_inventory_location_id, 3);

    $this->actingAs($this->manager)
        ->from(route('inventory.stock-transfers.show', $transfer))
        ->post(route('inventory.stock-transfers.complete', $transfer))
        ->assertRedirect(route('inventory.stock-transfers.show', $transfer))
        ->assertSessionHasErrors('quantity');

    expect($transfer->refresh()->status)->toBe(StockTransfer::STATUS_SUBMITTED)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_transfers')
            ->where('reference_id', $transfer->id)
            ->count())->toBe(0);
});

it('writes transfer out and transfer in movements at the correct locations with derived balances', function () {
    $this->actingAs($this->manager);

    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $firstProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 10000]);
    $secondProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 5000]);

    $this->stock->createOpeningStock($firstProduct->id, $source->id, 20, 10000);
    $this->stock->createOpeningStock($secondProduct->id, $source->id, 15, 5000);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $firstProduct->id, 'quantity' => 6],
        ['product_id' => $secondProduct->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->completeTransfer($transfer->id);

    $movements = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->orderBy('id')
        ->get();

    $outMovements = $movements->where('movement_type', InventoryMovement::TYPE_TRANSFER_OUT);
    $inMovements = $movements->where('movement_type', InventoryMovement::TYPE_TRANSFER_IN);

    expect($outMovements)->toHaveCount(2)
        ->and($inMovements)->toHaveCount(2)
        ->and($outMovements->every(fn ($movement) => $movement->inventory_location_id === $source->id))->toBeTrue()
        ->and($outMovements->every(fn ($movement) => (float) $movement->quantity_out > 0 && (float) $movement->quantity_in === 0.0))->toBeTrue()
        ->and($inMovements->every(fn ($movement) => $movement->inventory_location_id === $destination->id))->toBeTrue()
        ->and($inMovements->every(fn ($movement) => (float) $movement->quantity_in > 0 && (float) $movement->quantity_out === 0.0))->toBeTrue()
        ->and($this->stock->getCurrentStock($firstProduct->id, $source->id))->toBe(14.0)
        ->and($this->stock->getCurrentStock($secondProduct->id, $source->id))->toBe(11.0)
        ->and($this->stock->getCurrentStock($firstProduct->id, $destination->id))->toBe(6.0)
        ->and($this->stock->getCurrentStock($secondProduct->id, $destination->id))->toBe(4.0);
});

it('does not introduce or use mutable stock columns on products or locations', function () {
    $forbiddenColumns = [
        'current_stock',
        'stock',
        'qty_on_hand',
        'available_stock',
        'quantity_on_hand',
    ];

    foreach ($forbiddenColumns as $column) {
        expect(Schema::hasColumn('inv_products', $column))->toBeFalse()
            ->and(Schema::hasColumn('inv_inventory_locations', $column))->toBeFalse();
    }

    expect((new Product)->getFillable())->not->toContain('current_stock', 'stock', 'qty_on_hand')
        ->and((new InventoryLocation)->getFillable())->not->toContain('current_stock', 'stock', 'qty_on_hand');

    $this->actingAs($this->manager);

    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 10);
    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->completeTransfer($transfer->id);

    foreach ([$product->refresh(), $source->refresh(), $destination->refresh()] as $model) {
        expect(array_intersect(array_keys($model->getAttributes()), $forbiddenColumns))->toBe([]);
    }

    expect($this->stock->getCurrentStock($product->id, $source->id))->toBe(6.0)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(4.0)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_transfers')
            ->where('reference_id', $transfer->id)
            ->count())->toBe(2);
});

it('rejects store requests with same source and destination invalid product and missing items', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $sameLocationPayload = hardeningTransferPayload($source->id, $source->id, $product->id);
    $invalidProductPayload = hardeningTransferPayload($source->id, $destination->id, 999999);
    $invalidQuantityPayload = hardeningTransferPayload($source->id, $destination->id, $product->id, 0);
    $missingItemsPayload = [
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ];

    $request = new StoreStockTransferRequest;
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    foreach ([
        'same location' => $sameLocationPayload,
        'invalid product' => $invalidProductPayload,
        'invalid quantity' => $invalidQuantityPayload,
        'missing items' => $missingItemsPayload,
    ] as $label => $payload) {
        $request->replace($payload);
        $validator = validator($payload, $request->rules(), $request->messages(), $request->attributes());
        $request->withValidator($validator);

        expect($validator->fails())->toBeTrue("Expected {$label} payload to fail validation");
    }
});

it('enforces draft update submitted completion and terminal status guards', function () {
    $this->actingAs($this->manager);

    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $replacementDestination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $replacementProduct = Product::factory()->create(['branch_id' => $this->branch->id]);

    $draft = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);

    $updatedDraft = $this->service->updateTransfer(
        $draft->id,
        $source->id,
        $replacementDestination->id,
        [['product_id' => $replacementProduct->id, 'quantity' => 5]],
    );

    expect($updatedDraft->status)->toBe(StockTransfer::STATUS_DRAFT)
        ->and($updatedDraft->destination_inventory_location_id)->toBe($replacementDestination->id);

    $this->stock->createOpeningStock($replacementProduct->id, $source->id, 10);
    $submitted = $this->service->submitTransfer($updatedDraft->id);
    $completed = $this->service->completeTransfer($submitted->id);

    expect($submitted->status)->toBe(StockTransfer::STATUS_SUBMITTED)
        ->and($completed->status)->toBe(StockTransfer::STATUS_COMPLETED);

    expect(fn () => $this->service->updateTransfer(
        $completed->id,
        $source->id,
        $replacementDestination->id,
        [['product_id' => $replacementProduct->id, 'quantity' => 1]],
    ))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->cancelTransfer($completed->id))
        ->toThrow(ValidationException::class);

    $cancelled = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);
    $this->service->submitTransfer($cancelled->id);
    $this->service->cancelTransfer($cancelled->id);

    expect(fn () => $this->service->completeTransfer($cancelled->id))
        ->toThrow(ValidationException::class);
});

it('blocks editing or completing completed and cancelled transfers through HTTP', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $completed = StockTransfer::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ]);
    StockTransferItem::factory()->create([
        'stock_transfer_id' => $completed->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $this->actingAs($this->manager)
        ->from(route('inventory.stock-transfers.show', $completed))
        ->put(
            route('inventory.stock-transfers.update', $completed),
            hardeningTransferPayload($source->id, $destination->id, $product->id),
        )
        ->assertRedirect(route('inventory.stock-transfers.show', $completed))
        ->assertSessionHasErrors('status');

    $this->actingAs($this->manager)
        ->from(route('inventory.stock-transfers.show', $completed))
        ->post(route('inventory.stock-transfers.cancel', $completed))
        ->assertRedirect(route('inventory.stock-transfers.show', $completed))
        ->assertSessionHasErrors('status');

    $cancelled = StockTransfer::factory()->cancelled()->create([
        'branch_id' => $this->branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ]);
    StockTransferItem::factory()->create([
        'stock_transfer_id' => $cancelled->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    $this->actingAs($this->manager)
        ->from(route('inventory.stock-transfers.show', $cancelled))
        ->post(route('inventory.stock-transfers.complete', $cancelled))
        ->assertRedirect(route('inventory.stock-transfers.show', $cancelled))
        ->assertSessionHasErrors('status');
});

it('denies unauthorized users from all stock transfer routes', function () {
    $transfer = hardeningSubmittedTransfer($this->branch);
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $routes = [
        ['get', route('inventory.stock-transfers.index')],
        ['get', route('inventory.stock-transfers.create')],
        ['post', route('inventory.stock-transfers.store'), hardeningTransferPayload($source->id, $destination->id, $product->id)],
        ['get', route('inventory.stock-transfers.show', $transfer)],
        ['get', route('inventory.stock-transfers.edit', $transfer)],
        ['put', route('inventory.stock-transfers.update', $transfer), hardeningTransferPayload($source->id, $destination->id, $product->id)],
        ['post', route('inventory.stock-transfers.submit', $transfer)],
        ['post', route('inventory.stock-transfers.complete', $transfer)],
        ['post', route('inventory.stock-transfers.cancel', $transfer)],
    ];

    foreach ($routes as $route) {
        $method = $route[0];
        $url = $route[1];
        $payload = $route[2] ?? [];

        $response = $this->actingAs($this->unauthorized)->{$method}($url, $payload);

        expect($response->status())->toBe(403);
    }
});

it('requires authentication for stock transfer routes', function () {
    $transfer = hardeningSubmittedTransfer($this->branch);

    $this->get(route('inventory.stock-transfers.index'))->assertRedirect(route('login'));
    $this->get(route('inventory.stock-transfers.show', $transfer))->assertRedirect(route('login'));
    $this->post(route('inventory.stock-transfers.complete', $transfer))->assertRedirect(route('login'));
});

it('allows view_inventory to read but not mutate stock transfers', function () {
    $transfer = hardeningSubmittedTransfer($this->branch);
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.index'))
        ->assertOk();

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk();

    $this->actingAs($this->viewer)
        ->post(route('inventory.stock-transfers.store'), hardeningTransferPayload($source->id, $destination->id, $product->id))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.stock-transfers.complete', $transfer))
        ->assertForbidden();
});

it('allows manage_inventory to mutate stock transfers in the active branch', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 10);

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.store'), hardeningTransferPayload($source->id, $destination->id, $product->id, 3))
        ->assertRedirect();

    $transfer = StockTransfer::query()->latest('id')->firstOrFail();

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.submit', $transfer))
        ->assertRedirect();

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.complete', $transfer))
        ->assertRedirect();

    expect($transfer->refresh()->status)->toBe(StockTransfer::STATUS_COMPLETED);
});
