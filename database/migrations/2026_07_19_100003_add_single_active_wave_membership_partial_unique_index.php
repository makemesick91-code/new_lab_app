<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — DB-level single-active-wave-membership invariant.
 *
 * A branch may belong to at most one active (non-removed) wave. A `FOR UPDATE`
 * guard on a zero-row predicate does not lock a gap in Postgres, so two
 * concurrent enrollments could both pass the service check. This partial unique
 * index makes at most ONE active membership per (environment, branch)
 * enforceable at the database; the service catches the violation and returns a
 * friendly validation error. Portable across Postgres + SQLite. Skipped on
 * drivers without partial unique indexes — the service guard is the fallback.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    private const INDEX = 'trx_ss_wave_membership_active_uq';

    public function up(): void
    {
        if (! Schema::hasTable('trx_satusehat_wave_branch_memberships')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON trx_satusehat_wave_branch_memberships (environment, branch_id)'
            ." WHERE status = 'enrolled'"
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
