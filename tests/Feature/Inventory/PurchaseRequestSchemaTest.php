<?php

use Illuminate\Support\Facades\Schema;

it('creates trx_purchase_requests table with required columns', function () {
    expect(Schema::hasTable('trx_purchase_requests'))->toBeTrue();

    $columns = Schema::getColumnListing('trx_purchase_requests');

    foreach ([
        'id',
        'branch_id',
        'purchase_request_number',
        'request_date',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'notes',
        'created_by',
        'created_at',
        'updated_at',
    ] as $column) {
        expect($columns)->toContain($column);
    }
});

it('creates trx_purchase_request_items table with required columns', function () {
    expect(Schema::hasTable('trx_purchase_request_items'))->toBeTrue();

    $columns = Schema::getColumnListing('trx_purchase_request_items');

    foreach ([
        'id',
        'purchase_request_id',
        'product_id',
        'inventory_location_id',
        'quantity_requested',
        'estimated_unit_price',
        'notes',
        'created_at',
        'updated_at',
    ] as $column) {
        expect($columns)->toContain($column);
    }
});

it('does not add mutable stock columns to purchase request tables', function () {
    foreach (['trx_purchase_requests', 'trx_purchase_request_items', 'inv_products'] as $table) {
        expect(Schema::hasColumn($table, 'current_stock'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'stock'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'qty_on_hand'))->toBeFalse();
    }
});

it('leaves trx_inventory_movements schema unchanged by purchase request migrations', function () {
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
