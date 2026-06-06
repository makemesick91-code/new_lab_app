<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return array<int, string>
 */
function goodsReceiptTableIndexes(string $table): array
{
    return collect(DB::select('PRAGMA index_list('.DB::getPdo()->quote($table).')'))
        ->pluck('name')
        ->all();
}

/**
 * @return array<int, string>
 */
function goodsReceiptIndexColumns(string $indexName): array
{
    return collect(DB::select('PRAGMA index_info('.DB::getPdo()->quote($indexName).')'))
        ->sortBy('seqno')
        ->pluck('name')
        ->all();
}

it('creates trx_goods_receipts table with required columns', function () {
    expect(Schema::hasTable('trx_goods_receipts'))->toBeTrue();

    $columns = Schema::getColumnListing('trx_goods_receipts');

    foreach ([
        'id',
        'branch_id',
        'purchase_order_id',
        'receipt_number',
        'receipt_date',
        'supplier_delivery_number',
        'supplier_invoice_number',
        'status',
        'notes',
        'submitted_at',
        'posted_at',
        'cancelled_at',
        'created_by',
        'submitted_by',
        'posted_by',
        'cancelled_by',
        'created_at',
        'updated_at',
    ] as $column) {
        expect($columns)->toContain($column);
    }
});

it('creates trx_goods_receipt_items table with required columns', function () {
    expect(Schema::hasTable('trx_goods_receipt_items'))->toBeTrue();

    $columns = Schema::getColumnListing('trx_goods_receipt_items');

    foreach ([
        'id',
        'goods_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'inventory_location_id',
        'inventory_batch_id',
        'batch_number',
        'lot_number',
        'batch_received_date',
        'expiry_date',
        'inventory_movement_id',
        'ordered_qty',
        'previously_received_qty',
        'received_qty',
        'accepted_qty',
        'rejected_qty',
        'unit_cost',
        'line_total',
        'notes',
        'created_at',
        'updated_at',
    ] as $column) {
        expect($columns)->toContain($column);
    }
});

it('adds requires_batch_tracking to inv_products', function () {
    expect(Schema::hasColumn('inv_products', 'requires_batch_tracking'))->toBeTrue();
});

it('adds quantity_received to trx_purchase_order_items', function () {
    expect(Schema::hasColumn('trx_purchase_order_items', 'quantity_received'))->toBeTrue();

    $column = collect(DB::select('PRAGMA table_info(trx_purchase_order_items)'))
        ->firstWhere('name', 'quantity_received');

    expect($column)->not->toBeNull()
        ->and(trim((string) $column->dflt_value, "'"))->toBe('0');
});

it('enforces unique receipt_number on trx_goods_receipts', function () {
    $indexes = goodsReceiptTableIndexes('trx_goods_receipts');

    $uniqueNumberIndexes = collect($indexes)
        ->filter(function (string $indexName): bool {
            $info = DB::select('PRAGMA index_list('.DB::getPdo()->quote('trx_goods_receipts').')');

            return collect($info)->firstWhere('name', $indexName)?->unique === 1
                && goodsReceiptIndexColumns($indexName) === ['receipt_number'];
        })
        ->values()
        ->all();

    expect($uniqueNumberIndexes)->not->toBeEmpty();
});

it('creates required indexes on trx_goods_receipts', function () {
    $indexes = goodsReceiptTableIndexes('trx_goods_receipts');

    foreach ([
        'trx_goods_receipts_branch_id_index',
        'trx_goods_receipts_purchase_order_id_index',
        'trx_goods_receipts_receipt_number_index',
        'trx_goods_receipts_status_index',
        'trx_goods_receipts_receipt_date_index',
        'trx_goods_receipts_posted_at_index',
        'trx_goods_receipts_branch_status_index',
        'trx_goods_receipts_branch_date_index',
        'trx_goods_receipts_branch_po_index',
    ] as $indexName) {
        expect($indexes)->toContain($indexName);
    }

    expect(goodsReceiptIndexColumns('trx_goods_receipts_branch_status_index'))->toBe(['branch_id', 'status'])
        ->and(goodsReceiptIndexColumns('trx_goods_receipts_branch_date_index'))->toBe(['branch_id', 'receipt_date'])
        ->and(goodsReceiptIndexColumns('trx_goods_receipts_branch_po_index'))->toBe(['branch_id', 'purchase_order_id']);
});

it('creates required indexes on trx_goods_receipt_items', function () {
    $indexes = goodsReceiptTableIndexes('trx_goods_receipt_items');

    foreach ([
        'trx_gr_items_receipt_id_index',
        'trx_gr_items_po_item_id_index',
        'trx_gr_items_product_id_index',
        'trx_gr_items_location_index',
        'trx_gr_items_batch_id_index',
        'trx_gr_items_movement_id_index',
        'trx_gr_items_movement_id_unique',
        'trx_gr_items_receipt_po_item_index',
        'trx_gr_items_receipt_product_index',
    ] as $indexName) {
        expect($indexes)->toContain($indexName);
    }

    expect(goodsReceiptIndexColumns('trx_gr_items_receipt_po_item_index'))->toBe(['goods_receipt_id', 'purchase_order_item_id'])
        ->and(goodsReceiptIndexColumns('trx_gr_items_receipt_product_index'))->toBe(['goods_receipt_id', 'product_id']);
});

it('does not add forbidden mutable stock columns to goods receipt tables', function () {
    $forbiddenColumns = [
        'current_stock',
        'qty_on_hand',
        'stock_balance',
        'stock_qty',
        'available_stock',
        'quantity_in',
        'quantity_out',
    ];

    foreach (['trx_goods_receipts', 'trx_goods_receipt_items'] as $table) {
        foreach ($forbiddenColumns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table} must not have {$column}");
        }
    }
});

it('defaults goods receipt status to draft', function () {
    $status = collect(DB::select('PRAGMA table_info(trx_goods_receipts)'))
        ->firstWhere('name', 'status');

    expect($status)->not->toBeNull()
        ->and($status->dflt_value)->toBe("'draft'");
});

it('does not create inventory movements from goods receipt schema migrations alone', function () {
    expect(Schema::hasTable('trx_inventory_movements'))->toBeTrue();

    $columns = Schema::getColumnListing('trx_inventory_movements');

    foreach ([
        'goods_receipt_id',
        'goods_receipt_item_id',
        'goods_receipt_number',
    ] as $column) {
        expect($columns)->not->toContain($column);
    }
});
