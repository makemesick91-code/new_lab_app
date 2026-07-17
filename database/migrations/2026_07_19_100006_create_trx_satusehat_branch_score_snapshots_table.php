<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — append-only readiness score history.
 *
 * Each recalculation captures a reproducible snapshot: score, score version,
 * threshold version, component breakdown, and whether a hard blocker capped the
 * score. Append-only (no updated_at) so historical scores stay reproducible and
 * score-weight/threshold changes are auditable over time. Derived numbers +
 * foreign keys only — no PII.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_satusehat_branch_score_snapshots')) {
            return;
        }

        Schema::create('trx_satusehat_branch_score_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnDelete();
            $table->foreignId('rollout_wave_id')->nullable()->constrained('mst_satusehat_rollout_waves')->nullOnDelete();

            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedInteger('score_version')->nullable();
            $table->unsignedInteger('threshold_version')->nullable();
            $table->unsignedInteger('open_hard_issues')->default(0);
            $table->unsignedInteger('open_soft_issues')->default(0);
            $table->boolean('has_hard_blocker')->default(false);
            $table->json('component_breakdown')->nullable();
            $table->string('readiness_stage', 40)->nullable();

            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['environment', 'branch_id', 'created_at'], 'trx_ss_score_env_branch_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_branch_score_snapshots');
    }
};
