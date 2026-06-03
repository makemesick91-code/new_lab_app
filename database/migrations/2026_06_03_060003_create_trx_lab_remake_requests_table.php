<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5 — trx_lab_remake_requests (remake requests from failed QC reviews).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_remake_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('trx_lab_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('quality_control_id')->constrained('trx_lab_quality_controls')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('reason', 100);
            $table->text('notes')->nullable();
            $table->string('status', 50)->default('OPEN');
            $table->timestamp('requested_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index('lab_order_id');
            $table->index('quality_control_id');
            $table->index('requested_by');
            $table->index('status');
            $table->index('requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_remake_requests');
    }
};
