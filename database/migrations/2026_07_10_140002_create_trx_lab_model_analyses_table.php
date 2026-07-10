<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — model analysis decisions (append-only).
 *
 * One row per analysis decision (internal vs external). An order may collect
 * several rows over its life (e.g. external result rejected → re-analysis);
 * rows are never updated or deleted by the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_model_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')
                ->constrained('trx_lab_orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('decision', 20); // INTERNAL | EXTERNAL
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->foreignId('external_lab_id')->nullable()
                ->constrained('mst_external_labs')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('analyzed_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('analyzed_at');
            $table->timestamps();

            $table->index(['lab_order_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_model_analyses');
    }
};
