<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.4.1 — persist batch/lot input on GR lines until posting links inventory_batch_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_goods_receipt_items', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')
                ->nullable()
                ->after('inventory_location_id')
                ->constrained('inv_inventory_batches')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->string('batch_number', 100)->nullable()->after('inventory_batch_id');
            $table->string('lot_number', 100)->nullable()->after('batch_number');
            $table->date('batch_received_date')->nullable()->after('lot_number');
            $table->date('expiry_date')->nullable()->after('batch_received_date');

            $table->index('inventory_batch_id', 'trx_gr_items_batch_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('trx_goods_receipt_items', function (Blueprint $table) {
            $table->dropIndex('trx_gr_items_batch_id_index');
            $table->dropConstrainedForeignId('inventory_batch_id');
            $table->dropColumn(['batch_number', 'lot_number', 'batch_received_date', 'expiry_date']);
        });
    }
};
