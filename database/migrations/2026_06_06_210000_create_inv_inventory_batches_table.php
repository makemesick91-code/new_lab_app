<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('inv_suppliers')->cascadeOnUpdate()->nullOnDelete();
            $table->string('batch_number', 100)->nullable();
            $table->string('lot_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('branch_id');
            $table->index('product_id');
            $table->index('expiry_date');
            $table->index(['branch_id', 'product_id'], 'inv_inventory_batches_branch_product_index');
            $table->index(['branch_id', 'expiry_date'], 'inv_inventory_batches_branch_expiry_index');
            $table->unique(
                ['branch_id', 'product_id', 'batch_number', 'lot_number'],
                'inv_inventory_batches_branch_product_batch_lot_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_inventory_batches');
    }
};
