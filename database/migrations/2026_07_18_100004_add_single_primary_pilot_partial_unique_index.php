<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4C — DB-level single-primary-pilot invariant.
 *
 * A `FOR UPDATE` guard on a zero-row predicate does not lock a gap in Postgres,
 * so two concurrent approvals of different branches could both pass the "no
 * other approved" check. This partial unique index makes at most ONE approved
 * pilot per environment enforceable at the database — the service catches the
 * resulting unique violation and turns it into a friendly validation error.
 *
 * Portable across Postgres + SQLite (both support partial unique indexes via
 * `CREATE UNIQUE INDEX ... WHERE ...`). Skipped on drivers that do not
 * (e.g. MySQL) — the service-level guard remains the fallback there.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
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

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            ." ON mst_satusehat_branch_pilot_profiles (environment) WHERE pilot_status = 'approved'"
        );
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
