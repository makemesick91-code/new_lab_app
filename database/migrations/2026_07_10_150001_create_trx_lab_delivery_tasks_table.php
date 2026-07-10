<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-WORKFLOW-V2 (Phase 4) — courier delivery tasks (lab -> branch).
 *
 * One delivery task per V2 lab order (UNIQUE lab_order_id = idempotent).
 * Created after MODEL_DONE; claimed and driven by the courier; completion
 * requires the owner-mandated evidence set (pre-transit handover photo +
 * courier signature, then recipient signature + name + location proof photo)
 * enforced server-side by LabDeliveryWorkflowService + the state machine.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_delivery_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->unique()
                ->constrained('trx_lab_orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('status', 50)->default('PENDING');
            $table->foreignId('courier_id')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient_role', 100)->nullable();
            $table->text('delivery_notes')->nullable();
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
        Schema::dropIfExists('trx_lab_delivery_tasks');
    }
};
