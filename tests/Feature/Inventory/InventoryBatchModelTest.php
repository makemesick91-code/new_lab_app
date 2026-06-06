<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

it('relates an inventory batch to branch, product, supplier, and created by user', function () {
    $batch = InventoryBatch::factory()->create();

    expect($batch->branch)->toBeInstanceOf(Branch::class)
        ->and($batch->product)->toBeInstanceOf(Product::class)
        ->and($batch->supplier)->toBeInstanceOf(Supplier::class)
        ->and($batch->createdBy)->toBeInstanceOf(User::class);
});

it('keeps batch product and supplier in the same branch as the batch', function () {
    $batch = InventoryBatch::factory()->create();

    expect($batch->product->branch_id)->toBe($batch->branch_id)
        ->and($batch->supplier->branch_id)->toBe($batch->branch_id);
});

it('relates a product and supplier to their batches', function () {
    $batch = InventoryBatch::factory()->create();

    expect($batch->product->batches->first()->id)->toBe($batch->id)
        ->and($batch->supplier->batches->first()->id)->toBe($batch->id);
});

it('relates an inventory batch to its movements', function () {
    $batch = InventoryBatch::factory()->create();

    $movement = InventoryMovement::factory()->purchase()->create([
        'branch_id' => $batch->branch_id,
        'product_id' => $batch->product_id,
        'inventory_batch_id' => $batch->id,
    ]);

    expect($batch->movements)->toHaveCount(1)
        ->and($batch->movements->first())->toBeInstanceOf(InventoryMovement::class)
        ->and($movement->inventoryBatch->id)->toBe($batch->id);
});

it('casts batch dates and is_active', function () {
    $batch = InventoryBatch::factory()->create([
        'expiry_date' => '2026-12-01',
        'received_date' => '2026-06-01',
        'is_active' => true,
    ]);

    expect($batch->expiry_date)->toBeInstanceOf(Carbon::class)
        ->and($batch->received_date)->toBeInstanceOf(Carbon::class)
        ->and($batch->is_active)->toBeTrue();
});

it('defaults a new batch to active and supports factory states', function () {
    expect(InventoryBatch::factory()->create()->is_active)->toBeTrue()
        ->and(InventoryBatch::factory()->inactive()->create()->is_active)->toBeFalse()
        ->and(InventoryBatch::factory()->withoutSupplier()->create()->supplier_id)->toBeNull()
        ->and(InventoryBatch::factory()->withoutLot()->create()->lot_number)->toBeNull();
});

it('mass-assigns all fillable attributes', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $branch->id]);
    $supplier = Supplier::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create();

    $batch = InventoryBatch::create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
        'batch_number' => 'B-TEST-001',
        'lot_number' => 'LOT-001',
        'expiry_date' => '2027-01-15',
        'received_date' => '2026-06-06',
        'notes' => 'COA on file',
        'is_active' => true,
        'created_by' => $user->id,
    ]);

    expect($batch->batch_number)->toBe('B-TEST-001')
        ->and($batch->branch_id)->toBe($branch->id)
        ->and($batch->product_id)->toBe($product->id)
        ->and($batch->supplier_id)->toBe($supplier->id);
});

it('allows movements without inventory_batch_id for backward compatibility', function () {
    $movement = InventoryMovement::factory()->purchase()->create([
        'inventory_batch_id' => null,
    ]);

    expect($movement->inventory_batch_id)->toBeNull()
        ->and($movement->inventoryBatch)->toBeNull();
});

it('scopes batches by branch and product', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $productA = Product::factory()->create(['branch_id' => $branchA->id]);
    $productB = Product::factory()->create(['branch_id' => $branchB->id]);

    InventoryBatch::factory()->create(['branch_id' => $branchA->id, 'product_id' => $productA->id]);
    InventoryBatch::factory()->create(['branch_id' => $branchB->id, 'product_id' => $productB->id]);

    expect(InventoryBatch::forBranch($branchA->id)->count())->toBe(1)
        ->and(InventoryBatch::forProduct($productA->id)->count())->toBe(1);
});

it('does not store mutable stock columns on the batch table', function () {
    $columns = Schema::getColumnListing('inv_inventory_batches');

    $forbidden = [
        'current_stock',
        'quantity_on_hand',
        'available_quantity',
        'stock',
        'qty_on_hand',
    ];

    foreach ($forbidden as $column) {
        expect($columns)->not->toContain($column);
    }

    expect((new InventoryBatch)->getFillable())->not->toContain('current_stock')
        ->and((new InventoryBatch)->getFillable())->not->toContain('quantity_on_hand');
});
