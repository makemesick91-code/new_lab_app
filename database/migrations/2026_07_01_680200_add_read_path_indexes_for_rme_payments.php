<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 68.2 — Read-path index for branch + period payment aggregates.
 *
 * Supports the hottest read paths that scan trx_rme_payments by branch over a
 * date window and either order by paid_at or sum/select amount:
 *   - OwnerDashboardKpiService::paymentQuery() per-branch revenue
 *     (whereIn branch_id + whereBetween paid_at + sum amount) and
 *     dailyPaymentTrend (get(['paid_at','amount'])).
 *   - RmeReportController::paymentReportQuery() branch-filtered listing
 *     ordered by paid_at DESC.
 *
 * The leading (branch_id, paid_at) keys the range scan; INCLUDE (amount)
 * enables index-only aggregation for the dashboard sum/trend. Additive and
 * non-destructive — no column, data, or behaviour change.
 */
return new class extends Migration
{
    /**
     * PostgreSQL CREATE INDEX CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // PostgreSQL only. SQLite (test DB) does not support CONCURRENTLY/INCLUDE
        // and its small in-memory datasets do not need it, so this is a no-op there.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE INDEX CONCURRENTLY IF NOT EXISTS trx_rme_payments_branch_paid_at_idx
            ON trx_rme_payments (branch_id, paid_at DESC)
            INCLUDE (amount)
        ');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            DROP INDEX CONCURRENTLY IF EXISTS trx_rme_payments_branch_paid_at_idx
        ');
    }
};
