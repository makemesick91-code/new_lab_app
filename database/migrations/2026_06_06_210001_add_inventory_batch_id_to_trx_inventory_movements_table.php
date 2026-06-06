<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_inventory_movements', function (Blueprint $table) {
            $table->foreignId('inventory_batch_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('inv_inventory_batches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index('inventory_batch_id');
            $table->index(
                ['branch_id', 'inventory_location_id', 'inventory_batch_id'],
                'trx_inventory_movements_branch_location_batch_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('trx_inventory_movements', function (Blueprint $table) {
            $table->dropIndex('trx_inventory_movements_branch_location_batch_index');
            $table->dropConstrainedForeignId('inventory_batch_id');
        });
    }
};
