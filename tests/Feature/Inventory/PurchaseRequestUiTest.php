<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
});

it('shows purchase request index labels for view user', function () {
    PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.index'))
        ->assertOk()
        ->assertSee('Permintaan Pembelian')
        ->assertSee('Direktori Permintaan Pembelian');
});

it('shows create action for manage user and hides from view only', function () {
    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.index'))
        ->assertOk()
        ->assertSee('Buat Permintaan Pembelian');

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.index'))
        ->assertOk()
        ->assertDontSee('Buat Permintaan Pembelian');
});

it('shows sidebar link for users with viewAny purchase request policy', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Permintaan Pembelian');
});

it('scopes show action buttons by status and permission', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $draft = PurchaseRequest::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => PurchaseRequest::STATUS_DRAFT,
    ]);
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $draft->id,
        'product_id' => $product->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.show', $draft))
        ->assertOk()
        ->assertSee('Ajukan')
        ->assertSee('Ubah');

    $submitted = PurchaseRequest::factory()->submitted()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.show', $submitted))
        ->assertOk()
        ->assertSee('Setujui')
        ->assertSee('Tolak')
        ->assertDontSee('Ubah');
});

it('shows dashboard quick action for manage user', function () {
    $this->actingAs($this->manager)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Buat Permintaan Pembelian');
});

it('shows buat pr shortcut on alerts page for manage user only', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'reorder_quantity' => 50,
        'alert_enabled' => true,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $stock = app(InventoryStockService::class);
    $stock->createOpeningStock($product->id, $location->id, 5);
    $stock->adjustOut($product->id, $location->id, 5);

    $this->actingAs($this->manager)
        ->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('Buat PR');

    $this->actingAs($this->viewer)
        ->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertDontSee('Buat PR');
});
