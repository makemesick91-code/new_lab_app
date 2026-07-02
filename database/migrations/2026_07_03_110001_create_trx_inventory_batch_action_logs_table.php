<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 68.41 — operational action log for near-expiry/expired batches.
 * Audit-only; does not mutate stock or create ledger movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_inventory_batch_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('inventory_batch_id')->constrained('inv_inventory_batches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('action_type', 50);
            $table->text('note')->nullable();
            $table->decimal('ledger_quantity_snapshot', 18, 4)->nullable();
            $table->foreignId('acted_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['branch_id', 'inventory_batch_id'], 'trx_inv_batch_action_logs_branch_batch_index');
            $table->index(['branch_id', 'action_type'], 'trx_inv_batch_action_logs_branch_type_index');
            $table->index('acted_by', 'trx_inv_batch_action_logs_acted_by_index');
            $table->index('acted_at', 'trx_inv_batch_action_logs_acted_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_inventory_batch_action_logs');
    }
};
