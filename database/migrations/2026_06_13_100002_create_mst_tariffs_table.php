<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19 Phase 3 — mst_tariffs. Branch-scoped pricing for global treatments.
 * Tariffs belong to a branch and a global treatment; resolved via BranchContext.
 * The canonical record is (branch_id, treatment_id, effective_date) → price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('treatment_id')->constrained('mst_treatments')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('price', 18, 2)->default(0);
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index(['branch_id', 'treatment_id'], 'mst_tariffs_branch_treatment_index');
            $table->index(['branch_id', 'is_active'], 'mst_tariffs_branch_active_index');
            $table->unique(['branch_id', 'treatment_id', 'effective_date'], 'mst_tariffs_branch_treatment_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_tariffs');
    }
};
