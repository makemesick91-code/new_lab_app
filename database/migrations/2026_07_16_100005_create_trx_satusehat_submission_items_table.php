<?php

// SATUSEHAT-1 — Resource-level submission item scaffold. One row per FHIR
// resource (Encounter/Condition/Procedure) prepared from a candidate, with a
// dependency order and a unique idempotency key. NO external processing this
// sprint — status never reaches "sent". Additive only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_satusehat_submission_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('submission_batch_id')->constrained('trx_satusehat_submission_batches')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('satusehat_candidate_id')->constrained('trx_satusehat_candidates')->cascadeOnUpdate()->restrictOnDelete();

            $table->unsignedInteger('dependency_order')->default(0);
            $table->string('resource_type', 60);               // Encounter | Condition | Procedure
            $table->string('local_source_type', 60)->nullable();
            $table->unsignedBigInteger('local_source_id')->nullable();

            $table->string('source_hash', 64)->nullable();
            $table->string('payload_hash', 64)->nullable();
            // Deterministic, non-sensitive key for future idempotent submission.
            $table->string('idempotency_key', 191)->unique();

            // pending | prepared | blocked (no "sent"/"succeeded"/"failed" here)
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);

            $table->string('remote_resource_id', 191)->nullable();
            // Redacted result/error only — never a raw API/patient payload.
            $table->text('result_summary')->nullable();
            $table->text('error_summary')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('submission_batch_id');
            $table->index('satusehat_candidate_id');
            $table->index(['submission_batch_id', 'dependency_order']);
            $table->index('resource_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_submission_items');
    }
};
