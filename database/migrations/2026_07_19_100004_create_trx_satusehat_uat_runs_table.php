<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — human operator UAT runs, scenario results, and sign-offs.
 *
 * Records real human operator UAT: one run per wave (or ad-hoc), many scenario
 * results, and role-based sign-offs. Evidence is synthetic / PII-safe only —
 * NIK, raw clinical notes, and real patient identities are never stored here.
 * A run reaching signed_off is the mandatory precondition for an operational
 * GO decision; automated tests never substitute for these rows.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trx_satusehat_uat_runs')) {
            Schema::create('trx_satusehat_uat_runs', function (Blueprint $table) {
                $table->id();
                $table->string('environment', 20)->default('sandbox');
                $table->foreignId('rollout_wave_id')->nullable()->constrained('mst_satusehat_rollout_waves')->nullOnDelete();
                $table->string('title', 200);
                // draft | in_progress | completed | signed_off | rejected
                $table->string('status', 30)->default('draft');
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['environment', 'status'], 'trx_ss_uat_env_status_idx');
                $table->index(['environment', 'rollout_wave_id'], 'trx_ss_uat_env_wave_idx');
            });
        }

        if (! Schema::hasTable('trx_satusehat_uat_scenarios')) {
            Schema::create('trx_satusehat_uat_scenarios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('uat_run_id')->constrained('trx_satusehat_uat_runs')->cascadeOnDelete();
                $table->string('scenario_code', 60);
                $table->string('role', 60);
                $table->foreignId('branch_id')->nullable()->constrained('mst_branches')->nullOnDelete();
                $table->string('precondition', 1000)->nullable();
                $table->string('steps', 2000)->nullable();
                $table->string('expected_result', 1000)->nullable();
                $table->string('actual_result', 1000)->nullable();
                // pass | fail | blocked | pending
                $table->string('outcome', 20)->default('pending');
                // none | low | medium | high | critical
                $table->string('finding_severity', 20)->nullable();
                $table->string('evidence_reference', 500)->nullable();
                $table->string('operator_name', 150)->nullable();
                $table->string('operator_role', 60)->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                $table->index(['uat_run_id', 'outcome'], 'trx_ss_uatsc_run_outcome_idx');
                $table->index(['uat_run_id', 'role'], 'trx_ss_uatsc_run_role_idx');
            });
        }

        if (! Schema::hasTable('trx_satusehat_uat_signoffs')) {
            Schema::create('trx_satusehat_uat_signoffs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('uat_run_id')->constrained('trx_satusehat_uat_runs')->cascadeOnDelete();
                $table->string('role', 60);
                $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('operator_name', 150);
                $table->string('operator_role', 60);
                // approved | rejected
                $table->string('decision', 20);
                $table->string('notes', 500)->nullable();
                $table->timestamp('signed_at');
                $table->timestamps();

                $table->index(['uat_run_id', 'decision'], 'trx_ss_uatso_run_decision_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_uat_signoffs');
        Schema::dropIfExists('trx_satusehat_uat_scenarios');
        Schema::dropIfExists('trx_satusehat_uat_runs');
    }
};
