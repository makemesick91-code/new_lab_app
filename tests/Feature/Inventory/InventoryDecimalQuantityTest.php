<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use App\Modules\Inventory\Services\PurchaseRequestService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\SchemaFacts;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory', 'manage master data']);
    $this->actingAs($this->manager);

    $this->stock = app(InventoryStockService::class);
    $this->purchaseRequestService = app(PurchaseRequestService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->goodsReceiptService = app(GoodsReceiptService::class);
    $this->stockTransferService = app(StockTransferService::class);
});

it('persists decimal minimum stock on product update', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 1,
    ]);

    $this->put(route('inventory.products.update', $product), [
        'product_category_id' => $product->product_category_id,
        'product_unit_id' => $product->product_unit_id,
        'name' => $product->name,
        'code' => $product->code,
        'minimum_stock' => 1.25,
        'reorder_point' => 0.5,
        'reorder_quantity' => 2.75,
        'alert_enabled' => 1,
        'is_active' => 1,
    ])->assertRedirect(route('inventory.products.show', $product));

    $product->refresh();

    expect($product->minimum_stock)->toBe('1.2500')
        ->and($product->reorder_point)->toBe('0.5000')
        ->and($product->reorder_quantity)->toBe('2.7500');
});

it('persists decimal quantity on purchase request items', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseRequest = $this->purchaseRequestService->createDraft([
        'request_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $product->id,
                'quantity_requested' => 2.75,
            ],
        ],
    ], $this->manager);

    $item = $purchaseRequest->items()->first();

    expect($item)->toBeInstanceOf(PurchaseRequestItem::class)
        ->and($item->quantity_requested)->toBe('2.7500');
});

it('persists decimal quantity on purchase order items', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = $this->purchaseOrderService->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'inventory_location_id' => $location->id,
                'quantity_ordered' => 1.25,
                'unit_price' => 1000,
                'estimated_arrival_date' => now()->toDateString(),
            ],
        ],
    ], $this->manager);

    $item = $purchaseOrder->items()->first();

    expect($item)->toBeInstanceOf(PurchaseOrderItem::class)
        ->and($item->quantity_ordered)->toBe('1.2500');
});

it('posts goods receipt decimal accepted quantity to the ledger', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = $this->purchaseOrderService->createDraft([
        'order_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'items' => [
            [
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'quantity_ordered' => 10,
                'unit_price' => 2500,
            ],
        ],
    ], $this->manager);

    $sent = $this->purchaseOrderService->markAsSent(
        $this->purchaseOrderService->approve(
            $this->purchaseOrderService->submit($purchaseOrder, $this->manager),
            $this->manager,
        ),
        $this->manager,
    );
    $poItem = $sent->items()->first();

    $goodsReceipt = $this->goodsReceiptService->createFromPurchaseOrder([
        'purchase_order_id' => $sent->id,
        'receipt_date' => now()->toDateString(),
        'items' => [
            [
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'received_qty' => 0.5,
                'accepted_qty' => 0.5,
                'rejected_qty' => 0,
            ],
        ],
    ], $this->manager);

    $posted = $this->goodsReceiptService->post($goodsReceipt, $this->manager);

    $movement = InventoryMovement::query()
        ->where('reference_type', $posted->getTable())
        ->where('reference_id', $posted->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity_in)->toBe('0.5000')
        ->and($movement->quantity_out)->toBe('0.0000')
        ->and($this->stock->getCurrentStock($product->id, $location->id))->toBe(0.5);
});

it('accepts decimal stock transfer quantity and keeps ledger-derived stock correct', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 10.5);

    $transfer = $this->stockTransferService->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1.25],
    ]);
    $this->stockTransferService->submitTransfer($transfer->id);
    $this->stockTransferService->shipTransfer($transfer->id);
    $received = $this->stockTransferService->receiveTransfer($transfer->id);

    expect($received->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and($received->items->first()->quantity)->toBe('1.2500')
        ->and($this->stock->getCurrentStock($product->id, $source->id))->toBe(9.25)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(1.25);
});

it('keeps ledger stock as sum of quantity in minus quantity out for decimal movements', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $location->id, 10.5);
    $this->stock->adjustIn($product->id, $location->id, 1.25);
    $this->stock->adjustOut($product->id, $location->id, 0.5);

    $ledgerSum = (float) DB::table('trx_inventory_movements')
        ->where('branch_id', $this->branch->id)
        ->where('product_id', $product->id)
        ->where('inventory_location_id', $location->id)
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
        ->value('current_stock');

    expect($ledgerSum)->toBe(11.25)
        ->and($this->stock->getCurrentStock($product->id, $location->id))->toBe(11.25);
});

it('stores inventory quantity columns as numeric 18 4 in the database', function () {
    /*
     * This ran on no driver at all before: it returned early on SQLite, and on
     * PostgreSQL it asserted the type string 'decimal' — which PostgreSQL never
     * reports, because its native spelling is 'numeric'. It also never checked
     * the 18,4 its own name promises.
     *
     * It now runs on both supported drivers and proves the full contract:
     * canonical type, precision AND scale.
     */
    $columns = [
        ['trx_inventory_movements', 'quantity_in'],
        ['trx_purchase_request_items', 'quantity_requested'],
        ['trx_purchase_order_items', 'quantity_ordered'],
        ['trx_goods_receipt_items', 'accepted_qty'],
        ['trx_stock_transfer_items', 'quantity'],
        ['trx_stock_opname_items', 'counted_quantity'],
        ['inv_products', 'minimum_stock'],
    ];

    /*
     * Precision and scale are a PostgreSQL-only contract, for two independent
     * and verified reasons:
     *
     * 1. The widening to NUMERIC(18,4) lives in
     *    2026_06_07_160000_alter_inventory_quantity_columns_to_decimal_18_4,
     *    which returns early unless the driver is pgsql.
     * 2. SQLite cannot express it at all: SQLiteGrammar::typeDecimal() emits a
     *    bare `numeric`, while PostgresGrammar emits `decimal(total, places)`.
     *
     * So both drivers are asserted against the contract each can actually hold.
     * Nothing is skipped — SQLite is asserted too, including the explicit fact
     * that it carries no precision — and PostgreSQL, the authoritative driver,
     * gets the strict 18,4 assertion this test is named for and never had.
     */
    $isPostgres = DB::getDriverName() === 'pgsql';

    foreach ($columns as [$table, $column]) {
        // Canonical type on both drivers: PostgreSQL's native spelling is
        // "numeric", never "decimal". Compare meaning, not driver spelling.
        expect(SchemaFacts::columnTypeName($table, $column))
            ->toBe('decimal', "Expected {$table}.{$column} to be a decimal column");

        $numeric = SchemaFacts::numericPrecisionScale($table, $column);

        if ($isPostgres) {
            expect($numeric['precision'])->toBe(18, "Expected {$table}.{$column} precision 18")
                ->and($numeric['scale'])->toBe(4, "Expected {$table}.{$column} scale 4");

            continue;
        }

        expect($numeric['precision'])
            ->toBeNull("SQLite cannot record precision for {$table}.{$column}; it stores a bare numeric")
            ->and($numeric['scale'])->toBeNull();
    }
});
