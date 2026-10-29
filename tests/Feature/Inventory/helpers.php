<?php

/*
|--------------------------------------------------------------------------
| CI-PERF-03 — shared goods-receipt fixtures for the Inventory suites.
|--------------------------------------------------------------------------
|
| These helpers used to be declared inside GoodsReceiptServiceTest.php and
| GoodsReceiptBatchTest.php and called from a dozen sibling suites. That only
| works when Pest has loaded the declaring file first, which a serial run
| always does and a parallel worker usually does not: run on its own, every
| borrowing suite failed with "Call to undefined function".
|
| Require this file from each test file that uses it:
|
|     require_once __DIR__.'/helpers.php';
|
| Every function is wrapped in a function_exists() guard so several test files
| may require it in the same process. The bodies are unchanged; each one reads
| branch, manager and purchaseOrderService from the calling test case.
*/

use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;

if (! function_exists('grBranchFixtures')) {
    function grBranchFixtures(object $test): array
    {
        $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);
        $product = Product::factory()->create(['branch_id' => $test->branch->id]);
        $location = InventoryLocation::factory()->create(['branch_id' => $test->branch->id]);

        return compact('supplier', 'product', 'location');
    }
}

if (! function_exists('grPurchaseOrderPayload')) {
    function grPurchaseOrderPayload(
        int $supplierId,
        int $productId,
        int $locationId,
        float $quantity = 10,
        float $unitPrice = 2500,
    ): array {
        return [
            'order_date' => now()->toDateString(),
            'supplier_id' => $supplierId,
            'items' => [
                [
                    'product_id' => $productId,
                    'supplier_id' => $supplierId,
                    'inventory_location_id' => $locationId,
                    'quantity_ordered' => $quantity,
                    'unit_price' => $unitPrice,
                    'estimated_arrival_date' => now()->toDateString(),
                ],
            ],
        ];
    }
}

if (! function_exists('advancePoToSent')) {
    function advancePoToSent(object $test, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $submitted = $test->purchaseOrderService->submit($purchaseOrder, $test->manager);
        $approved = $test->purchaseOrderService->approve($submitted, $test->manager);

        return $test->purchaseOrderService->markAsSent($approved, $test->manager);
    }
}

if (! function_exists('createSentPurchaseOrderWithItem')) {
    function createSentPurchaseOrderWithItem(
        object $test,
        float $quantity = 10,
        float $unitPrice = 2500,
    ): array {
        ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grBranchFixtures($test);

        $purchaseOrder = $test->purchaseOrderService->createDraft(
            grPurchaseOrderPayload($supplier->id, $product->id, $location->id, $quantity, $unitPrice),
            $test->manager,
        );

        $sent = advancePoToSent($test, $purchaseOrder);
        $poItem = $sent->items()->first();

        return compact('supplier', 'product', 'location', 'purchaseOrder', 'sent', 'poItem');
    }
}

if (! function_exists('goodsReceiptPayload')) {
    function goodsReceiptPayload(
        int $purchaseOrderId,
        int $poItemId,
        int $productId,
        int $locationId,
        float $acceptedQty = 5,
        float $rejectedQty = 0,
        array $overrides = [],
    ): array {
        return array_merge([
            'purchase_order_id' => $purchaseOrderId,
            'receipt_date' => now()->toDateString(),
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'product_id' => $productId,
                    'inventory_location_id' => $locationId,
                    'received_qty' => $acceptedQty + $rejectedQty,
                    'accepted_qty' => $acceptedQty,
                    'rejected_qty' => $rejectedQty,
                ],
            ],
        ], $overrides);
    }
}

if (! function_exists('grBatchPayload')) {
    function grBatchPayload(
        int $purchaseOrderId,
        int $poItemId,
        int $productId,
        int $locationId,
        float $acceptedQty = 5,
        array $batchOverrides = [],
        array $overrides = [],
    ): array {
        return array_merge([
            'purchase_order_id' => $purchaseOrderId,
            'receipt_date' => now()->toDateString(),
            'items' => [
                array_merge([
                    'purchase_order_item_id' => $poItemId,
                    'product_id' => $productId,
                    'inventory_location_id' => $locationId,
                    'received_qty' => $acceptedQty,
                    'accepted_qty' => $acceptedQty,
                    'rejected_qty' => 0,
                    'batch_mode' => 'new',
                    'auto_batch' => false,
                    'batch_number' => 'B-GR-001',
                    'lot_number' => 'LOT-GR-01',
                    'batch_received_date' => now()->toDateString(),
                    'expiry_date' => now()->addYear()->toDateString(),
                ], $batchOverrides),
            ],
        ], $overrides);
    }
}

if (! function_exists('createSentPurchaseOrderWithBatchProduct')) {
    function createSentPurchaseOrderWithBatchProduct(object $test, float $quantity = 10): array
    {
        $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);
        $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $test->branch->id]);
        $location = InventoryLocation::factory()->create(['branch_id' => $test->branch->id]);

        $purchaseOrder = $test->purchaseOrderService->createDraft([
            'order_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'items' => [[
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'quantity_ordered' => $quantity,
                'unit_price' => 2500,
            ]],
        ], $test->manager);

        $submitted = $test->purchaseOrderService->submit($purchaseOrder, $test->manager);
        $approved = $test->purchaseOrderService->approve($submitted, $test->manager);
        $sent = $test->purchaseOrderService->markAsSent($approved, $test->manager);
        $poItem = $sent->items()->first();

        return compact('supplier', 'product', 'location', 'sent', 'poItem');
    }
}
