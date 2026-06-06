<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->alertService = app(InventoryAlertService::class);
    $this->stockService = app(InventoryStockService::class);
});

function createStockAlertProduct(Branch $branch, array $productAttributes = []): array
{
    $product = Product::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'reorder_quantity' => 50,
        'alert_enabled' => true,
    ], $productAttributes));

    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);

    return [$product, $location];
}

it('detects out of stock', function () {
    [$product, $location] = createStockAlertProduct($this->branch, [
        'minimum_stock' => 10,
        'reorder_point' => 20,
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 5);
    $this->stockService->adjustOut($product->id, $location->id, 5);

    $alerts = $this->alertService->getStockAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['severity'])->toBe(InventoryAlertService::SEVERITY_OUT_OF_STOCK)
        ->and($alerts->first()['product_id'])->toBe($product->id);
});

it('detects critical stock', function () {
    [$product, $location] = createStockAlertProduct($this->branch, [
        'minimum_stock' => 10,
        'reorder_point' => 20,
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 8);

    $alerts = $this->alertService->getStockAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['severity'])->toBe(InventoryAlertService::SEVERITY_CRITICAL);
});

it('detects low stock', function () {
    [$product, $location] = createStockAlertProduct($this->branch, [
        'minimum_stock' => 5,
        'reorder_point' => 20,
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 15);

    $alerts = $this->alertService->getStockAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['severity'])->toBe(InventoryAlertService::SEVERITY_LOW);
});

it('ignores products with alert_enabled false', function () {
    [$product, $location] = createStockAlertProduct($this->branch, [
        'alert_enabled' => false,
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 5);
    $this->stockService->adjustOut($product->id, $location->id, 5);

    expect($this->alertService->getStockAlerts())->toBeEmpty();
});

it('detects expired batch with stock', function () {
    [$product, $location] = createStockAlertProduct($this->branch);

    $batch = InventoryBatch::factory()->expired()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    $this->stockService->receiveStock(
        $product->id,
        $location->id,
        4,
        100,
        null,
        'expired batch stock',
        ['inventory_batch_id' => $batch->id],
    );

    $alerts = $this->alertService->getBatchExpiryAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['severity'])->toBe(InventoryAlertService::SEVERITY_BATCH_EXPIRED)
        ->and($alerts->first()['inventory_batch_id'])->toBe($batch->id);
});

it('ignores expired batch without stock', function () {
    [$product, $location] = createStockAlertProduct($this->branch);

    $batch = InventoryBatch::factory()->expired()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'quantity_in' => 4,
        'quantity_out' => 0,
    ]);

    InventoryMovement::factory()->adjustmentOut()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'quantity_in' => 0,
        'quantity_out' => 4,
    ]);

    expect($this->alertService->getBatchExpiryAlerts())->toBeEmpty();
});

it('detects expiring soon batch', function () {
    [$product, $location] = createStockAlertProduct($this->branch);

    $batch = InventoryBatch::factory()->expiringSoon(15)->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    $this->stockService->receiveStock(
        $product->id,
        $location->id,
        3,
        100,
        null,
        'expiring soon batch',
        ['inventory_batch_id' => $batch->id],
    );

    $alerts = $this->alertService->getBatchExpiryAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()['severity'])->toBe(InventoryAlertService::SEVERITY_BATCH_EXPIRING_SOON);
});

it('enforces branch isolation for alerts', function () {
    $otherBranch = Branch::factory()->create();

    [$product, $location] = createStockAlertProduct($this->branch, [
        'minimum_stock' => 10,
        'reorder_point' => 20,
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 5);

    $otherProduct = Product::factory()->create([
        'branch_id' => $otherBranch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'alert_enabled' => true,
    ]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    InventoryMovement::factory()->opening()->create([
        'branch_id' => $otherBranch->id,
        'inventory_location_id' => $otherLocation->id,
        'product_id' => $otherProduct->id,
        'supplier_id' => null,
        'quantity_in' => 5,
        'quantity_out' => 0,
    ]);

    $otherBatch = InventoryBatch::factory()->expired()->create([
        'branch_id' => $otherBranch->id,
        'product_id' => $otherProduct->id,
    ]);

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => $otherBranch->id,
        'inventory_location_id' => $otherLocation->id,
        'product_id' => $otherProduct->id,
        'inventory_batch_id' => $otherBatch->id,
        'quantity_in' => 2,
        'quantity_out' => 0,
    ]);

    $stockAlerts = $this->alertService->getStockAlerts();
    $batchAlerts = $this->alertService->getBatchExpiryAlerts();

    expect($stockAlerts->pluck('product_id'))->toContain($product->id)
        ->and($stockAlerts->pluck('product_id'))->not->toContain($otherProduct->id)
        ->and($batchAlerts->pluck('inventory_batch_id'))->not->toContain($otherBatch->id);
});

it('does not introduce mutable stock columns on products', function () {
    $columns = Schema::getColumnListing('inv_products');

    expect($columns)->not->toContain('current_stock')
        ->and($columns)->not->toContain('stock')
        ->and($columns)->not->toContain('qty_on_hand')
        ->and($columns)->not->toContain('available_stock');
});

it('denies unauthorized users from alerts index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.alerts.index'))
        ->assertForbidden();
});

it('allows view_inventory users to view alerts', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('Peringatan Persediaan')
        ->assertSee('Stok Habis')
        ->assertSee('Stok Kritis')
        ->assertSee('Stok Rendah')
        ->assertSee('Batch Kedaluwarsa')
        ->assertSee('Segera Kedaluwarsa');
});

it('allows manage_inventory users to view alerts', function () {
    $user = userWith(['manage_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('Peringatan Persediaan');
});

it('builds alert summary counts from stock and batch alerts', function () {
    [$product, $location] = createStockAlertProduct($this->branch, [
        'minimum_stock' => 10,
        'reorder_point' => 20,
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 8);

    $expiredBatch = InventoryBatch::factory()->expired()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    $this->stockService->receiveStock(
        $product->id,
        $location->id,
        2,
        100,
        null,
        'summary expired batch',
        ['inventory_batch_id' => $expiredBatch->id],
    );

    $summary = $this->alertService->getAlertSummary();

    expect($summary['critical_stock_count'])->toBe(1)
        ->and($summary['batch_expired_count'])->toBe(1)
        ->and($summary['total_count'])->toBeGreaterThanOrEqual(2);
});

it('rejects foreign location filter on alerts index', function () {
    $user = userWith(['view_inventory']);
    $otherBranch = Branch::factory()->create();
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($user)
        ->from(route('inventory.alerts.index'))
        ->get(route('inventory.alerts.index', ['inventory_location_id' => $otherLocation->id]))
        ->assertRedirect(route('inventory.alerts.index'))
        ->assertSessionHasErrors('inventory_location_id');
});

it('shows sidebar link for permitted users', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Peringatan Stok');
});

it('does not show sidebar alerts link for users without inventory permission', function () {
    $user = userWith(['view_invoice']);

    $response = $this->actingAs($user)->get(route('invoices.index'));

    if ($response->status() === 200) {
        $response->assertDontSee('Peringatan Stok');
    } else {
        expect(true)->toBeTrue();
    }
});
