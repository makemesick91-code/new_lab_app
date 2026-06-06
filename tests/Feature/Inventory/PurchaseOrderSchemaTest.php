<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return array<int, string>
 */
function purchaseOrderTableIndexes(string $table): array
{
    return collect(DB::select('PRAGMA index_list('.DB::getPdo()->quote($table).')'))
        ->pluck('name')
        ->all();
}

/**
 * @return array<int, string>
 */
function purchaseOrderIndexColumns(string $indexName): array
{
    return collect(DB::select('PRAGMA index_info('.DB::getPdo()->quote($indexName).')'))
        ->sortBy('seqno')
        ->pluck('name')
        ->all();
}

it('creates trx_purchase_orders table with required columns', function () {
    expect(Schema::hasTable('trx_purchase_orders'))->toBeTrue();

    $columns = Schema::getColumnListing('trx_purchase_orders');

    foreach ([
        'id',
        'branch_id',
        'purchase_order_number',
        'order_date',
        'status',
        'supplier_id',
        'supplier_snapshot_name',
        'supplier_reference_number',
        'currency',
        'purchase_request_id',
        'expected_delivery_date',
        'notes',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'sent_by',
        'sent_at',
        'created_by',
        'created_at',
        'updated_at',
    ] as $column) {
        expect($columns)->toContain($column);
    }
});

it('creates trx_purchase_order_items table with required columns', function () {
    expect(Schema::hasTable('trx_purchase_order_items'))->toBeTrue();

    $columns = Schema::getColumnListing('trx_purchase_order_items');

    foreach ([
        'id',
        'purchase_order_id',
        'product_id',
        'inventory_location_id',
        'purchase_request_item_id',
        'quantity_ordered',
        'unit_price',
        'notes',
        'created_at',
        'updated_at',
    ] as $column) {
        expect($columns)->toContain($column);
    }
});

it('stores supplier snapshot and reference fields on purchase order header', function () {
    expect(Schema::hasColumn('trx_purchase_orders', 'supplier_snapshot_name'))->toBeTrue()
        ->and(Schema::hasColumn('trx_purchase_orders', 'supplier_reference_number'))->toBeTrue();
});

it('stores currency on purchase order header with IDR default', function () {
    expect(Schema::hasColumn('trx_purchase_orders', 'currency'))->toBeTrue();

    $currency = collect(DB::select('PRAGMA table_info(trx_purchase_orders)'))
        ->firstWhere('name', 'currency');

    expect($currency)->not->toBeNull()
        ->and($currency->dflt_value)->toBe("'IDR'");
});

it('enforces unique purchase_order_number', function () {
    $indexes = purchaseOrderTableIndexes('trx_purchase_orders');

    $uniqueNumberIndexes = collect($indexes)
        ->filter(function (string $indexName): bool {
            $info = DB::select('PRAGMA index_list('.DB::getPdo()->quote('trx_purchase_orders').')');

            return collect($info)->firstWhere('name', $indexName)?->unique === 1
                && purchaseOrderIndexColumns($indexName) === ['purchase_order_number'];
        })
        ->values()
        ->all();

    expect($uniqueNumberIndexes)->not->toBeEmpty();
});

it('creates required indexes on trx_purchase_orders', function () {
    $indexes = purchaseOrderTableIndexes('trx_purchase_orders');

    foreach ([
        'trx_purchase_orders_branch_id_index',
        'trx_purchase_orders_status_index',
        'trx_purchase_orders_order_date_index',
        'trx_purchase_orders_supplier_id_index',
        'trx_purchase_orders_purchase_request_id_index',
        'trx_purchase_orders_number_index',
        'trx_purchase_orders_branch_status_index',
        'trx_purchase_orders_branch_date_index',
        'trx_purchase_orders_branch_supplier_index',
        'trx_purchase_orders_branch_pr_index',
    ] as $indexName) {
        expect($indexes)->toContain($indexName);
    }

    expect(purchaseOrderIndexColumns('trx_purchase_orders_branch_status_index'))->toBe(['branch_id', 'status'])
        ->and(purchaseOrderIndexColumns('trx_purchase_orders_branch_date_index'))->toBe(['branch_id', 'order_date'])
        ->and(purchaseOrderIndexColumns('trx_purchase_orders_branch_supplier_index'))->toBe(['branch_id', 'supplier_id'])
        ->and(purchaseOrderIndexColumns('trx_purchase_orders_branch_pr_index'))->toBe(['branch_id', 'purchase_request_id']);
});

it('creates required indexes on trx_purchase_order_items', function () {
    $indexes = purchaseOrderTableIndexes('trx_purchase_order_items');

    foreach ([
        'trx_po_items_order_id_index',
        'trx_po_items_product_id_index',
        'trx_po_items_location_index',
        'trx_po_items_pr_item_index',
        'trx_po_items_order_product_index',
    ] as $indexName) {
        expect($indexes)->toContain($indexName);
    }

    expect(purchaseOrderIndexColumns('trx_po_items_order_product_index'))->toBe(['purchase_order_id', 'product_id']);
});

it('does not add forbidden stock or total columns to purchase order tables', function () {
    $forbiddenColumns = [
        'current_stock',
        'qty_on_hand',
        'stock_balance',
        'inventory_value',
        'total_amount',
        'subtotal',
        'received_qty',
        'partially_received',
        'fully_received',
        'closed',
        'inventory_movement_id',
    ];

    foreach (['trx_purchase_orders', 'trx_purchase_order_items'] as $table) {
        foreach ($forbiddenColumns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table} must not have {$column}");
        }
    }
});

it('leaves trx_inventory_movements schema unchanged by purchase order migrations', function () {
    expect(Schema::hasTable('trx_inventory_movements'))->toBeTrue();

    $columns = Schema::getColumnListing('trx_inventory_movements');

    foreach ([
        'branch_id',
        'inventory_location_id',
        'product_id',
        'movement_type',
        'quantity_in',
        'quantity_out',
    ] as $column) {
        expect($columns)->toContain($column);
    }
});

it('does not create goods receipt tables in sprint 16.2 schema', function () {
    foreach ([
        'trx_goods_receipts',
        'trx_goods_receipt_items',
        'trx_purchase_receipts',
        'trx_purchase_receipt_items',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('limits sprint 16.2 purchase order status to workflow states without receiving columns', function () {
    expect(Schema::hasColumn('trx_purchase_orders', 'status'))->toBeTrue();

    $status = collect(DB::select('PRAGMA table_info(trx_purchase_orders)'))
        ->firstWhere('name', 'status');

    expect($status)->not->toBeNull()
        ->and($status->dflt_value)->toBe("'draft'");

    $allowedStatuses = [
        'draft',
        'submitted',
        'approved',
        'sent',
        'cancelled',
    ];

    $deferredStatuses = [
        'partially_received',
        'fully_received',
        'closed',
    ];

    expect($allowedStatuses)->toBe([
        'draft',
        'submitted',
        'approved',
        'sent',
        'cancelled',
    ]);

    foreach ($deferredStatuses as $column) {
        expect(Schema::hasColumn('trx_purchase_orders', $column))->toBeFalse();
    }
});
