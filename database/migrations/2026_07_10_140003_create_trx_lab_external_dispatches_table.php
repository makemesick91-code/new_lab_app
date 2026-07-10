<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — external lab dispatches.
 *
 * One row per physical send-out to an external lab. Rejected results create a
 * NEW dispatch on resend (history preserved, rows never overwritten with a
 * different vendor/round). Tracking is deliberately manual — no external API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_external_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')
                ->constrained('trx_lab_orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('external_lab_id')
                ->constrained('mst_external_labs')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('status', 30)->default('PREPARATION');
            $table->text('reason')->nullable();
            $table->date('expected_return_date')->nullable();
            $table->string('shipping_method', 100)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('review_result', 20)->nullable(); // ACCEPTED | REJECTED
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->index(['lab_order_id', 'status']);
            $table->index('external_lab_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_external_dispatches');
    }
};
