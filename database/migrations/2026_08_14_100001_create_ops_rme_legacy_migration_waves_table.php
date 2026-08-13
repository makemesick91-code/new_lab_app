<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-ROLL-4 — the operational record of one migration wave.
 *
 * WHY A TABLE AT ALL, WHEN ROLL-3'S WAVE IS CONFIG.
 *
 * ROLL-3's wave lives in `config/legacy_rme_rollout.php`: a label, an approval
 * reference and the exact branch set that approval covers. That is deliberately
 * deploy-time state — changing who may migrate requires a server change, which
 * is precisely the property that makes admission trustworthy. None of it moves
 * here.
 *
 * What cannot live in config is everything that CHANGES DURING a wave: which
 * operators are assigned, how much has been consumed today, whether the wave is
 * paused right now, whether a branch has been signed off. Those are
 * transactional facts, they need locking, and they need an audit trail.
 *
 * So this row does NOT authorize a wave. It MIRRORS the config approval and is
 * refused at runtime when the two disagree (WAVE_BINDING_MISMATCH). The mirror
 * is the point: it catches the deployment where someone edited the environment
 * and not the governance record, or the reverse — the same class of drift
 * ROLL-3 caught one level down by binding the approval to its branch scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_rme_legacy_migration_waves', function (Blueprint $table): void {
            $table->id();

            // The canonical wave token, e.g. `WAVE-1`. Compared against
            // `legacy_rme_rollout.admission.wave` as an exact upper-case token,
            // so it is unique and normalized before it is stored.
            $table->string('code', 50)->unique();
            $table->string('name', 150);

            $table->string('status', 20)->default('DRAFT')->index();

            /*
            | The approval this wave MIRRORS. Both are compared against config
            | at ingestion time; neither is an authorization by itself.
            |
            | `approved_branch_codes` is the exact set, stored as JSON because
            | it is read as a whole set and never queried by element.
            */
            $table->string('approval_reference', 190)->nullable();
            $table->json('approved_branch_codes')->nullable();

            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();

            /*
            | Quota ceilings. NULL means "no ceiling declared at this level" and
            | is deliberately different from 0, which admits nothing.
            */
            $table->unsignedInteger('daily_quota')->nullable();
            $table->unsignedInteger('per_branch_daily_quota')->nullable();

            // Governance actors and their reasons. `created_by` is kept so the
            // approver-is-not-creator rule has something to compare against.
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('activated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('activated_at')->nullable();

            $table->foreignId('paused_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->text('pause_reason')->nullable();

            $table->foreignId('completed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_rme_legacy_migration_waves');
    }
};
