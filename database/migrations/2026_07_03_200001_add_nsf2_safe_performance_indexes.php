<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint NSF-2 — Safe index pack from NSF-1 deferred candidates.
 *
 * Inventory (branch_id, movement_date): satisfied by trx_inv_movements_branch_date_index
 * from 2026_06_10_100000 unless missing (then idx_nsf2_* is created).
 *
 * Patients (branch_id, is_active): idx_nsf2_patients_branch_is_active for branch-scoped lists.
 *
 * PostgreSQL CONCURRENTLY — no transaction wrapper. SQLite no-op (tests use in-memory).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('trx_inventory_movements')
            && Schema::hasColumn('trx_inventory_movements', 'branch_id')
            && Schema::hasColumn('trx_inventory_movements', 'movement_date')
            && ! $this->hasCompositeIndex('trx_inventory_movements', ['branch_id', 'movement_date'])) {
            DB::statement('
                CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_nsf2_inventory_movements_branch_movement_date
                ON trx_inventory_movements (branch_id, movement_date)
            ');
        }

        if (Schema::hasTable('mst_patients')
            && Schema::hasColumn('mst_patients', 'branch_id')
            && Schema::hasColumn('mst_patients', 'is_active')
            && ! $this->hasCompositeIndex('mst_patients', ['branch_id', 'is_active'])) {
            DB::statement('
                CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_nsf2_patients_branch_is_active
                ON mst_patients (branch_id, is_active)
            ');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_nsf2_patients_branch_is_active');

        // Only drop NSF-2 inventory index if we created it (not the legacy analytics name).
        $legacy = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE indexname = 'trx_inv_movements_branch_date_index' LIMIT 1"
        );

        if ($legacy === null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_nsf2_inventory_movements_branch_movement_date');
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasCompositeIndex(string $table, array $columns): bool
    {
        $needle = '('.implode(', ', $columns).')';

        $row = DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ? LIMIT 1',
            [$table, '%'.$needle.'%'],
        );

        return $row !== null;
    }
};
