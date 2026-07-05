<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DBPERF-1 — Composite index for the idempotency expiry sweep.
 *
 * App\Services\Foundation\IdempotencyService::expireOld() runs:
 *   WHERE status = 'reserved' AND expires_at < now()
 * sys_idempotency_keys previously only had separate single-column indexes on
 * status and expires_at. Additive and non-destructive — no column/data change.
 */
return new class extends Migration
{
    /**
     * PostgreSQL CREATE INDEX CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // PostgreSQL only. SQLite (test DB) does not support CONCURRENTLY and
        // its small in-memory datasets do not need it, so this is a no-op there.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_dbperf1_idempotency_status_expires_at
            ON sys_idempotency_keys (status, expires_at)
        ');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            DROP INDEX CONCURRENTLY IF EXISTS idx_dbperf1_idempotency_status_expires_at
        ');
    }
};
