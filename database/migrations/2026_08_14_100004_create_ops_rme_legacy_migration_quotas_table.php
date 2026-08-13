<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-ROLL-4 — the daily quota ledger.
 *
 * WHY A COUNTER TABLE INSTEAD OF COUNTING THE IMPORTS.
 *
 * The accepted-document count is derivable: count staging rows for this wave,
 * this branch, today. Deriving it is also unsafe under concurrency, and quota is
 * exactly a concurrency problem. Two operators uploading the last permitted
 * document at the same instant both run `SELECT count(*)`, both read N-1, and
 * both insert — there is no row to lock, because the row that would block the
 * second one is the one neither has written yet.
 *
 * A counter row exists BEFORE the decision, so it can be locked. `SELECT ... FOR
 * UPDATE` on this row serialises the two operators: the second one blocks, then
 * reads the incremented value and is refused. That is the whole reason this
 * table exists, and it is why the increment lives inside the same transaction as
 * the staging row it counts — a rolled-back import takes its quota with it.
 *
 * THE LEDGER CAN STILL BE WRONG, AND THAT IS CHECKED. A counter is a second
 * copy of a derivable fact, so it can drift from reality (a hand-edited row, a
 * future code path that inserts a staging row outside this service).
 * Reconciliation compares `consumed` against the documents actually accepted and
 * reports `quota_drift`; a non-zero drift blocks branch completion. The
 * duplication buys concurrency safety and pays for it with a check, rather than
 * being assumed correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_rme_legacy_migration_quotas', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('wave_id')
                ->constrained('ops_rme_legacy_migration_waves')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
            | The clinical calendar day this bucket counts, resolved through
            | `legacy_rme.dates.clinical_timezone` — the same wall clock the
            | date rules use. A quota that rolled over at UTC midnight would
            | reset in the middle of an Indonesian working morning.
            */
            $table->date('quota_date');

            $table->unsignedInteger('consumed')->default(0);

            $table->timestamps();

            /*
            | The lockable identity. NOT NULL on every component deliberately:
            | PostgreSQL treats NULLs as distinct in a unique index, so a
            | nullable component would silently permit duplicate buckets for the
            | same day — two counters, each below the ceiling, admitting twice
            | the quota.
            */
            $table->unique(['wave_id', 'branch_id', 'quota_date'], 'ops_rme_quota_bucket_unique');

            // The wave-wide daily total is the sum across branches for one day,
            // so that pair is the read path and gets its own index.
            $table->index(['wave_id', 'quota_date'], 'ops_rme_quota_wave_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_rme_legacy_migration_quotas');
    }
};
