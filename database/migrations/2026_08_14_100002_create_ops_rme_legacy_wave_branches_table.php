<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-ROLL-4 — one branch enrolled in one migration wave.
 *
 * Enrollment is NOT admission. ROLL-3's config allowlist decides whether a
 * branch may ingest at all; this row decides how that branch is OPERATED inside
 * an already-admitted wave — its own quota, its own pause, its own completion
 * sign-off. A branch enrolled here but absent from the ROLL-3 allowlist ingests
 * nothing, because ROLL-3 runs first and this layer can only narrow.
 *
 * `branch_code` is denormalized beside `branch_id` on purpose. Admission is
 * decided on the RM-DERIVED branch CODE, so keeping the canonical token on the
 * row lets the operations gate compare tokens without re-reading the branch
 * table on every upload — and leaves readable evidence if a branch is later
 * renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_rme_legacy_wave_branches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('wave_id')
                ->constrained('ops_rme_legacy_migration_waves')
                ->cascadeOnUpdate()
                // A wave row is governance evidence; deleting one that still
                // carries enrolled branches would erase the record of what was
                // migrated. Restricted, never cascaded.
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('branch_code', 50)->index();

            $table->string('status', 20)->default('PLANNED')->index();

            // Per-branch override of the wave default. NULL = inherit.
            $table->unsignedInteger('daily_quota')->nullable();

            /*
            | How many documents the operator EXPECTS this branch to hold.
            |
            | Nullable, and it stays nullable. There is no way to derive the
            | size of a paper archive from the database, so a total is either
            | something a human counted or it is unknown. Reporting a fabricated
            | denominator would make every completion percentage a lie.
            */
            $table->unsignedInteger('planned_document_count')->nullable();

            $table->foreignId('completed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_note')->nullable();

            // The reconciliation that justified the sign-off, frozen at the
            // moment it was given. Counts only — no patient identifier.
            $table->json('reconciliation_snapshot')->nullable();

            $table->text('status_reason')->nullable();

            $table->timestamps();

            // One enrollment per branch per wave. The unique key is what makes
            // "enroll twice" a database error rather than a duplicate quota
            // bucket nobody notices.
            $table->unique(['wave_id', 'branch_id'], 'ops_rme_wave_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_rme_legacy_wave_branches');
    }
};
