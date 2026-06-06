<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.3 — trx_goods_receipt_items (per-product goods receipt lines).
 *
 * Line quantities record receiving intent and cost snapshots. They are not
 * stock balances and must not be treated as a source of truth for inventory stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('trx_goods_receipts')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('trx_purchase_order_items')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inv_inventory_locations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('trx_inventory_movements')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('ordered_qty', 12, 2);
            $table->decimal('previously_received_qty', 12, 2)->default(0);
            $table->decimal('received_qty', 12, 2);
            $table->decimal('accepted_qty', 12, 2);
            $table->decimal('rejected_qty', 12, 2)->default(0);
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('line_total', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('goods_receipt_id', 'trx_gr_items_receipt_id_index');
            $table->index('purchase_order_item_id', 'trx_gr_items_po_item_id_index');
            $table->index('product_id', 'trx_gr_items_product_id_index');
            $table->index('inventory_location_id', 'trx_gr_items_location_index');
            $table->index('inventory_movement_id', 'trx_gr_items_movement_id_index');
            $table->unique('inventory_movement_id', 'trx_gr_items_movement_id_unique');
            $table->index(['goods_receipt_id', 'purchase_order_item_id'], 'trx_gr_items_receipt_po_item_index');
            $table->index(['goods_receipt_id', 'product_id'], 'trx_gr_items_receipt_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_goods_receipt_items');
    }
};
