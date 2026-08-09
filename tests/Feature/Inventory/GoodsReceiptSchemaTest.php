<?php

use Illuminate\Support\Facades\Schema;
use Tests\Support\Database\SchemaFacts;

/**
 * @return array<int, string>
 */
function goodsReceiptTableIndexes(string $table): array
{
    return SchemaFacts::indexNames($table);
}

/**
 * @return array<int, string>
 */
function goodsReceiptIndexColumns(string $table, string $indexName): array
{
    // PRAGMA index_info() looked indexes up globally; the portable API needs the
    // owning table, so every call site names it explicitly.
    return SchemaFacts::indexColumns($table, $indexName);
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
        'cancellation_reason',
        'submitted_at',
        'posted_at',
        'cancelled_at',
        'voided_at',
        'created_by',
        'submitted_by',
        'posted_by',
        'cancelled_by',
        'voided_by',
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
        'reversal_movement_id',
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

    $default = SchemaFacts::columnDefault('trx_purchase_order_items', 'quantity_received');

    // The contract is "defaults to zero". SQLite spells that `0` and PostgreSQL
    // may spell it `0` or `0.0000`, so compare the value, not the spelling.
    expect($default)->not->toBeNull()
        ->and((float) $default)->toBe(0.0);
});

it('enforces unique receipt_number on trx_goods_receipts', function () {
    // Same contract, driver-independent: a unique index covering exactly
    // [receipt_number]. SQLite and PostgreSQL name such indexes differently, so
    // the assertion is on the index's shape rather than on its name.
    expect(SchemaFacts::hasUniqueIndexOn('trx_goods_receipts', ['receipt_number']))->toBeTrue();
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

    expect(goodsReceiptIndexColumns('trx_goods_receipts', 'trx_goods_receipts_branch_status_index'))->toBe(['branch_id', 'status'])
        ->and(goodsReceiptIndexColumns('trx_goods_receipts', 'trx_goods_receipts_branch_date_index'))->toBe(['branch_id', 'receipt_date'])
        ->and(goodsReceiptIndexColumns('trx_goods_receipts', 'trx_goods_receipts_branch_po_index'))->toBe(['branch_id', 'purchase_order_id']);
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

    expect(goodsReceiptIndexColumns('trx_goods_receipt_items', 'trx_gr_items_receipt_po_item_index'))->toBe(['goods_receipt_id', 'purchase_order_item_id'])
        ->and(goodsReceiptIndexColumns('trx_goods_receipt_items', 'trx_gr_items_receipt_product_index'))->toBe(['goods_receipt_id', 'product_id']);
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
    // PostgreSQL reports this as `'draft'::character varying`, SQLite as
    // `'draft'`; both mean the column defaults to draft.
    expect(SchemaFacts::columnDefault('trx_goods_receipts', 'status'))->toBe('draft');
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
