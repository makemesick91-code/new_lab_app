<?php

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B3 (rule 245; Design Lock §12.5, §13.4 N-9) — the
 * read-only check that the database matches the role separation.
 *
 * It runs as the runtime login, inside a READ ONLY transaction that is rolled
 * back, and reads only the catalog and the server's own privilege functions
 * (has_*_privilege, pg_has_role), so it reports what the runtime can actually
 * do, through PUBLIC and through any membership, not what one ACL row says.
 *
 * Result: MATCH (0), DRIFT (1) or CANNOT_VERIFY (2). Each problem names the
 * table, column, sequence, function or trigger, never a role that is not a
 * predefined `pg_*` role (role names stay out of logs and CI output, OD-R6-6).
 *
 * Checks:
 *  1. the runtime holds no elevated attribute (superuser, createrole, createdb,
 *     bypassrls, replication);
 *  2. the runtime reaches no role but the allowed monitoring roles (D-Q1), so
 *     not the owner role, not pg_monitor, not pg_write_server_files…;
 *  3. the runtime owns nothing, in any database (pg_shdepend);
 *  4. every classified table gives the runtime exactly its class, at table and
 *     column level; every sequence USAGE and SELECT only;
 *  5. the runtime can create in no schema, and not in the database;
 *  6. the database owner is a superuser (N-9);
 *  7. every trigger function on a `public` table is owned by the owner role,
 *     pins search_path and is not SECURITY DEFINER; every trigger fires;
 *  8. the runtime cannot reset pg_stat_statements, cannot export or import
 *     server files through large objects, and cannot set
 *     session_replication_role (which would switch the guard triggers off).
 * The registry itself must agree with the schema (RuntimePrivilegeRegistry::verify).
 */
final class DatabasePrivilegeCheck
{
    public const MATCH = 0;

    public const DRIFT = 1;

    public const CANNOT_VERIFY = 2;

    /** The only roles the runtime may reach: the monitoring privileges of D-Q1. */
    public const ALLOWED_MEMBERSHIPS = ['pg_read_all_settings', 'pg_read_all_stats'];

    private const TABLE_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

    public function __construct(private readonly RuntimePrivilegeRegistry $registry) {}

    /**
     * The full check, as the runtime itself.
     *
     * @return array{status: int, problems: list<string>, passed: list<string>}
     */
    public function run(ConnectionInterface $connection, string $owner, ?string $expectedRuntime = null): array
    {
        if (preg_match(RuntimeGrantPlan::IDENTIFIER, $owner) !== 1) {
            return $this->cannotVerify('the owner role is not configured as a plain role name.');
        }
        try {
            if ($connection->getDriverName() !== 'pgsql') {
                return $this->cannotVerify('the database is not PostgreSQL.');
            }

            $connection->beginTransaction();
            try {
                // Inside an outer transaction (a test's) the mode can no longer be
                // set; the check still only reads, and the savepoint is rolled back.
                if ($connection->transactionLevel() === 1) {
                    $connection->statement('SET TRANSACTION READ ONLY');
                }
                $session = $connection->selectOne('select session_user as ses, current_user as cur, current_schema() as sch');
                if ($session->ses !== $session->cur) {
                    return $this->cannotVerify('the check must run as the runtime login itself, not under SET ROLE.');
                }
                if ($expectedRuntime !== null && $expectedRuntime !== '' && $session->ses !== $expectedRuntime) {
                    return $this->cannotVerify('the check runs as a login that is not the configured runtime role.');
                }
                if ($session->sch !== 'public') {
                    return $this->cannotVerify('the connection does not resolve to the public schema.');
                }
                if ((int) $connection->selectOne('select count(*) as n from pg_catalog.pg_roles where rolname = ?', [$owner])->n !== 1) {
                    return $this->cannotVerify('the configured owner role does not exist.');
                }

                return $this->evaluate($connection, (string) $session->ses, $owner);
            } finally {
                // Read only, and nothing to keep.
                $connection->rollBack();
            }
        } catch (Throwable $exception) {
            return $this->cannotVerify('the catalog could not be read ('.class_basename($exception).').');
        }
    }

