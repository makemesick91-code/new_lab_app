<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4C — per-branch readiness profile + internal pilot state.
 *
 * One row per (environment, branch). Holds the cached, recomputable readiness
 * score/rates, the pilot status/stage, reasoned+audited threshold overrides,
 * approval, and reversible suspension. No PII — foreign keys + derived numbers
 * only. Pilot state is INTERNAL readiness; external readiness is never stored
 * here (it stays a separate credential blocker). No branch is a pilot by
 * default; MAIN is never eligible (enforced in the service layer).
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mst_satusehat_branch_pilot_profiles')) {
            return;
        }

        Schema::create('mst_satusehat_branch_pilot_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnDelete();

            // none | candidate | approved | suspended
            $table->string('pilot_status', 20)->default('none');
            // not_started | profiling | remediation | uat_ready |
            // pilot_ready_internal | blocked_external_credential | suspended
            $table->string('readiness_stage', 40)->default('not_started');

            // Cached, recomputable readiness snapshot.
            $table->unsignedTinyInteger('internal_readiness_score')->nullable();
            $table->unsignedInteger('score_version')->nullable();
            $table->unsignedInteger('open_hard_issues')->default(0);
            $table->unsignedInteger('open_soft_issues')->default(0);
            $table->decimal('diagnosis_adoption_rate', 5, 2)->nullable();
            $table->decimal('treatment_mapping_rate', 5, 2)->nullable();
            $table->decimal('dental_readiness_rate', 5, 2)->nullable();
            $table->decimal('patient_data_readiness_rate', 5, 2)->nullable();
            $table->decimal('practitioner_readiness_rate', 5, 2)->nullable();
            $table->decimal('location_readiness_rate', 5, 2)->nullable();
            $table->decimal('local_conformance_rate', 5, 2)->nullable();

            // Reasoned, audited threshold overrides (null → config default).
            $table->json('threshold_overrides')->nullable();
            $table->unsignedInteger('threshold_version')->nullable();

            $table->timestamp('last_recalculated_at')->nullable();
            $table->timestamp('last_rehearsal_at')->nullable();
            $table->string('last_rehearsal_result', 40)->nullable();

            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();

            $table->timestamps();

            $table->unique(['environment', 'branch_id'], 'mst_ss_pilot_env_branch_uq');
            $table->index(['pilot_status'], 'mst_ss_pilot_status_idx');
            $table->index(['readiness_stage'], 'mst_ss_pilot_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_satusehat_branch_pilot_profiles');
    }
};
