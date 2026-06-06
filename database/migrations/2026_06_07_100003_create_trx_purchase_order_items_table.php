<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.2 — trx_purchase_order_items (per-product purchase order lines).
 *
 * Line quantities are ordered purchase quantities. They are not stock
 * balances and must not be treated as a source of truth for inventory stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('trx_purchase_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inv_inventory_locations')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('purchase_request_item_id')->nullable()->constrained('trx_purchase_request_items')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('quantity_ordered', 12, 2);
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id', 'trx_po_items_order_id_index');
            $table->index('product_id', 'trx_po_items_product_id_index');
            $table->index('inventory_location_id', 'trx_po_items_location_index');
            $table->index('purchase_request_item_id', 'trx_po_items_pr_item_index');
            $table->index(['purchase_order_id', 'product_id'], 'trx_po_items_order_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_purchase_order_items');
    }
};
