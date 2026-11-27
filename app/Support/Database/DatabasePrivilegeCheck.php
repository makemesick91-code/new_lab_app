<?php

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B3 (rule 245; Design Lock §12.5, §13.4 N-9) — the
 * read-only check that the database matches the role separation.
 *
 * It runs as the runtime login, inside a READ ONLY transaction that is rolled
 * back (and it verifies the transaction really is read only), and reads only the
 * catalog and the server's own privilege functions (has_*_privilege,
 * pg_has_role), so it reports what the runtime can actually do, through PUBLIC
 * and through any membership, not what one ACL row says.
 *
 * Result: MATCH (0), DRIFT (1) or CANNOT_VERIFY (2). Each problem names the
 * table, column, sequence, function or trigger, never a role that is not a
 * predefined `pg_*` role (role names stay out of logs and CI output, OD-R6-6).
 *
 * Checks (review round 1 added 9 to 16):
 *  1. the runtime holds no elevated attribute (superuser, createrole, createdb,
 *     bypassrls, replication);
 *  2. the runtime reaches no role but the allowed monitoring roles (D-Q1), so
 *     not the owner role, not pg_monitor, not pg_write_server_files…;
 *  3. the runtime owns nothing, in any database (pg_shdepend);
 *  4. every classified table gives the runtime exactly its class, at table and
 *     column level; every sequence USAGE and SELECT only; and the runtime can
 *     write to no other relation (views, materialized views, foreign tables,
 *     partitions, tables outside `public`);
 *  5. the runtime can create in no schema, and neither CREATE nor TEMPORARY in
 *     the database;
 *  6. the database owner is a superuser (N-9);
 *  7. every trigger function on a `public` table is owned by the owner role,
 *     is not SECURITY DEFINER and pins search_path with pg_temp last; every
 *     trigger fires;
 *  8. the runtime cannot execute pg_stat_statements_reset or the server-file
 *     large-object functions;
 *  9. triggers are not switched off: this session's session_replication_role
 *     is `origin`, and no role or database default sets it for the runtime;
 * 10. the runtime can execute no SECURITY DEFINER function outside the system
 *     schemas, and no table in `public` carries a rewrite rule;
 * 11. exactly the expected guard triggers exist, with their exact definitions
 *     (config runtime_privilege_classes.guard_triggers); no other trigger;
 * 12. the runtime can execute no function whose ACL withholds EXECUTE from
 *     PUBLIC (restricted built-ins such as pg_read_binary_file, pg_stat_reset);
 * 13. the runtime holds no SET or ALTER SYSTEM privilege on any parameter;
 * 14. the runtime holds no grant option on anything;
 * 15. default privileges give the runtime (or PUBLIC) at most SELECT on new
 *     tables and USAGE, SELECT on new sequences.
 * The registry itself must agree with the schema (RuntimePrivilegeRegistry::verify).
 *
 * Accepted (review round 1, finding 14): the runtime can always make the check
 * report DRIFT (for example by creating a large object it then owns). That
 * stops a deploy, the safe direction; it never yields a false MATCH.
 */
final class DatabasePrivilegeCheck
{
    public const MATCH = 0;

    public const DRIFT = 1;

    public const CANNOT_VERIFY = 2;

    /** The only roles the runtime may reach: the monitoring privileges of D-Q1. */
    public const ALLOWED_MEMBERSHIPS = ['pg_read_all_settings', 'pg_read_all_stats'];

    private const TABLE_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

    /** Default privileges the runtime (or PUBLIC) may receive, per object type (pg_default_acl.defaclobjtype). */
    private const ALLOWED_DEFAULTS = ['r' => ['SELECT'], 'S' => ['USAGE', 'SELECT']];

    public function __construct(private readonly RuntimePrivilegeRegistry $registry) {}

