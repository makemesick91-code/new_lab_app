<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-WORKFLOW-V2 (Phase 2) — courier pickup tasks.
 *
 * One pickup task per V2 lab order (UNIQUE lab_order_id = idempotent task
 * creation). Created when the branch submits the order for pickup
 * (WAITING_PICKUP); claimed and driven by the courier; closed when the lab
 * confirms receipt. Additive only — no existing table touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_pickup_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->unique()
                ->constrained('trx_lab_orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('status', 50)->default('PENDING');
            $table->foreignId('courier_id')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('pickup_notes')->nullable();
            $table->text('discrepancy_note')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('courier_id');
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_pickup_tasks');
    }
};
