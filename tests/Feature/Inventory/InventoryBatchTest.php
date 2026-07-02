<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->unauthorized = userWith(['view_lab_orders']);
});

/**
 * @return array{batch: InventoryBatch, product: Product, location: InventoryLocation, supplier: Supplier}
 */
function createBatchWithLedgerStock(
    Branch $branch,
    array $batchOverrides = [],
    float $quantity = 10,
): array {
    $product = $batchOverrides['product'] ?? Product::factory()->create(['branch_id' => $branch->id]);
    $supplier = $batchOverrides['supplier'] ?? Supplier::factory()->create(['branch_id' => $branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);

    unset($batchOverrides['product'], $batchOverrides['supplier']);

    $batch = InventoryBatch::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
    ], $batchOverrides));

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
        'inventory_batch_id' => $batch->id,
        'quantity_in' => $quantity,
        'quantity_out' => 0,
    ]);

    return compact('batch', 'product', 'location', 'supplier');
}

it('registers inventory batch index and show route names', function () {
    expect(Route::has('inventory.batches.index'))->toBeTrue()
        ->and(Route::has('inventory.batches.show'))->toBeTrue();
});

it('redirects guests from batch routes', function () {
    $batch = InventoryBatch::factory()->create(['branch_id' => $this->branch->id]);

    $this->get(route('inventory.batches.index'))->assertRedirect(route('login'));
    $this->get(route('inventory.batches.show', $batch))->assertRedirect(route('login'));
});

it('denies unauthorized users from batch routes', function () {
    $batch = InventoryBatch::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->unauthorized)
        ->get(route('inventory.batches.index'))
        ->assertForbidden();

    $this->actingAs($this->unauthorized)
        ->get(route('inventory.batches.show', $batch))
        ->assertForbidden();
});

it('allows view_inventory to access batch index and show', function () {
    $data = createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-VIEW-001',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('Batch & Lot')
        ->assertSee('B-VIEW-001');

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('B-VIEW-001');
});

it('allows manage_inventory to access batch index and show', function () {
    $data = createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-MGR-001',
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('B-MGR-001');

    $this->actingAs($this->manager)
        ->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('B-MGR-001');
});

it('denies cross-branch batch show access', function () {
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'product_id' => $otherProduct->id,
        'batch_number' => 'B-OTHER-BRANCH',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.show', $batch))
        ->assertForbidden();
});

it('does not list batches from another branch on index', function () {
    createBatchWithLedgerStock($this->branch, ['batch_number' => 'B-VISIBLE-BRANCH']);

    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    InventoryBatch::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'product_id' => $otherProduct->id,
        'batch_number' => 'B-HIDDEN-BRANCH',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('B-VISIBLE-BRANCH')
        ->assertDontSee('B-HIDDEN-BRANCH');
});

it('shows batch lot product and supplier on index', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Zirconia Batch Product',
    ]);
    $supplier = Supplier::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'PT Batch Supplier',
    ]);

    createBatchWithLedgerStock($this->branch, [
        'product' => $product,
        'supplier' => $supplier,
        'batch_number' => 'B-INDEX-001',
        'lot_number' => 'LOT-INDEX-99',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('B-INDEX-001')
        ->assertSee('LOT-INDEX-99')
        ->assertSee('Zirconia Batch Product')
        ->assertSee('PT Batch Supplier');
});

it('shows derived stock from ledger on index', function () {
    createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-STOCK-LEDGER',
    ], 17.5);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('B-STOCK-LEDGER')
        ->assertSee('17,5', false);
});

it('filters index by product', function () {
    $productA = Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Filter Product A']);
    $productB = Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Filter Product B']);

    createBatchWithLedgerStock($this->branch, [
        'product' => $productA,
        'batch_number' => 'B-FILTER-PRODUCT-A',
    ]);
    createBatchWithLedgerStock($this->branch, [
        'product' => $productB,
        'batch_number' => 'B-FILTER-PRODUCT-B',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index', ['product_id' => $productA->id]))
        ->assertOk()
        ->assertSee('B-FILTER-PRODUCT-A')
        ->assertDontSee('B-FILTER-PRODUCT-B');
});

it('filters index by supplier', function () {
    $supplierA = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Supplier Alpha']);
    $supplierB = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Supplier Beta']);

    createBatchWithLedgerStock($this->branch, [
        'supplier' => $supplierA,
        'batch_number' => 'B-FILTER-SUPPLIER-A',
    ]);
    createBatchWithLedgerStock($this->branch, [
        'supplier' => $supplierB,
        'batch_number' => 'B-FILTER-SUPPLIER-B',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index', ['supplier_id' => $supplierA->id]))
        ->assertOk()
        ->assertSee('B-FILTER-SUPPLIER-A')
        ->assertDontSee('B-FILTER-SUPPLIER-B');
});

it('filters index by expiry status', function () {
    createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-EXPIRED-FILTER',
        'expiry_date' => now()->subDays(5)->toDateString(),
        'received_date' => now()->subYear()->toDateString(),
    ]);

    createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-VALID-FILTER',
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index', ['expiry_status' => 'expired']))
        ->assertOk()
        ->assertSee('B-EXPIRED-FILTER')
        ->assertDontSee('B-VALID-FILTER');
});

it('shows derived stock by location on batch show page', function () {
    $data = createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-LOC-STOCK',
    ], 8);

    $secondLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Lokasi Batch Kedua',
    ]);

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $secondLocation->id,
        'product_id' => $data['product']->id,
        'inventory_batch_id' => $data['batch']->id,
        'quantity_in' => 4,
        'quantity_out' => 0,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('Stok per Lokasi')
        ->assertSee($data['location']->name)
        ->assertSee('Lokasi Batch Kedua')
        ->assertSee('8', false)
        ->assertSee('4', false);
});

it('shows movement history on batch show page', function () {
    $data = createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-MOVEMENT-HIST',
    ], 6);

    InventoryMovement::factory()->adjustmentOut()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'inventory_batch_id' => $data['batch']->id,
        'quantity_in' => 0,
        'quantity_out' => 2,
        'notes' => 'Koreksi batch uji',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('Riwayat Pergerakan Batch')
        ->assertSee('Penyesuaian Keluar')
        ->assertSee('Koreksi batch uji');
});

it('shows expired batch warning on show page', function () {
    $data = createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-EXPIRED-WARN',
        'expiry_date' => now()->subDays(3)->toDateString(),
        'received_date' => now()->subYear()->toDateString(),
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('Peringatan: Batch Kedaluwarsa')
        ->assertSee('Kedaluwarsa');
});

it('shows expiring soon warning on show page', function () {
    $data = createBatchWithLedgerStock($this->branch, [
        'batch_number' => 'B-EXPIRING-SOON',
        'expiry_date' => now()->addDays(14)->toDateString(),
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('Peringatan: Akan Kedaluwarsa')
        ->assertSee('Akan Kedaluwarsa');
});

it('does not introduce mutable stock columns on batch table', function () {
    $columns = Schema::getColumnListing('inv_inventory_batches');

    foreach (['current_stock', 'quantity_on_hand', 'available_quantity', 'stock', 'qty_on_hand'] as $column) {
        expect($columns)->not->toContain($column);
    }
});

it('shows Batch & Lot sidebar link for permitted users', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('Batch & Lot');
});

it('hides Batch & Lot sidebar link for unauthorized users', function () {
    $this->actingAs($this->unauthorized)
        ->get(route('inventory.dashboard'))
        ->assertForbidden();

    $this->actingAs(userWith(['view dashboard']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Batch & Lot');
});
