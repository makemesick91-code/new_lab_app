<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(PurchaseOrderService::class);
});

function multiVendorPo(object $test): array
{
    $supplierA = Supplier::factory()->create(['branch_id' => $test->branch->id, 'name' => 'Vendor Alpha']);
    $supplierB = Supplier::factory()->create(['branch_id' => $test->branch->id, 'name' => 'Vendor Beta']);
    $productA = Product::factory()->create(['branch_id' => $test->branch->id, 'name' => 'Sarung Tangan Alpha']);
    $productB = Product::factory()->create(['branch_id' => $test->branch->id, 'name' => 'Masker Beta']);

    $test->actingAs($test->manager);

    $purchaseOrder = $test->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $productA->id, 'supplier_id' => $supplierA->id, 'quantity_ordered' => 2, 'unit_price' => 1111, 'estimated_arrival_date' => now()->addDays(5)->toDateString()],
            ['product_id' => $productB->id, 'supplier_id' => $supplierB->id, 'quantity_ordered' => 3, 'unit_price' => 2222, 'estimated_arrival_date' => now()->addDays(9)->toDateString()],
        ],
    ], $test->manager);

    return compact('supplierA', 'supplierB', 'productA', 'productB', 'purchaseOrder');
}

/** Render the supplier PDF Blade to HTML (dependency-free content assertion). */
function renderSupplierPdfHtml(object $test, PurchaseOrder $purchaseOrder, Supplier $supplier): string
{
    $data = $test->service->buildSupplierPdfData($purchaseOrder->fresh(), $supplier);

    return view('inventory.purchase-orders.supplier-pdf', $data)->render();
}

it('lets an authorized user download a supplier-scoped PDF with the correct filename and mime', function () {
    ['supplierA' => $supplierA, 'purchaseOrder' => $purchaseOrder] = multiVendorPo($this);

    $response = $this->actingAs($this->manager)->get(route('inventory.purchase-orders.supplier-pdf', [
        'purchaseOrder' => $purchaseOrder->id,
        'supplier' => $supplierA->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('.pdf')
        ->and($response->headers->get('content-disposition'))->toContain('vendor-alpha');
});

it('scopes the supplier PDF to only that supplier and never leaks another supplier', function () {
    ['supplierA' => $supplierA, 'purchaseOrder' => $purchaseOrder] = multiVendorPo($this);

    $html = renderSupplierPdfHtml($this, $purchaseOrder, $supplierA);

    expect($html)->toContain('Vendor Alpha')
        ->toContain('Sarung Tangan Alpha')
        ->toContain('Purchase Order')
        ->toContain($purchaseOrder->purchase_order_number)
        // Must NOT leak the other supplier, its item, or its price.
        ->not->toContain('Vendor Beta')
        ->not->toContain('Masker Beta');
});

it('shows the estimated arrival date and the supplier subtotal on the PDF', function () {
    ['supplierA' => $supplierA, 'purchaseOrder' => $purchaseOrder] = multiVendorPo($this);

    $html = renderSupplierPdfHtml($this, $purchaseOrder, $supplierA);

    // 2 x 1111 = 2222 supplier subtotal, arrival date formatted in Indonesian.
    expect($html)->toContain(format_currency_id(2222))
        ->toContain(format_date_id(now()->addDays(5)->toDateString()));
});

it('builds a supplier PDF dataset containing only that supplier items', function () {
    ['supplierA' => $supplierA, 'supplierB' => $supplierB, 'purchaseOrder' => $purchaseOrder] = multiVendorPo($this);

    $this->actingAs($this->manager);
    $data = $this->service->buildSupplierPdfData($purchaseOrder->fresh(), $supplierA);

    expect($data['items'])->toHaveCount(1)
        ->and($data['items']->every(fn (PurchaseOrderItem $item) => (int) $item->supplier_id === $supplierA->id))->toBeTrue()
        ->and($data['subtotal'])->toBe(2222.0)
        ->and($data['items']->pluck('supplier_id')->contains($supplierB->id))->toBeFalse();
});

it('returns 404 for a supplier that has no item on the purchase order', function () {
    ['purchaseOrder' => $purchaseOrder] = multiVendorPo($this);
    $strangerSupplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)->get(route('inventory.purchase-orders.supplier-pdf', [
        'purchaseOrder' => $purchaseOrder->id,
        'supplier' => $strangerSupplier->id,
    ]))->assertNotFound();
});

it('denies a supplier PDF for a supplier from another branch', function () {
    ['purchaseOrder' => $purchaseOrder] = multiVendorPo($this);
    $foreignSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->actingAs($this->manager)->get(route('inventory.purchase-orders.supplier-pdf', [
        'purchaseOrder' => $purchaseOrder->id,
        'supplier' => $foreignSupplier->id,
    ]))->assertNotFound();
});

it('denies a supplier PDF when the purchase order belongs to another branch', function () {
    // PO + supplier live entirely in the other branch; the acting manager's
    // active branch is MAIN, so the PO is not resolvable for them.
    $foreignSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $foreignProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $foreignPo = PurchaseOrder::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'supplier_id' => $foreignSupplier->id,
        'supplier_snapshot_name' => $foreignSupplier->name,
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $foreignPo->id,
        'product_id' => $foreignProduct->id,
        'supplier_id' => $foreignSupplier->id,
    ]);

    // The PurchaseOrderPolicy denies cross-branch view before the controller
    // resolves the record — a forbidden response is the branch boundary here.
    $this->actingAs($this->manager)->get(route('inventory.purchase-orders.supplier-pdf', [
        'purchaseOrder' => $foreignPo->id,
        'supplier' => $foreignSupplier->id,
    ]))->assertForbidden();
});

it('denies a supplier PDF for a user without inventory permission', function () {
    ['purchaseOrder' => $purchaseOrder, 'supplierA' => $supplierA] = multiVendorPo($this);
    $unauthorized = User::factory()->create();

    $this->actingAs($unauthorized)->get(route('inventory.purchase-orders.supplier-pdf', [
        'purchaseOrder' => $purchaseOrder->id,
        'supplier' => $supplierA->id,
    ]))->assertForbidden();
});
