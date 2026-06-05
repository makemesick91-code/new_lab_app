<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->user = userWith(['manage master data']);
});

it('opens the inventory dashboard for an authenticated user', function () {
    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Dasbor Persediaan')
        ->assertSee('Kartu KPI Persediaan')
        ->assertSee('Total Nilai Persediaan')
        ->assertSee('Ringkasan Nilai Persediaan')
        ->assertSee('Stok per Lokasi')
        ->assertSee('Pergerakan Terbaru')
        ->assertSee('Material Paling Banyak Dipakai');
});

it('opens inventory product location supplier and stock indexes', function () {
    Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Zirconia UI Block']);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Gudang UI']);
    Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'PT UI Supplier']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.index'))
        ->assertOk()
        ->assertSee('Produk Persediaan')
        ->assertSee('Zirconia UI Block')
        ->assertSee('Total Stok Cabang')
        ->assertSee('Stok Saat Ini - Total Cabang')
        ->assertSee('Status Stok');

    $this->actingAs($this->user)
        ->get(route('inventory.locations.index'))
        ->assertOk()
        ->assertSee('Lokasi Persediaan')
        ->assertSee('Gudang UI');

    $this->actingAs($this->user)
        ->get(route('inventory.suppliers.index'))
        ->assertOk()
        ->assertSee('Pemasok Persediaan')
        ->assertSee('PT UI Supplier');

    $this->actingAs($this->user)
        ->get(route('inventory.stock.index'))
        ->assertOk()
        ->assertSee('Stok Persediaan');
});

it('shows product detail stock summary and safe action context', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Detail Summary Product',
        'minimum_stock' => 10,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    app(InventoryStockService::class)->createOpeningStock($product->id, $location->id, 5, 100, 'detail summary');

    $this->actingAs($this->user)
        ->get(route('inventory.products.show', $product))
        ->assertOk()
        ->assertSee('Kartu Ringkasan Produk')
        ->assertSee('Stok Saat Ini - Total Cabang')
        ->assertSee('Kejelasan Stok Cabang / Lokasi')
        ->assertSee('Nilai Persediaan')
        ->assertSee('Setiap operasi stok wajib memilih Lokasi Persediaan.')
        ->assertSee('Produk ini berada di bawah stok minimum.');
});

it('shows a required location selector on the opening stock form', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Opening Selector Location']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.opening-stock.create', $product))
        ->assertOk()
        ->assertSee('Stok Awal')
        ->assertSee('Panel Ringkasan Produk')
        ->assertSee('Buat Entri Ledger Awal')
        ->assertSee('Stok Awal membuat pergerakan ledger awal.')
        ->assertSee('Stok berbasis ledger')
        ->assertSee('Lokasi Persediaan')
        ->assertSee('name="inventory_location_id"', false)
        ->assertSee('required', false)
        ->assertSee('Opening Selector Location');
});

it('shows receive stock supplier and cost guidance', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Receive Location']);
    Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Receive Supplier']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.receive-stock.create', $product))
        ->assertOk()
        ->assertSee('Terima Stok ke Lokasi')
        ->assertSee('Terima Stok menambah jumlah ledger.')
        ->assertSee('Pemasok')
        ->assertSee('Biaya per Unit')
        ->assertSee('Receive Supplier')
        ->assertSee('Isi biaya per unit pemasok jika diketahui. Gunakan 0 hanya jika biaya tidak tersedia.');
});

it('shows adjustment out safety warning and no location disabled state', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->get(route('inventory.products.adjust-out.create', $product))
        ->assertOk()
        ->assertSee('Kurangi Stok sebagai Koreksi')
        ->assertSee('Penyesuaian Keluar mengurangi stok dan perlu kehati-hatian.')
        ->assertSee('stok lokasi tidak mencukupi')
        ->assertSee('Tidak ada Lokasi Persediaan aktif.')
        ->assertDontSee('Buat Penyesuaian Keluar');
});

it('does not show inactive locations in stock operation selectors', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Selectable Active Location']);
    InventoryLocation::factory()->inactive()->create(['branch_id' => $this->branch->id, 'name' => 'Hidden Inactive Location']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.opening-stock.create', $product))
        ->assertOk()
        ->assertSee('Selectable Active Location')
        ->assertDontSee('Hidden Inactive Location');
});

it('does not allow stock operation forms for inactive products', function () {
    $product = Product::factory()->inactive()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->get(route('inventory.products.opening-stock.create', $product))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->get(route('inventory.products.show', $product))
        ->assertOk()
        ->assertDontSee('Stok Awal')
        ->assertDontSee('Terima Stok');
});

it('shows running balance on the stock card', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Balance Product']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Balance Location']);
    $stock = app(InventoryStockService::class);

    $stock->createOpeningStock($product->id, $location->id, 10, 100, 'opening balance');
    $stock->adjustOut($product->id, $location->id, 3, 'out balance');

    $this->actingAs($this->user)
        ->get(route('inventory.products.stock-card', $product))
        ->assertOk()
        ->assertSee('Kartu Stok')
        ->assertSee('Kartu Stok Berbasis Ledger')
        ->assertSee('Stok dihitung dari pergerakan persediaan. Tidak ada kolom stok mutable yang digunakan.')
        ->assertSee('Lokasi Persediaan')
        ->assertSee('Tipe Pergerakan')
        ->assertSee('Riwayat Pergerakan Stok')
        ->assertSee('Saldo Berjalan')
        ->assertSee('Pergerakan persediaan manual')
        ->assertSee('Biaya tidak dicatat')
        ->assertSee('+10')
        ->assertSee('>7<', false)
        ->assertSee('Balance Location');
});

it('shows an empty state on the stock card when no movement matches filters', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Empty Card Product']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.stock-card', $product))
        ->assertOk()
        ->assertSee('Kartu Stok Berbasis Ledger')
        ->assertSee('Tidak ada pergerakan stok yang cocok dengan filter ini.')
        ->assertSee('Stok awal, penerimaan stok, dan penyesuaian akan muncul di sini setelah dicatat.');
});

it('does not show products or locations from another branch', function () {
    $otherBranch = Branch::factory()->create();

    Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Visible Branch Product']);
    Product::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Hidden Branch Product']);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Visible Branch Location']);
    InventoryLocation::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Hidden Branch Location']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.index'))
        ->assertOk()
        ->assertSee('Visible Branch Product')
        ->assertDontSee('Hidden Branch Product');

    $this->actingAs($this->user)
        ->get(route('inventory.locations.index'))
        ->assertOk()
        ->assertSee('Visible Branch Location')
        ->assertDontSee('Hidden Branch Location');
});

it('does not leak another branch location through the stock card filter', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($this->user)
        ->from(route('inventory.products.show', $product))
        ->get(route('inventory.products.stock-card', [
            'product' => $product,
            'inventory_location_id' => $otherLocation->id,
        ]))
        ->assertRedirect(route('inventory.products.show', $product))
        ->assertSessionHasErrors('inventory_location_id');
});

it('does not show another branch movement on the inventory dashboard', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Hidden Movement Product']);
    $location = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Hidden Movement Location']);

    InventoryMovement::factory()->opening()->create([
        'branch_id' => $otherBranch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'supplier_id' => null,
        'quantity_in' => 5,
        'quantity_out' => 0,
    ]);

    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertDontSee('Hidden Movement Product')
        ->assertDontSee('Hidden Movement Location');
});
