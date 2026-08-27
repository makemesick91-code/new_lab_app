<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1 — the cross-capability daily quota ledger.
 *
 * WHY THIS EXISTS WHEN `ops_rme_legacy_migration_quotas` ALREADY DOES.
 *
 * That table counts (wave, branch, day). Its wave dimension is the point of it:
 * a wave-wide ceiling across every branch enrolled is a control the hub ceiling
 * cannot express, and it is not being replaced. But it also means the RME
 * counter cannot answer "how many RME documents has this branch taken today",
 * because two concurrent waves would give the branch two independent buckets.
 * And it has no vocabulary at all for the other two importers.
 *
 * So this table counts (import_type, branch, day) — one bucket per capability
 * per branch per clinical day, which is exactly the contract in
 * `config/legacy_import_hub.php`. For Legacy RME both buckets are taken, in a
 * fixed order, and either may refuse.
 *
 * WHY A COUNTER TABLE INSTEAD OF COUNTING THE RECORDS. The same reason ROLL-4
 * gives, and it has not stopped being true: the accepted-record count is
 * derivable, and deriving it is unsafe under concurrency, which is the only
 * condition a quota has to survive. Two operators taking the last permitted slot
 * at the same instant both run `SELECT count(*)`, both read N-1, and both
 * insert — there is no row to lock, because the row that would block the second
 * one is the one neither has written yet. A counter row exists BEFORE the
 * decision, so `SELECT ... FOR UPDATE` can serialise them: the second blocks,
 * then reads the incremented value and is refused.
 *
 * The increment lives inside the same transaction as the record it counts, so a
 * rolled-back import releases its slot with no compensating write.
 *
 * ADDITIVE ONLY. One new table. No existing column is altered or dropped, no
 * data is backfilled, and nothing outside this file changes shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_legacy_import_daily_quotas', function (Blueprint $table): void {
            $table->id();

            /*
            | One of the three canonical types in
            | `LegacyImportType::all()`. A string rather than an enum column so
            | the vocabulary lives in one place in PHP; the service refuses an
            | unknown type before it can ever reach the database.
            */
            $table->string('import_type', 32);

            $table->foreignId('branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
            | The clinical calendar day this bucket counts, resolved through
            | ClinicalClock — the same wall clock the legacy date rules and the
            | ROLL-4 quota use. A quota that rolled over at UTC midnight would
            | reset at 08:00 WITA, in the middle of a working morning.
            */
            $table->date('quota_date');

            $table->unsignedInteger('consumed')->default(0);

            $table->timestamps();

            /*
            | The lockable identity. Every component is NOT NULL deliberately:
            | PostgreSQL treats NULLs as distinct in a unique index, so a
            | nullable component would silently permit two buckets for the same
            | day — two counters, each below the ceiling, admitting twice the
            | quota.
            */
            $table->unique(['import_type', 'branch_id', 'quota_date'], 'ops_legacy_import_quota_bucket_unique');

            // The hub page reads every type for one branch on one day, and the
            // per-type total across branches for one day.
            $table->index(['quota_date', 'import_type'], 'ops_legacy_import_quota_day_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_legacy_import_daily_quotas');
    }
};
