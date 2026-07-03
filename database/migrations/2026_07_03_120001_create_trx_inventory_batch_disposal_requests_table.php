<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 68.42 — disposal/return/adjustment evidence workflow for batches.
 * Request/approval does not mutate stock; ADJUSTMENT_OUT only on explicit finalization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_inventory_batch_disposal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_batch_id')->constrained('inv_inventory_batches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_batch_action_log_id')->nullable()->constrained('trx_inventory_batch_action_logs')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inv_inventory_locations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('request_type', 50);
            $table->string('status', 50)->default('submitted');
            $table->decimal('quantity_requested', 18, 4);
            $table->decimal('available_quantity_snapshot', 18, 4)->nullable();
            $table->text('evidence_note');
            $table->string('evidence_reference', 255)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('trx_inventory_movements')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'trx_inv_batch_disposal_branch_status_index');
            $table->index(['branch_id', 'inventory_batch_id'], 'trx_inv_batch_disposal_branch_batch_index');
            $table->index(['branch_id', 'inventory_location_id'], 'trx_inv_batch_disposal_branch_loc_index');
            $table->index('inventory_batch_action_log_id', 'trx_inv_batch_disposal_action_log_index');
            $table->index('inventory_movement_id', 'trx_inv_batch_disposal_movement_index');
            $table->index('submitted_by', 'trx_inv_batch_disposal_submitted_by_index');
            $table->index('approved_by', 'trx_inv_batch_disposal_approved_by_index');
            $table->index('finalized_by', 'trx_inv_batch_disposal_finalized_by_index');
            $table->index('created_at', 'trx_inv_batch_disposal_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_inventory_batch_disposal_requests');
    }
};
