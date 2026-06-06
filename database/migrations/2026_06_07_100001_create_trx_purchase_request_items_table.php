<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.1 — trx_purchase_request_items (per-product purchase request lines).
 *
 * Line quantities are requested purchase quantities. They are not stock
 * balances and must not be treated as a source of truth for inventory stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('trx_purchase_requests')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inv_inventory_locations')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('quantity_requested', 12, 2);
            $table->decimal('estimated_unit_price', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_request_id');
            $table->index('product_id');
            $table->index('inventory_location_id', 'trx_pr_items_location_index');
            $table->index(['purchase_request_id', 'product_id'], 'trx_pr_items_request_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_purchase_request_items');
    }
};