    /**
     * Only the grant checks (4) for a given runtime: the apply step re-checks its
     * own transaction with this before it commits.
     *
     * @param  array<string, array{class: string, columns: list<string>, source?: string}>  $entries
     * @return list<string>
     */
    public static function grantProblems(ConnectionInterface $connection, string $runtime, array $entries): array
    {
        $problems = [];
        $tables = [];
        foreach ($connection->select(<<<'SQL'
            select c.relname,
                   pg_catalog.has_table_privilege(:r1, c.oid, 'SELECT') as "SELECT",
                   pg_catalog.has_table_privilege(:r2, c.oid, 'INSERT') as "INSERT",
                   pg_catalog.has_table_privilege(:r3, c.oid, 'UPDATE') as "UPDATE",
                   pg_catalog.has_table_privilege(:r4, c.oid, 'DELETE') as "DELETE",
                   pg_catalog.has_table_privilege(:r5, c.oid, 'TRUNCATE') as "TRUNCATE",
                   pg_catalog.has_table_privilege(:r6, c.oid, 'REFERENCES') as "REFERENCES",
                   pg_catalog.has_table_privilege(:r7, c.oid, 'TRIGGER') as "TRIGGER",
                   pg_catalog.has_any_column_privilege(:r8, c.oid, 'INSERT') as any_insert,
                   pg_catalog.has_any_column_privilege(:r9, c.oid, 'UPDATE') as any_update,
                   pg_catalog.has_any_column_privilege(:r10, c.oid, 'REFERENCES') as any_references
            from pg_catalog.pg_class c
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relispartition
            SQL, self::bindRuntime($runtime, 10)) as $row) {
            $tables[(string) $row->relname] = (array) $row;
        }

        foreach ($entries as $table => $entry) {
            $have = $tables[$table] ?? null;
            if ($have === null) {
                continue; // a missing table is the registry's error
            }
            $want = RuntimeGrantPlan::TABLE_PRIVILEGES[$entry['class']] ?? [];
            foreach (self::TABLE_PRIVILEGES as $privilege) {
                $expected = in_array($privilege, $want, true);
                if ((bool) $have[$privilege] !== $expected) {
                    $problems[] = "table {$table} ({$entry['class']}): the runtime ".($expected ? 'lacks' : 'holds')." {$privilege}";
                }
            }
            if ($entry['class'] !== RuntimePrivilegeClass::FULL_DML) {
                if ((bool) $have['any_references']) {
                    $problems[] = "table {$table} ({$entry['class']}): the runtime holds REFERENCES on a column";
                }
                if ($entry['class'] === RuntimePrivilegeClass::READ_ONLY_RUNTIME && (bool) $have['any_insert']) {
                    $problems[] = "table {$table} ({$entry['class']}): the runtime holds INSERT on a column";
                }
                if ($entry['class'] !== RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS && (bool) $have['any_update']) {
                    $problems[] = "table {$table} ({$entry['class']}): the runtime holds UPDATE on a column";
                }
            }
        }

        $withColumns = array_keys(array_filter($entries, fn (array $e): bool => $e['class'] === RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS));
        if ($withColumns !== []) {
            $placeholders = implode(', ', array_fill(0, count($withColumns), '?'));
            foreach ($connection->select(<<<SQL
                select c.relname, a.attname, pg_catalog.has_column_privilege(?, c.oid, a.attnum, 'UPDATE') as can_update
                from pg_catalog.pg_attribute a
                join pg_catalog.pg_class c on c.oid = a.attrelid
                join pg_catalog.pg_namespace n on n.oid = c.relnamespace
                where n.nspname = 'public' and c.relname in ({$placeholders}) and a.attnum > 0 and not a.attisdropped
                order by c.relname, a.attnum
                SQL, [$runtime, ...$withColumns]) as $row) {
                $expected = in_array((string) $row->attname, $entries[(string) $row->relname]['columns'], true);
                if ((bool) $row->can_update !== $expected) {
                    $problems[] = "table {$row->relname}: the runtime ".($expected ? 'cannot' : 'can')." UPDATE column {$row->attname}";
                }
            }
        }

        foreach ($connection->select(<<<'SQL'
            select c.relname,
                   pg_catalog.has_sequence_privilege(:r1, c.oid, 'USAGE') as can_use,
                   pg_catalog.has_sequence_privilege(:r2, c.oid, 'SELECT') as can_select,
                   pg_catalog.has_sequence_privilege(:r3, c.oid, 'UPDATE') as can_update
            from pg_catalog.pg_class c
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'S'
            order by c.relname
            SQL, self::bindRuntime($runtime, 3)) as $row) {
            if (! (bool) $row->can_use || ! (bool) $row->can_select) {
                $problems[] = "sequence {$row->relname}: the runtime lacks USAGE or SELECT";
            }
            if ((bool) $row->can_update) {
                $problems[] = "sequence {$row->relname}: the runtime holds UPDATE (setval)";
            }
        }

        sort($problems);

        return $problems;
    }

