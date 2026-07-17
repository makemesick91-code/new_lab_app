<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4B — branch-scoped structured diagnosis rollout mode.
 *
 * One row per branch that has been EXPLICITLY configured. Branches without a
 * row fall back to the safe config default (informational). There is
 * deliberately NO global/enforced-everywhere setting: enforcement can only be
 * turned on branch-by-branch, with a reason, by an authorized user
 * (configure_diagnosis_rollout), and every change is audited.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mst_diagnosis_rollout_settings')) {
            return;
        }

        Schema::create('mst_diagnosis_rollout_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnDelete();
            // disabled|informational|warning|pilot_enforced
            $table->string('mode', 30)->index();
            $table->string('reason', 500);
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('branch_id', 'mst_dx_rollout_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_diagnosis_rollout_settings');
    }
};
