<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5 — trx_lab_qc_checklists (item-level QC checklist results).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_qc_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_control_id')->constrained('trx_lab_quality_controls')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('checklist_item', 100);
            $table->string('result', 50);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('quality_control_id');
            $table->index('checklist_item');
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_qc_checklists');
    }
};
