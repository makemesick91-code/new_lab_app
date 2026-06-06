<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 15.3 Step 3 — nullable batch reference on stock transfer lines.
 *
 * Line quantities remain document quantities only; batch identity propagates
 * to TRANSFER_OUT / TRANSFER_IN ledger movements at ship/receive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_stock_transfer_items', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')
                ->nullable()
                ->after('product_id')
                ->constrained('inv_inventory_batches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index('inventory_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('trx_stock_transfer_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_batch_id');
        });
    }
};
