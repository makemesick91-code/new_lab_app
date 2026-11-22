<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * DMS-SPN4-READY-PILOT-1 G6 / R6-B1 — run a probe as a PostgreSQL role that OWNS
 * NOTHING: the position the runtime role is in after the rule 245 ownership split.
 *
 * The role is created inside the test's own transaction (CREATE ROLE and GRANT are
 * transactional in PostgreSQL), so RefreshDatabase's rollback removes it together
 * with everything else; nothing outlives the test. Its grants are what R6 leaves
 * the runtime at most: USAGE on the schema, DML on the tables, USAGE/SELECT on the
 * sequences. Never ownership, membership in the owner, CREATE, TRUNCATE or TRIGGER.
 *
 * The test identity must be a superuser (the GitHub-hosted critical gate connects
 * as postgres) or hold CREATEROLE (PostgreSQL 16 then self-grants SET through
 * createrole_self_grant). Any other identity gets a visible SKIP with the reason,
 * never a pass.
 */
final class NonOwnerRole
{
    public static function unavailableReason(): ?string
    {
        if (DB::getDriverName() !== 'pgsql') {
            return 'needs PostgreSQL: table ownership has no SQLite equivalent';
        }

        $me = DB::selectOne('SELECT rolsuper, rolcreaterole FROM pg_catalog.pg_roles WHERE rolname = current_user');

        if (! $me->rolsuper && ! $me->rolcreaterole) {
            return 'the test database identity can neither create nor assume a non-owner role (needs SUPERUSER or CREATEROLE)';
        }

        return null;
    }

    /** Creates the probe role inside the current test transaction and returns its name. */
    public static function create(bool $dml = true): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('NonOwnerRole must run inside the test transaction so the role is rolled back.');
        }

        $role = 'r6b1_runtime_'.bin2hex(random_bytes(5));
        $schema = (string) DB::scalar('SELECT current_schema()');
        $superuser = (bool) DB::scalar('SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname = current_user');

        if (! $superuser) {
            DB::statement("SET LOCAL createrole_self_grant = 'set'");
        }

        DB::statement("CREATE ROLE \"{$role}\" NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS");
        DB::statement("GRANT USAGE ON SCHEMA \"{$schema}\" TO \"{$role}\"");
        DB::statement(($dml ? 'GRANT SELECT, INSERT, UPDATE, DELETE' : 'GRANT SELECT')." ON ALL TABLES IN SCHEMA \"{$schema}\" TO \"{$role}\"");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA \"{$schema}\" TO \"{$role}\"");

        return $role;
    }

    /** Runs $probe as $role. The switch is transaction-local and undone afterwards. */
    public static function run(string $role, Closure $probe): mixed
    {
        DB::statement("SET LOCAL ROLE \"{$role}\"");

        try {
            return $probe();
        } finally {
            if (! self::aborted()) {
                DB::statement('RESET ROLE');
            }
        }
    }

    /** The SQLSTATE $statement fails with (run inside a savepoint), or null when it succeeds. */
    public static function sqlstate(Closure $statement): ?string
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            return (string) ($exception->errorInfo[0] ?? $exception->getCode());
        }

        return null;
    }

    private static function aborted(): bool
    {
        try {
            DB::select('SELECT 1');

            return false;
        } catch (QueryException) {
            return true;
        }
    }
}
