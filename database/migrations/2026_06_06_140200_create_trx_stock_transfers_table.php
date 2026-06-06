<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 14.2 - trx_stock_transfers (inter-location transfer document header).
 *
 * This table identifies a transfer workflow only. It does not store stock
 * balances; completed transfers must be posted to trx_inventory_movements by
 * the service layer in a later workflow slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('transfer_number', 50);
            $table->foreignId('source_inventory_location_id')->constrained('inv_inventory_locations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('destination_inventory_location_id')->constrained('inv_inventory_locations')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('transfer_date');
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->index('branch_id');
            $table->index('source_inventory_location_id', 'trx_stock_transfers_source_location_index');
            $table->index('destination_inventory_location_id', 'trx_stock_transfers_destination_location_index');
            $table->index('status');
            $table->index('transfer_date');
            $table->index(['branch_id', 'status'], 'trx_stock_transfers_branch_status_index');
            $table->index(['branch_id', 'transfer_date'], 'trx_stock_transfers_branch_date_index');
            $table->unique(['branch_id', 'transfer_number'], 'trx_stock_transfers_branch_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_stock_transfers');
    }
};
