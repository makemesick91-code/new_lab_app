<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-ROLL-4 — who may migrate which branch, in which wave.
 *
 * THE GAP THIS CLOSES. After ROLL-3, holding `create_legacy_rme_imports` let a
 * user upload for EVERY admitted branch. In a single-branch pilot that is the
 * same thing; across a five-branch wave it means any intake operator can file
 * documents into a clinic they have nothing to do with. The permission answers
 * "may this person migrate?" — it cannot answer "may this person migrate THIS
 * branch?", and that is the question a multi-branch wave asks.
 *
 * An assignment is a NARROWING, never a grant. It cannot give someone the
 * permission they lack, cannot admit a branch ROLL-3 refused, and cannot widen
 * the RM-derived branch. Holding an assignment plus the permission plus an
 * admitted branch is the whole requirement, and all three are checked.
 *
 * REVOCATION IS SOFT. `revoked_at` is set rather than the row deleted: who was
 * allowed to touch a clinical archive, and when that stopped, is exactly the
 * kind of fact an audit needs to be able to reconstruct afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_rme_legacy_wave_operators', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('wave_id')
                ->constrained('ops_rme_legacy_migration_waves')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('branch_code', 50)->index();

            $table->foreignId('assigned_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->foreignId('revoked_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            /*
            | One assignment row per (wave, user, branch). Re-assigning after a
            | revocation reactivates THIS row rather than inserting a second —
            | so the unique key can stay simple, and the active/revoked state is
            | a single readable fact instead of "the newest of several rows".
            */
            $table->unique(['wave_id', 'user_id', 'branch_id'], 'ops_rme_wave_operator_unique');

            // The hot path: "is this actor assigned to this branch right now?"
            $table->index(['wave_id', 'user_id', 'revoked_at'], 'ops_rme_wave_operator_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_rme_legacy_wave_operators');
    }
};
