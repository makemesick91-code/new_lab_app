<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 17.8 Step 1 — per-room (inventory location) minimum/maximum stock configuration.
 *
 * This table stores threshold CONFIGURATION ONLY. It is not a stock balance table.
 * Current stock remains ledger-derived from trx_inventory_movements:
 *   current_stock = SUM(quantity_in) - SUM(quantity_out)
 *
 * No mutable stock / current_stock / qty_on_hand / balance columns are introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_location_product_minimums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inv_inventory_locations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('minimum_stock', 18, 4)->default(0);
            $table->decimal('maximum_stock', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('branch_id');
            $table->index('inventory_location_id');
            $table->index('product_id');
            $table->index(['branch_id', 'is_active'], 'inv_location_product_minimums_branch_active_index');

            // One threshold row per product per room. Locations are strictly branch-owned,
            // and the project convention is explicit branch-composite uniqueness
            // (see inv_products, inv_inventory_batches). This unique key also serves as the
            // composite (branch_id, inventory_location_id, product_id) lookup index.
            $table->unique(
                ['branch_id', 'inventory_location_id', 'product_id'],
                'inv_location_product_minimums_branch_location_product_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_location_product_minimums');
    }
};
