<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 9 — Multi Branch Foundation.
 *
 * Backfills the nullable branch_id column on the core transaction tables with
 * the default MAIN branch so legacy rows (created before multi-branch) belong
 * to the head office. This is a data migration only — it never drops or alters
 * a column, so it is safe to re-run and causes no data loss:
 *  - it only touches rows where branch_id IS NULL (already-scoped rows untouched),
 *  - if the MAIN branch does not exist yet (e.g. fresh schema before seeding),
 *    it no-ops gracefully instead of failing.
 */
return new class extends Migration
{
    /**
     * Core transaction tables that carry branch_id (added in the prior migration).
     *
     * @var array<int, string>
     */
    private array $tables = [
        'trx_lab_orders',
        'trx_lab_deliveries',
        'trx_invoices',
        'trx_payments',
    ];

    public function up(): void
    {
        // Resolve the default branch by its stable business code, not a hard-coded id.
        $mainBranchId = DB::table('mst_branches')
            ->where('code', 'MAIN')
            ->whereNull('deleted_at')
            ->value('id');

        // Nothing to backfill onto if the default branch has not been seeded yet.
        if ($mainBranchId === null) {
            return;
        }

        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            DB::table($table)
                ->whereNull('branch_id')
                ->update(['branch_id' => $mainBranchId]);
        }
    }

    public function down(): void
    {
        // Data backfill is not reversible without losing the original NULL state,
        // and reverting would risk data loss, so this is intentionally a no-op.
    }
};
