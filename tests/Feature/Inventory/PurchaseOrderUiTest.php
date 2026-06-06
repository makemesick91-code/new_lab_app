<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseRequestService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->approver = userWith(['approve_inventory_purchase_order', 'view_inventory']);
    $this->purchaseRequestService = app(PurchaseRequestService::class);
});

function createPoUiFixtures(object $test): array
{
    $supplier = Supplier::factory()->create([
        'branch_id' => $test->branch->id,
        'name' => 'PT Supplier UI Test',
    ]);
    $product = Product::factory()->create(['branch_id' => $test->branch->id]);

    return compact('supplier', 'product');
}

function createDraftPoForUi(object $test, ?Supplier $supplier = null, ?Product $product = null, array $overrides = []): PurchaseOrder
{
    ['supplier' => $defaultSupplier, 'product' => $defaultProduct] = createPoUiFixtures($test);
    $supplier ??= $defaultSupplier;
    $product ??= $defaultProduct;

    $purchaseOrder = PurchaseOrder::factory()->create(array_merge([
        'branch_id' => $test->branch->id,
        'status' => PurchaseOrder::STATUS_DRAFT,
        'supplier_id' => $supplier->id,
        'supplier_snapshot_name' => $supplier->name,
        'supplier_reference_number' => 'SUP-REF-UI-001',
        'currency' => 'IDR',
        'created_by' => $test->manager->id,
    ], $overrides));

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'quantity_ordered' => 10,
        'unit_price' => 25000,
    ]);

    return $purchaseOrder->refresh()->load('items');
}

function createApprovedPrForPoUi(object $test, int $productId): PurchaseRequest
{
    $purchaseRequest = $test->purchaseRequestService->createDraft([
        'request_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $productId,
                'quantity_requested' => 8,
                'estimated_unit_price' => 12000,
            ],
        ],
    ], $test->manager);

    $submitted = $test->purchaseRequestService->submit($purchaseRequest, $test->manager);

    return $test->purchaseRequestService->approve($submitted, $test->manager);
}

it('shows purchase order index labels for view user', function () {
    createDraftPoForUi($this);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.index'))
        ->assertOk()
        ->assertSee('Pesanan Pembelian')
        ->assertSee('Direktori Pesanan Pembelian');
});

it('shows po number supplier snapshot currency and status badge on index', function () {
    $purchaseOrder = createDraftPoForUi($this);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.index'))
        ->assertOk()
        ->assertSee($purchaseOrder->purchase_order_number)
        ->assertSee('PT Supplier UI Test')
        ->assertSee('IDR')
        ->assertSee('Draft');
});

it('shows stock disclaimer on show page', function () {
    $purchaseOrder = createDraftPoForUi($this);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee('Pesanan pembelian tidak menambah stok')
        ->assertSee('Stok bertambah hanya melalui penerimaan barang');
});

it('shows computed total amount on show page', function () {
    $purchaseOrder = createDraftPoForUi($this);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee(format_currency_id($purchaseOrder->total_amount));
});

it('shows supplier reference number on show page', function () {
    $purchaseOrder = createDraftPoForUi($this);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee('SUP-REF-UI-001')
        ->assertSee('Referensi Supplier');
});

it('shows create page title for manage user', function () {
    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.create'))
        ->assertOk()
        ->assertSee('Buat Pesanan Pembelian');
});

it('defaults currency to IDR on create page', function () {
    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.create'))
        ->assertOk()
        ->assertSee('value="IDR"', false);
});

it('allows edit page for draft purchase order', function () {
    $purchaseOrder = createDraftPoForUi($this);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.edit', $purchaseOrder))
        ->assertOk()
        ->assertSee('Ubah Pesanan Pembelian')
        ->assertSee($purchaseOrder->purchase_order_number);
});

it('renders all sprint 16.2 status badges', function () {
    foreach (PurchaseOrder::STATUSES as $status) {
        $view = view('inventory.purchase-orders._status-badge', ['status' => $status])->render();

        expect($view)->toMatch('/rounded-full/');
    }

    $labels = ['Draft', 'Diajukan', 'Disetujui', 'Dikirim', 'Dibatalkan'];

    foreach ($labels as $label) {
        $rendered = view('inventory.purchase-orders._status-badge', [
            'status' => match ($label) {
                'Draft' => PurchaseOrder::STATUS_DRAFT,
                'Diajukan' => PurchaseOrder::STATUS_SUBMITTED,
                'Disetujui' => PurchaseOrder::STATUS_APPROVED,
                'Dikirim' => PurchaseOrder::STATUS_SENT,
                'Dibatalkan' => PurchaseOrder::STATUS_CANCELLED,
            },
        ])->render();

        expect($rendered)->toContain($label);
    }
});

