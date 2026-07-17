<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — append-only branch readiness transition history.
 *
 * Records every controlled promotion / demotion / suspension / resume of a
 * branch's readiness stage, with reason and actor. Append-only. External send
 * / production are never a transition target here. Derived + FK only, no PII.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_satusehat_branch_transitions')) {
            return;
        }

        Schema::create('trx_satusehat_branch_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnDelete();
            $table->foreignId('rollout_wave_id')->nullable()->constrained('mst_satusehat_rollout_waves')->nullOnDelete();

            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 40);
            // promotion | demotion | suspension | resume
            $table->string('transition_type', 30);
            $table->string('reason', 500);
            $table->json('gate_snapshot')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['environment', 'branch_id', 'created_at'], 'trx_ss_tr_env_branch_at_idx');
            $table->index(['environment', 'transition_type'], 'trx_ss_tr_env_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_branch_transitions');
    }
};
