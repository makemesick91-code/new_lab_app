<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 68.1 — Write bottleneck fix (visit-create throughput).
 *
 * `ClinicVisitService::generateUniqueVisitNumber()` runs, on EVERY visit create,
 * a `SELECT visit_number ... WHERE visit_number LIKE 'VIS-{branch}-{date}-%' FOR UPDATE`.
 *
 * The existing unique index `trx_clinic_visits_visit_number_unique` is a default
 * btree. Because the stress/pilot database collation is `en_US.UTF-8` (not `C`),
 * that index CANNOT serve a `LIKE 'prefix%'` range scan, so PostgreSQL falls back
 * to a parallel index-only scan that filters the ENTIRE table (~1M rows removed by
 * filter) for every insert — ~250 ms of DB CPU per write. Under k6 concurrency this
 * saturates PostgreSQL CPU and flattens visit-create throughput (~5/s plateau, Sprint
 * 67.6 / 68.1 evidence) while PHP stays near-idle.
 *
 * Adding a btree index with `varchar_pattern_ops` makes the prefix `LIKE` a narrow
 * range scan (only the rows for that branch+date), removing the full-table scan.
 *
 * This is additive and presentation-neutral:
 *   - No column, constraint, or data change.
 *   - Visit-number format / numbering rules are UNCHANGED.
 *   - The existing unique index is kept (uniqueness + equality lookups still use it).
 *
 * PostgreSQL only. SQLite (test DB) does not support `varchar_pattern_ops`/CONCURRENTLY
 * and its small in-memory datasets do not need it, so this is a no-op there.
 *
 * `CONCURRENTLY` keeps the index build from write-locking the large `trx_clinic_visits`
 * table on the pilot VPS (run with `php artisan migrate --force`; never migrate:fresh).
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY cannot run inside a transaction block.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS trx_clinic_visits_visit_number_pattern_idx '
            .'ON trx_clinic_visits (visit_number varchar_pattern_ops)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS trx_clinic_visits_visit_number_pattern_idx');
    }
};