it('does not show receiving status badges or goods receipt actions', function () {
    $purchaseOrder = createDraftPoForUi($this);

    $response = $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder));

    $response->assertOk()
        ->assertDontSee('Terima Barang')
        ->assertDontSee('Goods Receipt')
        ->assertDontSee('Update Stok')
        ->assertDontSee('partially_received')
        ->assertDontSee('fully_received')
        ->assertDontSee('Diterima Sebagian')
        ->assertDontSee('Diterima Penuh');
});

it('hides create quick action from view only user', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertDontSee('Buat Pesanan Pembelian');
});

it('shows create quick action for manage user', function () {
    $this->actingAs($this->manager)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Buat Pesanan Pembelian');
});

it('shows sidebar link for authorized user', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Pesanan Pembelian');
});

it('scopes show workflow buttons by status and permission', function () {
    $draft = createDraftPoForUi($this);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.show', $draft))
        ->assertOk()
        ->assertSee('Ajukan')
        ->assertSee('Ubah')
        ->assertSee('Batalkan')
        ->assertDontSee('Setujui')
        ->assertDontSee('Kirim ke Supplier');

    $submitted = PurchaseOrder::factory()->submitted()->create([
        'branch_id' => $this->branch->id,
        'supplier_snapshot_name' => 'Supplier Submitted',
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->approver)
        ->get(route('inventory.purchase-orders.show', $submitted))
        ->assertOk()
        ->assertSee('Setujui')
        ->assertDontSee('Ubah')
        ->assertDontSee('Ajukan');

    $approved = PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branch->id,
        'supplier_snapshot_name' => 'Supplier Approved',
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.show', $approved))
        ->assertOk()
        ->assertSee('Kirim ke Supplier')
        ->assertDontSee('Setujui')
        ->assertDontSee('Ubah');
});

it('shows linked purchase request info on pr prefill create page', function () {
    ['product' => $product] = createPoUiFixtures($this);
    $purchaseRequest = createApprovedPrForPoUi($this, $product->id);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.create', ['purchase_request_id' => $purchaseRequest->id]))
        ->assertOk()
        ->assertSee('Dibuat dari Permintaan Pembelian')
        ->assertSee($purchaseRequest->purchase_request_number);
});

it('shows buat po button on approved purchase request show page for manage user', function () {
    ['product' => $product] = createPoUiFixtures($this);
    $purchaseRequest = createApprovedPrForPoUi($this, $product->id);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.show', $purchaseRequest))
        ->assertOk()
        ->assertSee('Buat PO')
        ->assertSee(route('inventory.purchase-orders.create', ['purchase_request_id' => $purchaseRequest->id]), false);
});

it('does not show buat po button on non approved purchase request show pages', function (string $status) {
    ['product' => $product] = createPoUiFixtures($this);
    $purchaseRequest = PurchaseRequest::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => $status,
    ]);
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $product->id,
        'quantity_requested' => 5,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.show', $purchaseRequest))
        ->assertOk()
        ->assertDontSee('Buat PO');
})->with([
    PurchaseRequest::STATUS_DRAFT,
    PurchaseRequest::STATUS_SUBMITTED,
    PurchaseRequest::STATUS_REJECTED,
    PurchaseRequest::STATUS_CANCELLED,
]);

it('does not show buat po button when approved purchase request already has active purchase order', function () {
    ['supplier' => $supplier, 'product' => $product] = createPoUiFixtures($this);
    $purchaseRequest = createApprovedPrForPoUi($this, $product->id);

    createDraftPoForUi($this, $supplier, $product, [
        'purchase_request_id' => $purchaseRequest->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.show', $purchaseRequest))
        ->assertOk()
        ->assertDontSee('Buat PO');
});

it('shows buat po button when approved purchase request only has cancelled purchase order', function () {
    ['supplier' => $supplier, 'product' => $product] = createPoUiFixtures($this);
    $purchaseRequest = createApprovedPrForPoUi($this, $product->id);

    createDraftPoForUi($this, $supplier, $product, [
        'purchase_request_id' => $purchaseRequest->id,
        'status' => PurchaseOrder::STATUS_CANCELLED,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.show', $purchaseRequest))
        ->assertOk()
        ->assertSee('Buat PO');
});

it('prefills purchase order create page item data from approved purchase request', function () {
    ['product' => $product] = createPoUiFixtures($this);
    $purchaseRequest = createApprovedPrForPoUi($this, $product->id);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.create', ['purchase_request_id' => $purchaseRequest->id]))
        ->assertOk()
        ->assertSee($product->name)
        ->assertSee('name="purchase_request_id"', false)
        ->assertSee('value="'.$purchaseRequest->id.'"', false);
});
