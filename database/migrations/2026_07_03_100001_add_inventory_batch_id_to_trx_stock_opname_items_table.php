<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 68.39 — optional batch dimension on stock opname count lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_stock_opname_items', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')
                ->nullable()
                ->after('product_id')
                ->constrained('inv_inventory_batches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index('inventory_batch_id');
        });

        Schema::table('trx_stock_opname_items', function (Blueprint $table) {
            $table->dropUnique('trx_stock_opname_items_opname_product_unique');
        });

        DB::statement('CREATE UNIQUE INDEX trx_stock_opname_items_opname_product_null_batch_unique ON trx_stock_opname_items (stock_opname_id, product_id) WHERE inventory_batch_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX trx_stock_opname_items_opname_product_batch_unique ON trx_stock_opname_items (stock_opname_id, product_id, inventory_batch_id) WHERE inventory_batch_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS trx_stock_opname_items_opname_product_batch_unique');
        DB::statement('DROP INDEX IF EXISTS trx_stock_opname_items_opname_product_null_batch_unique');

        Schema::table('trx_stock_opname_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_batch_id');
            $table->unique(['stock_opname_id', 'product_id'], 'trx_stock_opname_items_opname_product_unique');
        });
    }
};
