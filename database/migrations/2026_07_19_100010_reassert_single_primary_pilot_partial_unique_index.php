<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — self-heal the single-primary-pilot PARTIAL unique index.
 *
 * The 4C index `mst_ss_pilot_single_approved_uq` is meant to be a PARTIAL unique
 * index (unique on `environment` only WHERE pilot_status = 'approved'), so at
 * most one approved pilot exists per environment while unlimited non-approved
 * branch profiles coexist. On SQLite, any later table rebuild (e.g. adding a
 * foreign-key column) recreates indexes from Laravel's introspection and DROPS
 * the WHERE clause — flattening it to a full unique on `environment`, which
 * would wrongly cap the environment to a SINGLE branch profile and break
 * multi-branch readiness. PostgreSQL is unaffected (ADD COLUMN never rebuilds),
 * but this migration re-asserts the correct partial index on both drivers so
 * any DB is self-healed and future rebuilds are corrected.
 *
 * Idempotent + additive (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    private const INDEX = 'mst_ss_pilot_single_approved_uq';

    public function up(): void
    {
        if (! Schema::hasTable('mst_satusehat_branch_pilot_profiles')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        // Drop whatever exists under the name (possibly a flattened full unique)
        // and recreate the correct partial unique index.
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON mst_satusehat_branch_pilot_profiles (environment)'
            ." WHERE pilot_status = 'approved'"
        );
    }

    public function down(): void
    {
        // No-op: the 4C migration owns this index's lifecycle; re-asserting it
        // is safe to leave in place on rollback.
    }
};
