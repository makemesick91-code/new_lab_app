<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 16.4 Step 2 — widen inventory/procurement quantity columns to decimal(18,4)
 * so fractional units (e.g. 0.5, 1.25, 2.7500) are stored without truncation.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $columnsByTable = [
        'trx_inventory_movements' => [
            'quantity_in',
            'quantity_out',
        ],
        'trx_purchase_request_items' => [
            'quantity_requested',
        ],
        'trx_purchase_order_items' => [
            'quantity_ordered',
            'quantity_received',
        ],
        'trx_goods_receipt_items' => [
            'ordered_qty',
            'previously_received_qty',
            'received_qty',
            'accepted_qty',
            'rejected_qty',
        ],
        'trx_stock_transfer_items' => [
            'quantity',
        ],
        'trx_stock_opname_items' => [
            'system_quantity',
            'counted_quantity',
            'variance_quantity',
        ],
        'inv_products' => [
            'minimum_stock',
            'reorder_point',
            'reorder_quantity',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columnsByTable as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s TYPE NUMERIC(18,4)',
                    $table,
                    $column,
                ));
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columnsByTable as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s TYPE NUMERIC(12,2)',
                    $table,
                    $column,
                ));
            }
        }
    }
};