    /**
     * The full check, as the runtime itself.
     *
     * @param  string|null  $connectionName  the runtime connection; null = the default connection
     * @return array{status: int, problems: list<string>, passed: list<string>}
     */
    public function run(?string $connectionName, string $owner, ?string $expectedRuntime): array
    {
        if (! RuntimeGrantPlan::plainRole($owner)) {
            return $this->cannotVerify('the owner role is not configured as a plain, non-reserved role name.');
        }
        // Review round 1, finding 11: without a configured runtime role there is
        // nothing to compare the session with, so the check cannot verify.
        if ($expectedRuntime === null || ! RuntimeGrantPlan::plainRole($expectedRuntime)) {
            return $this->cannotVerify('the runtime role is not configured as a plain, non-reserved role name.');
        }
        try {
            // Review round 1, finding 10: resolving the connection can fail too.
            $connection = DB::connection($connectionName);
            if ($connection->getDriverName() !== 'pgsql') {
                return $this->cannotVerify('the database is not PostgreSQL.');
            }

            $connection->beginTransaction();
            try {
                // Inside an outer transaction (a test's) the mode can no longer be
                // set; the check still only reads, and the savepoint is rolled back.
                if ($connection->transactionLevel() === 1) {
                    $connection->statement('SET TRANSACTION READ ONLY');
                    if ((string) $connection->selectOne("select current_setting('transaction_read_only') as ro")->ro !== 'on') {
                        return $this->cannotVerify('the check could not make its transaction read only.');
                    }
                }
                $session = $connection->selectOne('select session_user as ses, current_user as cur, current_schema() as sch');
                if ($session->ses !== $session->cur) {
                    return $this->cannotVerify('the check must run as the runtime login itself, not under SET ROLE.');
                }
                if ($session->ses !== $expectedRuntime) {
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

        // Review round 1, finding 3: a view, materialized view, foreign table or
        // partition, in any schema, or a table outside `public`, runs its access
        // with its owner's rights or outside the registry; the runtime may write
        // to none of them. (Reading is not an escalation: every class includes
        // SELECT.)
        foreach ($connection->select(<<<'SQL'
            select n.nspname, c.relname, c.relkind
            from pg_catalog.pg_class c
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where n.nspname not in ('pg_catalog', 'information_schema') and n.nspname not like 'pg\_toast%'
              and n.nspname not like 'pg\_temp\_%'
              and c.relkind in ('r', 'p', 'v', 'm', 'f')
              and not (n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relispartition)
              and (pg_catalog.has_table_privilege(:r1, c.oid, 'INSERT') or pg_catalog.has_table_privilege(:r2, c.oid, 'UPDATE')
                   or pg_catalog.has_table_privilege(:r3, c.oid, 'DELETE') or pg_catalog.has_table_privilege(:r4, c.oid, 'TRUNCATE')
                   or pg_catalog.has_table_privilege(:r5, c.oid, 'REFERENCES') or pg_catalog.has_table_privilege(:r6, c.oid, 'TRIGGER')
                   or pg_catalog.has_any_column_privilege(:r7, c.oid, 'INSERT') or pg_catalog.has_any_column_privilege(:r8, c.oid, 'UPDATE'))
            order by 1, 2
            SQL, self::bindRuntime($runtime, 8)) as $row) {
            $problems[] = "relation {$row->nspname}.{$row->relname} (kind {$row->relkind}): the runtime can write to it outside the table registry";
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

        // 1. Attributes.
        $role = $connection->selectOne('select rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolreplication from pg_catalog.pg_roles where rolname = ?', [$runtime]);
        $attributes = [];
        foreach (['rolsuper' => 'SUPERUSER', 'rolcreaterole' => 'CREATEROLE', 'rolcreatedb' => 'CREATEDB', 'rolbypassrls' => 'BYPASSRLS', 'rolreplication' => 'REPLICATION'] as $column => $label) {
            if ((bool) $role->{$column}) {
                $attributes[] = "the runtime holds {$label}";
            }
        }
        $record('the runtime holds no elevated attribute', $attributes);

        // 2. Reachable roles.
        $reached = array_map(fn (object $r): string => (string) $r->rolname, $connection->select(
            'select b.rolname from pg_catalog.pg_roles b where b.rolname <> ? and pg_catalog.pg_has_role(?, b.oid, \'MEMBER\') order by b.rolname',
            [$runtime, $runtime]
        ));
        $record('the runtime reaches only the monitoring roles', array_map(
            fn (string $r): string => $r === $owner ? 'the runtime is a member of the owner role' : 'the runtime is a member of '.self::roleLabel($r),
            array_values(array_diff($reached, self::ALLOWED_MEMBERSHIPS))
        ));

        // 3. Ownership, in every database and among shared objects.
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

        // 4. Class grants, sequences, and no write to any other relation.
        [$entries] = $this->registry->expected();
        $record('every table and sequence grants the runtime exactly its class, and no other relation is writable', self::grantProblems($connection, $runtime, $entries));

        // 5. Creation (finding 9: TEMPORARY too).
        $creates = array_map(fn (object $r): string => "the runtime can CREATE in schema {$r->nspname}", $connection->select(<<<'SQL'
            select n.nspname from pg_catalog.pg_namespace n
            where pg_catalog.has_schema_privilege(?, n.oid, 'CREATE')
              and n.nspname not like 'pg\_temp\_%' and n.nspname not like 'pg\_toast\_temp\_%'
            order by n.nspname
            SQL, [$runtime]));
        if (! (bool) $connection->selectOne("select pg_catalog.has_schema_privilege(?, 'public', 'USAGE') as u", [$runtime])->u) {
            $creates[] = 'the runtime lacks USAGE on schema public';
        }
        $database = $connection->selectOne("select pg_catalog.has_database_privilege(?, current_database(), 'CREATE') as c, pg_catalog.has_database_privilege(?, current_database(), 'TEMPORARY') as t", [$runtime, $runtime]);
        if ((bool) $database->c) {
            $creates[] = 'the runtime can CREATE in the database';
        }
        if ((bool) $database->t) {
            $creates[] = 'the runtime can create TEMPORARY objects in the database';
        }
        $record('the runtime can create no schema object, nothing in the database and no temporary object', $creates);

        // 6. N-9.
        $databaseOwnerIsSuperuser = (bool) $connection->selectOne(
            'select r.rolsuper as s from pg_catalog.pg_database d join pg_catalog.pg_roles r on r.oid = d.datdba where d.datname = current_database()'
        )->s;
        $record('the database is owned by a superuser (N-9)', $databaseOwnerIsSuperuser ? [] : ['the database owner is not a superuser (N-9)']);

        // 7 and 11. Trigger functions, trigger states, and exactly the expected guard triggers.
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
            if ($searchPath === [] || ! self::pinnedSearchPath(substr($searchPath[0], strlen('search_path=')))) {
                $functions[] = "trigger function {$row->proname} does not pin search_path with pg_temp last";
            }
        }
        $live = [];
        foreach ($connection->select(<<<'SQL'
            select c.relname, t.tgname, t.tgenabled, pg_catalog.pg_get_triggerdef(t.oid) as def
            from pg_catalog.pg_trigger t
            join pg_catalog.pg_class c on c.oid = t.tgrelid
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and not t.tgisinternal
            order by c.relname, t.tgname
            SQL) as $row) {
            $live[(string) $row->def] = true;
            if (! in_array($row->tgenabled, ['O', 'A'], true)) {
                $functions[] = "trigger {$row->tgname} on {$row->relname} does not fire (state {$row->tgenabled})";
            }
        }
        $expected = array_fill_keys((array) config('runtime_privilege_classes.guard_triggers', []), true);
        if ($expected === []) {
            $functions[] = 'no expected guard trigger is configured (runtime_privilege_classes.guard_triggers)';
        }
        foreach (array_keys(array_diff_key($expected, $live)) as $missing) {
            $functions[] = 'an expected guard trigger is missing or changed: '.self::triggerName($missing);
        }
        foreach (array_keys(array_diff_key($live, $expected)) as $unexpected) {
            $functions[] = 'a trigger that is not in the expected list exists: '.self::triggerName($unexpected);
        }
        $record('exactly the expected guard triggers exist and fire; their functions are owned by the owner role and pinned', $functions);

        // 8 and 12. Functions the runtime must not execute.
        $guards = [];
        foreach ($connection->select(<<<'SQL'
            select p.oid::regprocedure::text as signature
            from pg_catalog.pg_proc p
            where (p.proname = 'pg_stat_statements_reset'
                   or p.oid in ('pg_catalog.lo_export(oid, text)'::regprocedure, 'pg_catalog.lo_import(text)'::regprocedure, 'pg_catalog.lo_import(text, oid)'::regprocedure)
                   or (p.proacl is not null
                       and not exists (select 1 from pg_catalog.aclexplode(p.proacl) a
                                       where a.privilege_type = 'EXECUTE'
                                         and (a.grantee = 0 or a.grantee in (select oid from pg_catalog.pg_roles where rolname in ('pg_read_all_settings', 'pg_read_all_stats'))))))
              and pg_catalog.has_function_privilege(?, p.oid, 'EXECUTE')
              and p.proowner <> (select oid from pg_catalog.pg_roles where rolname = ?)
            order by 1
            SQL, [$runtime, $runtime]) as $row) {
            $guards[] = "the runtime can EXECUTE {$row->signature}, which PUBLIC may not";
        }
        $record('the runtime can execute no restricted function (statistics reset, server files, other withheld built-ins)', $guards);

        // 9. Triggers are not switched off for the runtime's sessions (finding 1).
        $replication = [];
        if ((string) $connection->selectOne("select current_setting('session_replication_role') as r")->r !== 'origin') {
            $replication[] = 'session_replication_role is not origin in the runtime session: triggers and rules do not fire';
        }
        foreach ($connection->select(<<<'SQL'
            select case when s.setrole = 0 then 'every role' else 'the runtime role' end as who,
                   case when s.setdatabase = 0 then 'every database' else 'this database' end as scope
            from pg_catalog.pg_db_role_setting s
            cross join lateral unnest(s.setconfig) as cfg(setting)
            where s.setrole in (0, (select oid from pg_catalog.pg_roles where rolname = ?))
              and s.setdatabase in (0, (select oid from pg_catalog.pg_database where datname = current_database()))
              and lower(split_part(cfg.setting, '=', 1)) = 'session_replication_role'
            SQL, [$runtime]) as $row) {
            $replication[] = "a default sets session_replication_role for {$row->who} in {$row->scope}";
        }
        $record('triggers and rules fire in the runtime session, and no role or database default switches them off', $replication);

        // 10. Owner rights reachable by the runtime (finding 2).
        $definer = array_map(fn (object $r): string => "the runtime can EXECUTE SECURITY DEFINER function {$r->signature}", $connection->select(<<<'SQL'
            select p.oid::regprocedure::text as signature
            from pg_catalog.pg_proc p join pg_catalog.pg_namespace n on n.oid = p.pronamespace
            where p.prosecdef and n.nspname not in ('pg_catalog', 'information_schema')
              and pg_catalog.has_function_privilege(?, p.oid, 'EXECUTE')
            order by 1
            SQL, [$runtime]));
        foreach ($connection->select(<<<'SQL'
            select c.relname, r.rulename
            from pg_catalog.pg_rewrite r
            join pg_catalog.pg_class c on c.oid = r.ev_class
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind in ('r', 'p')
            order by 1, 2
            SQL) as $row) {
            $definer[] = "table {$row->relname} carries rewrite rule {$row->rulename} (its actions run with the owner's rights)";
        }
        $record('the runtime reaches no owner rights through a SECURITY DEFINER function or a rewrite rule', $definer);

        // 13. Parameter privileges (finding 5).
        $parameters = array_map(fn (object $r): string => "the runtime holds {$r->privilege} on parameter {$r->parname}", $connection->select(<<<'SQL'
            select a.parname, p.privilege
            from pg_catalog.pg_parameter_acl a
            cross join (values ('SET'), ('ALTER SYSTEM')) as p(privilege)
            where pg_catalog.has_parameter_privilege(?, a.parname, p.privilege)
            order by 1, 2
            SQL, [$runtime]));
        $record('the runtime holds no SET or ALTER SYSTEM privilege on any parameter', $parameters);

        // 14. Grant options (finding 8).
        $grantable = array_map(fn (object $r): string => "the runtime holds a grant option ({$r->privilege_type}) on {$r->kind} {$r->name}", $connection->select(<<<'SQL'
            with rt as (select oid from pg_catalog.pg_roles where rolname = ?)
            select 'relation' as kind, c.relname as name, a.privilege_type
              from pg_catalog.pg_class c cross join lateral pg_catalog.aclexplode(c.relacl) a
             where c.relacl is not null and a.is_grantable and a.grantee = (select oid from rt)
            union all
            select 'column', c.relname || '.' || att.attname, a.privilege_type
              from pg_catalog.pg_attribute att join pg_catalog.pg_class c on c.oid = att.attrelid
              cross join lateral pg_catalog.aclexplode(att.attacl) a
             where att.attacl is not null and a.is_grantable and a.grantee = (select oid from rt)
            union all
            select 'function', p.oid::regprocedure::text, a.privilege_type
              from pg_catalog.pg_proc p cross join lateral pg_catalog.aclexplode(p.proacl) a
             where p.proacl is not null and a.is_grantable and a.grantee = (select oid from rt)
            union all
            select 'schema', n.nspname, a.privilege_type
              from pg_catalog.pg_namespace n cross join lateral pg_catalog.aclexplode(n.nspacl) a
             where n.nspacl is not null and a.is_grantable and a.grantee = (select oid from rt)
            union all
            select 'database', d.datname, a.privilege_type
              from pg_catalog.pg_database d cross join lateral pg_catalog.aclexplode(d.datacl) a
             where d.datname = current_database() and d.datacl is not null and a.is_grantable and a.grantee = (select oid from rt)
            order by 1, 2, 3
            SQL, [$runtime]));
        $record('the runtime holds no grant option', $grantable);

        // 15. Default privileges (finding 13).
        $defaults = [];
        foreach ($connection->select(<<<'SQL'
            select d.defaclobjtype as objtype, case when a.grantee = 0 then 'PUBLIC' else 'the runtime' end as who, a.privilege_type, a.is_grantable
            from pg_catalog.pg_default_acl d
            cross join lateral pg_catalog.aclexplode(d.defaclacl) a
            where a.grantee in (0, (select oid from pg_catalog.pg_roles where rolname = ?))
            order by 1, 2, 3
            SQL, [$runtime]) as $row) {
            $allowed = self::ALLOWED_DEFAULTS[(string) $row->objtype] ?? [];
            if ($row->who !== 'the runtime' || (bool) $row->is_grantable || ! in_array($row->privilege_type, $allowed, true)) {
                $defaults[] = "a default privilege gives {$row->who} {$row->privilege_type} on new objects of type {$row->objtype}".((bool) $row->is_grantable ? ' with grant option' : '');
            }
        }
        $record('default privileges give the runtime at most SELECT on new tables and USAGE, SELECT on new sequences', $defaults);

        sort($problems);

        return ['status' => $problems === [] ? self::MATCH : self::DRIFT, 'problems' => array_values(array_unique($problems)), 'passed' => $passed];
    }

    /** search_path is pinned when it names no `$user` and lists pg_temp explicitly and last (finding 9). */
    private static function pinnedSearchPath(string $value): bool
    {
        $parts = array_map(fn (string $p): string => trim($p, " \t\""), explode(',', $value));

        return $parts !== [] && ! in_array('$user', $parts, true) && end($parts) === 'pg_temp' && count(array_keys($parts, 'pg_temp', true)) === 1;
    }

    /** The trigger and table of a pg_get_triggerdef() text, for a message. */
    private static function triggerName(string $definition): string
    {
        return preg_match('/^CREATE (?:CONSTRAINT )?TRIGGER (\S+) .* ON (\S+) /', $definition, $m) === 1 ? "{$m[1]} on {$m[2]}" : 'a trigger';
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
