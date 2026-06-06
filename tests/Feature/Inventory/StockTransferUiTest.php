<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->stock = app(InventoryStockService::class);
});

function createUiDraftTransfer(Branch $branch, string $transferNumber = 'TRF-UI-001'): StockTransfer
{
    $source = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang Sumber UI']);
    $destination = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang Tujuan UI']);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'name' => 'Produk Transfer UI']);

    $transfer = StockTransfer::factory()->create([
        'branch_id' => $branch->id,
        'transfer_number' => $transferNumber,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
        'status' => StockTransfer::STATUS_DRAFT,
    ]);

    StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    return $transfer->refresh()->load(['sourceInventoryLocation', 'destinationInventoryLocation', 'items.product']);
}

it('opens the stock transfer index and uses Indonesian labels', function () {
    createUiDraftTransfer($this->branch);

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.index'))
        ->assertOk()
        ->assertSee('Transfer Stok')
        ->assertSee('Direktori Transfer Stok')
        ->assertSee('TRF-UI-001')
        ->assertSee('Gudang Sumber UI')
        ->assertSee('Gudang Tujuan UI')
        ->assertDontSee('Stock Transfer');
});

it('opens the stock transfer create page for managers', function () {
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Lokasi Create UI']);
    Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Produk Create UI']);

    $this->actingAs($this->manager)
        ->get(route('inventory.stock-transfers.create'))
        ->assertOk()
        ->assertSee('Buat Transfer Stok')
        ->assertSee('Lokasi Sumber')
        ->assertSee('Lokasi Tujuan')
        ->assertSee('Item Transfer')
        ->assertSee('Stok berbasis ledger')
        ->assertSee('Simpan Draft Transfer');
});

it('opens the stock transfer show page for viewers', function () {
    $transfer = createUiDraftTransfer($this->branch, 'TRF-UI-SHOW');

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertSee('TRF-UI-SHOW')
        ->assertSee('Gudang Sumber UI')
        ->assertSee('Gudang Tujuan UI')
        ->assertSee('Produk Transfer UI')
        ->assertSee('Item Transfer');
});

it('shows draft workflow buttons for managers and hides them for viewers', function () {
    $transfer = createUiDraftTransfer($this->branch, 'TRF-UI-ACTIONS');

    $this->actingAs($this->manager)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertSee('Ajukan Transfer')
        ->assertSee('Ubah')
        ->assertSee('Batalkan');

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertSee('TRF-UI-ACTIONS')
        ->assertDontSee('Ajukan Transfer')
        ->assertDontSee('Selesaikan Transfer')
        ->assertDontSee('Batalkan')
        ->assertDontSee('Simpan Draft Transfer');
});

it('shows ship button for submitted transfers and managers only', function () {
    $transfer = createUiDraftTransfer($this->branch, 'TRF-UI-SUBMITTED');
    $transfer->update(['status' => StockTransfer::STATUS_SUBMITTED]);

    $this->actingAs($this->manager)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertSee('Kirim Transfer')
        ->assertDontSee('Ajukan Transfer')
        ->assertDontSee('Selesaikan Transfer');

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertDontSee('Kirim Transfer');
});

it('shows receive button for in transit transfers and managers only', function () {
    $transfer = createUiDraftTransfer($this->branch, 'TRF-UI-IN-TRANSIT');
    $transfer->update([
        'status' => StockTransfer::STATUS_IN_TRANSIT,
        'shipped_at' => now(),
        'shipped_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertSee('Terima Transfer')
        ->assertDontSee('Kirim Transfer')
        ->assertDontSee('Ajukan Transfer');

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertDontSee('Terima Transfer');
});

it('shows ledger movement reference on received transfers', function () {
    $transfer = createUiDraftTransfer($this->branch, 'TRF-UI-RECEIVED');
    $productId = $transfer->items->first()->product_id;

    $this->stock->createOpeningStock($productId, $transfer->source_inventory_location_id, 10, 10000);

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.submit', $transfer));

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.ship', $transfer));

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.receive', $transfer));

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer->refresh()))
        ->assertOk()
        ->assertSee('Referensi Pergerakan Ledger')
        ->assertSee('Transfer Keluar')
        ->assertSee('Transfer Masuk')
        ->assertSee('Produk Transfer UI')
        ->assertSee('Diterima Oleh')
        ->assertSee('Diterima Pada');
});

it('shows ledger movement reference on in transit transfers after ship only', function () {
    $transfer = createUiDraftTransfer($this->branch, 'TRF-UI-IN-TRANSIT-LEDGER');
    $productId = $transfer->items->first()->product_id;

    $this->stock->createOpeningStock($productId, $transfer->source_inventory_location_id, 10, 10000);

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.submit', $transfer));

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.ship', $transfer));

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer->refresh()))
        ->assertOk()
        ->assertSee('Referensi Pergerakan Ledger')
        ->assertSee('Transfer Keluar')
        ->assertDontSee('Transfer Masuk');
});

it('hides create transfer button on index for view-only users', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.index'))
        ->assertOk()
        ->assertSee('Transfer Stok')
        ->assertDontSee('Buat Transfer Stok');
});

it('denies view-only users from the create page', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.create'))
        ->assertForbidden();
});
