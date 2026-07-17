<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4C — internal pilot rehearsal run log.
 *
 * One row per rehearsal execution. Append-style history of credential-
 * independent synthetic rehearsals. A rehearsal NEVER performs an external
 * request and its terminal result is honestly PILOT_READY_INTERNAL or
 * BLOCKED_EXTERNAL_CREDENTIAL — never submitted/sent/succeeded_external. Stage
 * results are PII-free structured scalars only.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_satusehat_pilot_rehearsal_runs')) {
            return;
        }

        Schema::create('trx_satusehat_pilot_rehearsal_runs', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnDelete();
            // synthetic (only supported mode this sprint)
            $table->string('mode', 20)->default('synthetic');
            $table->boolean('dry_run')->default(true);
            // PILOT_READY_INTERNAL | BLOCKED_EXTERNAL_CREDENTIAL | failed
            $table->string('result', 40);
            $table->string('final_stage', 80)->nullable();
            // Sanitized per-stage outcomes (scalars only, no PII, no payload).
            $table->json('stage_results')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at'], 'trx_ss_rehearsal_branch_created_idx');
            $table->index(['result'], 'trx_ss_rehearsal_result_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_pilot_rehearsal_runs');
    }
};
