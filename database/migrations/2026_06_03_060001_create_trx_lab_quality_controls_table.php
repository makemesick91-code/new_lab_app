<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5 — trx_lab_quality_controls (QC review sessions).
 *
 * Note: `result` is nullable so a review can be started (inspected_by/started_at)
 * and completed later with a result (PASSED/REJECTED/REVISION), per the QC
 * review lifecycle in sprint_5_technical_design.md §10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_quality_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('trx_lab_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('inspected_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('result', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('lab_order_id');
            $table->index('inspected_by');
            $table->index('result');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_quality_controls');
    }
};
