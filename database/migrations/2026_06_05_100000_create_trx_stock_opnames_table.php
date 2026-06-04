<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 13 — trx_stock_opnames (physical stock-count document header).
 *
 * Branch- and location-aware header for a stock opname (physical count).
 * The opname records a count event; actual stock stays ledger-derived from
 * trx_inventory_movements. Posting an opname's variance creates adjustment
 * movements (service layer) — this table never holds a running stock balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inv_inventory_locations')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('opname_number', 50);
            $table->date('opname_date');
            $table->string('status', 30)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('branch_id');
            $table->index('inventory_location_id');
            $table->index('status');
            $table->index('opname_date');
            $table->index(['branch_id', 'status'], 'trx_stock_opnames_branch_status_index');
            $table->index(['branch_id', 'inventory_location_id'], 'trx_stock_opnames_branch_location_index');
            $table->unique(['branch_id', 'opname_number'], 'trx_stock_opnames_branch_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_stock_opnames');
    }
};