    /** @return array{status: int, problems: list<string>, passed: list<string>} */
    private function evaluate(ConnectionInterface $connection, string $runtime, string $owner): array
    {
        $problems = [];
        $passed = [];
        $record = function (string $check, array $found) use (&$problems, &$passed): void {
            if ($found === []) {
                $passed[] = $check;

                return;
            }
            array_push($problems, ...$found);
        };

        $record('the registry agrees with the schema', array_map(fn (string $e): string => "registry: {$e}", $this->registry->verify($connection)));

        $role = $connection->selectOne('select rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolreplication from pg_catalog.pg_roles where rolname = ?', [$runtime]);
        $attributes = [];
        foreach (['rolsuper' => 'SUPERUSER', 'rolcreaterole' => 'CREATEROLE', 'rolcreatedb' => 'CREATEDB', 'rolbypassrls' => 'BYPASSRLS', 'rolreplication' => 'REPLICATION'] as $column => $label) {
            if ((bool) $role->{$column}) {
                $attributes[] = "the runtime holds {$label}";
            }
        }
        $record('the runtime holds no elevated attribute', $attributes);

        $reached = array_map(fn (object $r): string => (string) $r->rolname, $connection->select(
            'select b.rolname from pg_catalog.pg_roles b where b.rolname <> ? and pg_catalog.pg_has_role(?, b.oid, \'MEMBER\') order by b.rolname',
            [$runtime, $runtime]
        ));
        $record('the runtime reaches only the monitoring roles', array_map(
            fn (string $r): string => $r === $owner ? 'the runtime is a member of the owner role' : 'the runtime is a member of '.self::roleLabel($r),
            array_values(array_diff($reached, self::ALLOWED_MEMBERSHIPS))
        ));

        $owned = $connection->selectOne(<<<'SQL'
            select count(*) filter (where d.dbid = (select oid from pg_catalog.pg_database where datname = current_database())) as here,
                   count(*) filter (where d.dbid = 0) as shared,
                   count(*) filter (where d.dbid <> 0 and d.dbid <> (select oid from pg_catalog.pg_database where datname = current_database())) as elsewhere
            from pg_catalog.pg_shdepend d
            where d.refclassid = 'pg_catalog.pg_authid'::regclass and d.deptype = 'o'
              and d.refobjid = (select oid from pg_catalog.pg_roles where rolname = ?)
            SQL, [$runtime]);
        $ownership = [];
        foreach (['here' => 'in this database', 'shared' => 'among shared objects (databases, tablespaces)', 'elsewhere' => 'in other databases'] as $key => $where) {
            if ((int) $owned->{$key} > 0) {
                $ownership[] = "the runtime owns {$owned->{$key}} object(s) {$where}";
            }
        }
        $record('the runtime owns nothing', $ownership);

        [$entries] = $this->registry->expected();
        $record('every table and sequence grants the runtime exactly its class', self::grantProblems($connection, $runtime, $entries));

        $creates = array_map(fn (object $r): string => "the runtime can CREATE in schema {$r->nspname}", $connection->select(<<<'SQL'
            select n.nspname from pg_catalog.pg_namespace n
            where pg_catalog.has_schema_privilege(?, n.oid, 'CREATE')
              and n.nspname not like 'pg\_temp\_%' and n.nspname not like 'pg\_toast\_temp\_%'
            order by n.nspname
            SQL, [$runtime]));
        if (! (bool) $connection->selectOne("select pg_catalog.has_schema_privilege(?, 'public', 'USAGE') as u", [$runtime])->u) {
            $creates[] = 'the runtime lacks USAGE on schema public';
        }
        if ((bool) $connection->selectOne('select pg_catalog.has_database_privilege(?, current_database(), \'CREATE\') as c', [$runtime])->c) {
            $creates[] = 'the runtime can CREATE in the database';
        }
        $record('the runtime can create no schema object and nothing in the database', $creates);

        $databaseOwnerIsSuperuser = (bool) $connection->selectOne(
            'select r.rolsuper as s from pg_catalog.pg_database d join pg_catalog.pg_roles r on r.oid = d.datdba where d.datname = current_database()'
        )->s;
        $record('the database is owned by a superuser (N-9)', $databaseOwnerIsSuperuser ? [] : ['the database owner is not a superuser (N-9)']);

        $functions = [];
        foreach ($connection->select(<<<'SQL'
            select distinct p.oid, p.proname, pg_catalog.pg_get_userbyid(p.proowner) as owner, p.prosecdef,
                   coalesce(array_to_string(p.proconfig, chr(1)), '') as config
            from pg_catalog.pg_trigger t
            join pg_catalog.pg_class c on c.oid = t.tgrelid
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            join pg_catalog.pg_proc p on p.oid = t.tgfoid
            where n.nspname = 'public' and not t.tgisinternal
            order by p.proname
            SQL) as $row) {
            if ($row->owner !== $owner) {
                $functions[] = "trigger function {$row->proname} is not owned by the owner role";
            }
            if ((bool) $row->prosecdef) {
                $functions[] = "trigger function {$row->proname} is SECURITY DEFINER";
            }
            $settings = $row->config === '' ? [] : explode(chr(1), (string) $row->config);
            $searchPath = array_values(array_filter($settings, fn (string $s): bool => str_starts_with($s, 'search_path=')));
            if ($searchPath === [] || str_contains($searchPath[0], '$user')) {
                $functions[] = "trigger function {$row->proname} does not pin search_path";
            }
        }
        foreach ($connection->select(<<<'SQL'
            select c.relname, t.tgname, t.tgenabled
            from pg_catalog.pg_trigger t
            join pg_catalog.pg_class c on c.oid = t.tgrelid
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and not t.tgisinternal and t.tgenabled not in ('O', 'A')
            order by c.relname, t.tgname
            SQL) as $row) {
            $functions[] = "trigger {$row->tgname} on {$row->relname} does not fire (state {$row->tgenabled})";
        }
        $record('every trigger function is owned by the owner role and pinned; every trigger fires', $functions);

        $guards = [];
        foreach ($connection->select(<<<'SQL'
            select p.oid::regprocedure::text as signature, pg_catalog.has_function_privilege(?, p.oid, 'EXECUTE') as can_execute
            from pg_catalog.pg_proc p
            where p.proname = 'pg_stat_statements_reset'
               or p.oid in ('pg_catalog.lo_export(oid, text)'::regprocedure, 'pg_catalog.lo_import(text)'::regprocedure, 'pg_catalog.lo_import(text, oid)'::regprocedure)
            order by 1
            SQL, [$runtime]) as $row) {
            if ((bool) $row->can_execute) {
                $guards[] = "the runtime can EXECUTE {$row->signature}";
            }
        }
        if ((bool) $connection->selectOne("select pg_catalog.has_parameter_privilege(?, 'session_replication_role', 'SET') as s", [$runtime])->s) {
            $guards[] = 'the runtime can SET session_replication_role';
        }
        $record('the runtime cannot reset statistics, reach server files or switch triggers off', $guards);

        sort($problems);

        return ['status' => $problems === [] ? self::MATCH : self::DRIFT, 'problems' => array_values(array_unique($problems)), 'passed' => $passed];
    }

    /** A predefined role keeps its name; any other role's name is withheld (OD-R6-6). */
    private static function roleLabel(string $role): string
    {
        return str_starts_with($role, 'pg_') ? $role : 'a non-predefined role (name withheld)';
    }

    /** @return array<string, string> */
    private static function bindRuntime(string $runtime, int $count): array
    {
        $bindings = [];
        for ($i = 1; $i <= $count; $i++) {
            $bindings['r'.$i] = $runtime;
        }

        return $bindings;
    }

    /** @return array{status: int, problems: list<string>, passed: list<string>} */
    private function cannotVerify(string $reason): array
    {
        return ['status' => self::CANNOT_VERIFY, 'problems' => [$reason], 'passed' => []];
    }
}
