<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 14.2 - trx_stock_transfer_items (per-product transfer lines).
 *
 * Line quantities are requested transfer quantities. They are not stock
 * balances and must not be treated as a source of truth for inventory stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('trx_stock_transfers')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('stock_transfer_id');
            $table->index('product_id');
            $table->index(['stock_transfer_id', 'product_id'], 'trx_stock_transfer_items_transfer_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_stock_transfer_items');
    }
};
