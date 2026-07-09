<?php

/**
 * FIX-PRE-68-45 Scope F — Vendor/Supplier filter + per-vendor spend summary on
 * the inventory reports. Ledger stays SUM(quantity_in)-SUM(quantity_out); the
 * supplier filter narrows to purchase-provenance movements. The spend summary
 * sources procurement truth (getSupplierPerformance). Branch-isolation enforced
 * in sanitizeReportFilters.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryReportService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branch->update(['is_rme_enabled' => true, 'is_inventory_enabled' => true]);
});

function fvProduct(Branch $branch, string $name): Product
{
    return Product::factory()->create(['branch_id' => $branch->id, 'name' => $name]);
}

function fvPurchaseMovement(Branch $branch, Product $product, Supplier $supplier, float $qty = 10, float $cost = 50000): InventoryMovement
{
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);

    return InventoryMovement::factory()->purchase()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
        'supplier_id' => $supplier->id,
        'quantity_in' => $qty,
        'quantity_out' => 0,
        'unit_cost' => $cost,
        'reference_type' => 'trx_goods_receipts',
        'movement_date' => now()->toDateString(),
    ]);
}

it('narrows the current-stock report to the selected vendor (purchase provenance)', function () {
    $supplierA = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor Alpha', 'is_active' => true]);
    $supplierB = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor Beta', 'is_active' => true]);
    $productX = fvProduct($this->branch, 'Produk Dari Alpha');
    $productY = fvProduct($this->branch, 'Produk Dari Beta');
    fvPurchaseMovement($this->branch, $productX, $supplierA);
    fvPurchaseMovement($this->branch, $productY, $supplierB);

    $repo = app(InventoryMovementRepositoryInterface::class);

    // Without the filter: both products appear.
    $all = collect($repo->getCurrentStockReport($this->branch->id, [], 50)->items())->pluck('product_id');
    expect($all)->toContain($productX->id, $productY->id);

    // With supplier=A: only product X (received from A) remains.
    $filtered = collect($repo->getCurrentStockReport($this->branch->id, ['supplier_id' => $supplierA->id], 50)->items())->pluck('product_id');
    expect($filtered)->toContain($productX->id);
    expect($filtered)->not->toContain($productY->id);
});

it('renders the vendor filter select and the per-vendor spend summary', function () {
    Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor Gamma', 'is_active' => true]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('Vendor / Supplier')
        ->assertSee('name="supplier_id"', false)
        ->assertSee('Ringkasan Belanja per Vendor')
        ->assertSee('Vendor Gamma');
});

it('keeps a same-branch supplier filter but drops a cross-branch supplier (branch isolation)', function () {
    $service = app(InventoryReportService::class);
    $ownSupplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_inventory_enabled' => true]);
    $foreignSupplier = Supplier::factory()->create(['branch_id' => $otherBranch->id, 'is_active' => true]);

    $user = userWith(['view_inventory']); // scoped to MAIN via BranchContext (no cross-branch perm)

    $kept = $service->sanitizeReportFilters(['branch_id' => $this->branch->id, 'supplier_id' => $ownSupplier->id], $user);
    expect($kept)->toHaveKey('supplier_id');
    expect($kept['supplier_id'])->toBe($ownSupplier->id);

    $dropped = $service->sanitizeReportFilters(['branch_id' => $this->branch->id, 'supplier_id' => $foreignSupplier->id], $user);
    expect($dropped)->not->toHaveKey('supplier_id');
});

it('does not 500 when there is no vendor data', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk();
});
