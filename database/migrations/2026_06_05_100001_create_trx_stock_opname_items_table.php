<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 13 — trx_stock_opname_items (per-product lines of a stock opname).
 *
 * Each line is an immutable snapshot of a single product's count:
 *  - system_quantity:  ledger-derived balance captured at count time,
 *  - counted_quantity: the physically counted amount,
 *  - variance_quantity: counted − system (recorded for reporting),
 *  - unit_cost: cost snapshot used to value the variance.
 * These are historical count records, NOT a mutable stock balance on products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('trx_stock_opnames')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('system_quantity', 12, 2)->default(0);
            $table->decimal('counted_quantity', 12, 2)->default(0);
            $table->decimal('variance_quantity', 12, 2)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('stock_opname_id');
            $table->index('product_id');
            $table->unique(['stock_opname_id', 'product_id'], 'trx_stock_opname_items_opname_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_stock_opname_items');
    }
};
