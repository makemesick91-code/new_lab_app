<?php

/**
 * SPRINT-68.45 Scope C — Vendor filter + procurement spend governance.
 *
 * Vendor filter = purchase/GR provenance (never net-stock ownership). Vendor
 * spend + received goods come from procurement truth (POSTED Goods Receipts),
 * not the ledger. Branch isolation on the supplier filter. No 500 on any tab
 * with a supplier + date range; the tabs without vendor provenance (Kartu Stok,
 * Stok per Ruangan) show a clear explanation instead of wrong data.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryReportService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branch->update(['is_rme_enabled' => true, 'is_inventory_enabled' => true]);
});

function s6845PurchaseMovement(Branch $branch, Product $product, Supplier $supplier): InventoryMovement
{
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);

    return InventoryMovement::factory()->purchase()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
        'supplier_id' => $supplier->id,
        'quantity_in' => 10,
        'quantity_out' => 0,
        'unit_cost' => 50000,
        'reference_type' => 'trx_goods_receipts',
        'movement_date' => now()->toDateString(),
    ]);
}

/**
 * Build a POSTED, PO-linked Goods Receipt so getSupplierPerformance() sees real
 * procurement truth (line_total + accepted_qty) for the supplier.
 */
function s6845PostedGr(Branch $branch, Supplier $supplier, Product $product, float $qty = 5, float $lineTotal = 250000): void
{
    $po = PurchaseOrder::factory()->sent()->create(['branch_id' => $branch->id, 'supplier_id' => $supplier->id]);
    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'product_id' => $product->id,
        'quantity_ordered' => 10,
        'unit_price' => 50000,
    ]);
    $gr = GoodsReceipt::factory()->posted()->create(['branch_id' => $branch->id, 'purchase_order_id' => $po->id]);
    GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $gr->id,
        'purchase_order_item_id' => $poItem->id,
        'product_id' => $product->id,
        'accepted_qty' => $qty,
        'rejected_qty' => 0,
        'line_total' => $lineTotal,
    ]);
}

it('narrows a report to the selected vendor by purchase provenance', function () {
    $a = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor A', 'is_active' => true]);
    $b = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor B', 'is_active' => true]);
    $pa = Product::factory()->create(['branch_id' => $this->branch->id]);
    $pb = Product::factory()->create(['branch_id' => $this->branch->id]);
    s6845PurchaseMovement($this->branch, $pa, $a);
    s6845PurchaseMovement($this->branch, $pb, $b);

    $repo = app(InventoryMovementRepositoryInterface::class);
    $filtered = collect($repo->getCurrentStockReport($this->branch->id, ['supplier_id' => $a->id], 50)->items())->pluck('product_id');

    expect($filtered)->toContain($pa->id);
    expect($filtered)->not->toContain($pb->id);
});

it('sources vendor spend and received goods from procurement truth (POSTED GR)', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor Truth', 'is_active' => true]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    s6845PostedGr($this->branch, $supplier, $product, qty: 5, lineTotal: 250000);

    $rows = app(InventoryAnalyticsRepositoryInterface::class)->getSupplierPerformance($this->branch->id);
    $row = collect($rows)->firstWhere('supplier_id', $supplier->id);

    expect($row)->not->toBeNull();
    expect((float) $row['received_value'])->toBe(250000.0);
    expect((int) $row['received_gr_item_count'])->toBe(1);
    expect((float) $row['received_gr_quantity'])->toBe(5.0);
});

it('keeps a same-branch supplier but drops a cross-branch supplier (branch isolation)', function () {
    $service = app(InventoryReportService::class);
    $own = Supplier::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
    $other = Branch::factory()->create(['is_active' => true, 'is_inventory_enabled' => true]);
    $foreign = Supplier::factory()->create(['branch_id' => $other->id, 'is_active' => true]);
    $user = userWith(['view_inventory']);

    $kept = $service->sanitizeReportFilters(['branch_id' => $this->branch->id, 'supplier_id' => $own->id], $user);
    expect($kept)->toHaveKey('supplier_id');

    $dropped = $service->sanitizeReportFilters(['branch_id' => $this->branch->id, 'supplier_id' => $foreign->id], $user);
    expect($dropped)->not->toHaveKey('supplier_id');
});

it('renders the vendor spend summary with received item + quantity columns', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor Kolom', 'is_active' => true]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    s6845PostedGr($this->branch, $supplier, $product);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('Ringkasan Belanja per Vendor')
        ->assertSee('Item Diterima')
        ->assertSee('Qty Diterima')
        ->assertSee('Total Belanja (GR)')
        ->assertSee('Vendor Kolom');
});

it('does not 500 on any report tab with a vendor filter and a date range', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    s6845PurchaseMovement($this->branch, $product, $supplier);
    $user = userWith(['view_inventory']);

    foreach (['current-stock', 'stock-card', 'low-stock', 'mutation', 'valuation', 'room-stock'] as $tab) {
        $response = $this->actingAs($user)->get(route('inventory.reports.index', [
            'tab' => $tab,
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'date_from' => now()->subWeek()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        expect($response->status())->not->toBe(500);
    }
});

it('explains that the vendor filter does not apply to Kartu Stok / Stok per Ruangan', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index', ['tab' => 'stock-card', 'supplier_id' => $supplier->id, 'product_id' => $product->id]))
        ->assertOk()
        ->assertSee('Filter vendor tidak diterapkan pada tab ini');

    $this->actingAs($user)
        ->get(route('inventory.reports.index', ['tab' => 'room-stock', 'supplier_id' => $supplier->id]))
        ->assertOk()
        ->assertSee('Filter vendor tidak diterapkan pada tab ini');
});

it('does not 500 when there is no vendor data', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk();
});
