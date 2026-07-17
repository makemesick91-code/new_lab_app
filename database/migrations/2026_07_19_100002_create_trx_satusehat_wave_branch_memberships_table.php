<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — wave ↔ branch enrollment.
 *
 * A branch may belong to only ONE active (non-removed) wave at a time. That
 * invariant is enforced by the service layer AND a partial unique index (see
 * the companion single-active-membership migration). Enrollment/removal is
 * always explicit and audited; only RME-enabled branches are enrollable and
 * MAIN is never enrollable (enforced in the service layer).
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_satusehat_wave_branch_memberships')) {
            return;
        }

        Schema::create('trx_satusehat_wave_branch_memberships', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->foreignId('rollout_wave_id')->constrained('mst_satusehat_rollout_waves')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnDelete();

            // enrolled | removed
            $table->string('status', 30)->default('enrolled');

            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['environment', 'rollout_wave_id'], 'trx_ss_wm_env_wave_idx');
            $table->index(['environment', 'branch_id'], 'trx_ss_wm_env_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_wave_branch_memberships');
    }
};
