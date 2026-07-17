<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4B — emergency override of the pilot-enforced structured diagnosis
 * requirement. Append-only by design (no update/delete endpoint exists): each
 * row records who bypassed the requirement for which medical record, why, and
 * until when. An override NEVER makes SATUSEHAT readiness "ready" — the
 * missing-diagnosis data-quality issue stays open for clinical review.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_diagnosis_requirement_overrides')) {
            return;
        }

        Schema::create('trx_diagnosis_requirement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('trx_medical_records')->cascadeOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['medical_record_id', 'expires_at'], 'trx_dx_override_record_expiry_idx');
            $table->index(['branch_id', 'created_at'], 'trx_dx_override_branch_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_diagnosis_requirement_overrides');
    }
};
