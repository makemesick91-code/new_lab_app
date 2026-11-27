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

    /** Round 3, finding 2: the only extensions the design uses. */
    public const ALLOWED_EXTENSIONS = ['pg_stat_statements', 'plpgsql'];

    /** Round 3, findings 1 and 9: settings that must keep their safe value in every runtime session. */
    private const SAFE_SETTINGS = ['session_replication_role' => 'origin', 'lo_compat_privileges' => 'off', 'standard_conforming_strings' => 'on'];

    /** Round 4, N4: information_schema relations initdb leaves without an ACL (no PUBLIC SELECT baseline). */
    private const INFORMATION_SCHEMA_WITHOUT_ACL = ['_pg_foreign_data_wrappers', '_pg_foreign_servers', '_pg_foreign_table_columns', '_pg_foreign_tables', '_pg_user_mappings', 'sql_parts', 'transforms'];

    /** Round 3, finding 5: the exact configuration of every guard function (the provisioning value). */
    public const GUARD_FUNCTION_CONFIG = ['search_path=pg_catalog, pg_temp'];

    public function __construct(private readonly RuntimePrivilegeRegistry $registry) {}

    /**
     * The full check, as the runtime itself.
     *
     * @param  string|null  $connectionName  the runtime connection; null = the default connection
     * @return array{status: int, problems: list<string>, passed: list<string>, notes: list<string>}
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
                // Round 4, N2: the session values are read before the session is
                // pinned, so a default the runtime set on itself is still seen.
                $sessionValues = [];
                foreach (array_keys(self::SAFE_SETTINGS) as $setting) {
                    $sessionValues[$setting] = (string) $connection->selectOne('select pg_catalog.current_setting(?) as v', [$setting])->v;
                }
                // Round 4, N1/N2: from here on only pg_catalog is searched, so no
                // overload the owner role creates in `public` can steer these
                // queries, and string literals keep their meaning.
                RuntimeGrantPlan::pinSession($connection);

                return $this->evaluate($connection, (string) $session->ses, $owner, $sessionValues);
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
                   case when c.relkind = 'S' then pg_catalog.has_sequence_privilege(:r1, c.oid, 'USAGE') end as can_use,
                   case when c.relkind = 'S' then pg_catalog.has_sequence_privilege(:r2, c.oid, 'SELECT') end as can_select,
                   case when c.relkind = 'S' then pg_catalog.has_sequence_privilege(:r3, c.oid, 'UPDATE') end as can_update
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

        // Round 2, N8: a sequence outside `public` may not be advanced either.
        foreach ($connection->select(<<<'SQL'
            select n.nspname, c.relname
            from pg_catalog.pg_class c
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where c.relkind = 'S' and n.nspname not in ('public', 'pg_catalog', 'information_schema')
              -- The CASE keeps has_sequence_privilege() off non-sequences: the planner may
              -- evaluate it before the relkind filter (SQLSTATE 42809 otherwise).
              and case when c.relkind = 'S' then pg_catalog.has_sequence_privilege(?, c.oid, 'UPDATE') else false end
            order by 1, 2
            SQL, [$runtime]) as $row) {
            $problems[] = "sequence {$row->nspname}.{$row->relname}: the runtime holds UPDATE (setval)";
        }
        // Round 4, N5: any privilege on a sequence outside `public` that no extension owns.
        foreach ($connection->select(<<<'SQL'
            select n.nspname, c.relname
            from pg_catalog.pg_class c
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where c.relkind = 'S' and n.nspname not in ('public', 'pg_catalog', 'information_schema')
              and not exists (select 1 from pg_catalog.pg_depend d where d.classid = 'pg_catalog.pg_class'::regclass and d.objid = c.oid and d.deptype = 'e')
              and case when c.relkind = 'S' then pg_catalog.has_sequence_privilege(?, c.oid, 'USAGE') or pg_catalog.has_sequence_privilege(?, c.oid, 'SELECT') else false end
            order by 1, 2
            SQL, [$runtime, $runtime]) as $row) {
            $problems[] = "sequence {$row->nspname}.{$row->relname}: the runtime holds a privilege on a sequence outside public";
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
            where n.nspname not in ('pg_catalog', 'information_schema') and not pg_catalog.starts_with(n.nspname, 'pg_toast')
              and not pg_catalog.starts_with(n.nspname, 'pg_temp_')
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
    /** @param  array<string, string>  $sessionValues */
    private function evaluate(ConnectionInterface $connection, string $runtime, string $owner, array $sessionValues): array
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

        $record('the registry agrees with the schema', array_map(fn (string $e): string => "registry: {$e}", $this->registry->verify($connection, 'public')));

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
        $memberships = array_map(
            fn (string $r): string => $r === $owner ? 'the runtime is a member of the owner role' : 'the runtime is a member of '.self::roleLabel($r),
            array_values(array_diff($reached, self::ALLOWED_MEMBERSHIPS))
        );
        // Round 5, F1: every privilege rule asks has_*_privilege(), which counts only
        // INHERITED privileges. A membership the runtime can SET ROLE into without
        // inheriting it would let it use privileges none of the rules sees.
        foreach ($connection->select(<<<'SQL'
            select b.rolname from pg_catalog.pg_roles b
            where b.rolname <> ? and pg_catalog.pg_has_role(?, b.oid, 'SET') and not pg_catalog.pg_has_role(?, b.oid, 'USAGE')
            order by 1
            SQL, [$runtime, $runtime, $runtime]) as $row) {
            $memberships[] = 'the runtime can SET ROLE to '.((string) $row->rolname === $owner ? 'the owner role' : self::roleLabel((string) $row->rolname)).' without inheriting it, so its privileges escape every check';
        }
        $record('the runtime reaches only the monitoring roles, and inherits every role it can SET', $memberships);

        // 3. Ownership, in every database and among shared objects.
        $owned = $connection->selectOne(<<<'SQL'
            select count(*) filter (where d.dbid = (select oid from pg_catalog.pg_database where datname = current_database())) as here,
                   count(*) filter (where d.dbid = 0) as shared,
                   count(*) filter (where d.dbid <> 0 and d.dbid <> (select oid from pg_catalog.pg_database where datname = current_database())) as elsewhere
            from pg_catalog.pg_shdepend d
            where d.refclassid = 'pg_catalog.pg_authid'::regclass and d.deptype = 'o'
              and d.refobjid in (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER'))
            SQL, [$runtime]);
        $ownership = [];
        foreach (['here' => 'in this database', 'shared' => 'among shared objects (databases, tablespaces)', 'elsewhere' => 'in other databases'] as $key => $where) {
            if ((int) $owned->{$key} > 0) {
                $ownership[] = "the runtime or a role it reaches owns {$owned->{$key}} object(s) {$where}";
            }
        }
        // Round 2 (verification run): PostgreSQL records no pg_shdepend row for a
        // built-in (pinned) role, so an object owned by a reached predefined role
        // such as pg_read_all_stats is found only through the owner columns.
        $pinnedOwned = (int) $connection->selectOne(<<<'SQL'
            with reach as (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER') and b.oid < 16384)
            select count(*) as n from (
                select relowner as owner from pg_catalog.pg_class
                union all
                select proowner as owner from pg_catalog.pg_proc
                union all
                select typowner as owner from pg_catalog.pg_type
                union all
                select nspowner as owner from pg_catalog.pg_namespace
                union all
                select lomowner as owner from pg_catalog.pg_largeobject_metadata
                union all
                select extowner as owner from pg_catalog.pg_extension
                union all
                select collowner as owner from pg_catalog.pg_collation
                union all
                select conowner as owner from pg_catalog.pg_conversion
                union all
                select oprowner as owner from pg_catalog.pg_operator
                union all
                select opcowner as owner from pg_catalog.pg_opclass
                union all
                select opfowner as owner from pg_catalog.pg_opfamily
                union all
                select cfgowner as owner from pg_catalog.pg_ts_config
                union all
                select dictowner as owner from pg_catalog.pg_ts_dict
                union all
                select fdwowner as owner from pg_catalog.pg_foreign_data_wrapper
                union all
                select srvowner as owner from pg_catalog.pg_foreign_server
                union all
                select evtowner as owner from pg_catalog.pg_event_trigger
                union all
                select pubowner as owner from pg_catalog.pg_publication
                union all
                select stxowner as owner from pg_catalog.pg_statistic_ext
                union all
                select spcowner as owner from pg_catalog.pg_tablespace
                union all
                select lanowner from pg_catalog.pg_language
                union all
                select subowner from pg_catalog.pg_subscription
                union all
                -- Round 3, finding 6: every database, not only this one.
                select datdba from pg_catalog.pg_database
            ) o
            where o.owner in (select oid from reach)
            SQL, [$runtime])->n;
        if ($pinnedOwned > 0) {
            $ownership[] = "a built-in role the runtime reaches owns {$pinnedOwned} object(s)";
        }
        $record('the runtime, and every role it reaches, owns nothing', $ownership);

        // 4. Class grants, sequences, and no write to any other relation.
        [$entries] = $this->registry->expected();
        $record('every table and sequence grants the runtime exactly its class, and no other relation is writable', self::grantProblems($connection, $runtime, $entries));

        // 5. Creation (finding 9: TEMPORARY too).
        $creates = array_map(fn (object $r): string => "the runtime can CREATE in schema {$r->nspname}", $connection->select(<<<'SQL'
            select n.nspname from pg_catalog.pg_namespace n
            where pg_catalog.has_schema_privilege(?, n.oid, 'CREATE')
              and not pg_catalog.starts_with(n.nspname, 'pg_temp_') and not pg_catalog.starts_with(n.nspname, 'pg_toast_temp_')
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
        // Round 3, finding 7: no other database is reachable at all (PostgreSQL
        // grants CONNECT and TEMPORARY to PUBLIC by default; provisioning revokes
        // them). The databases' names are never printed.
        $others = (int) $connection->selectOne(<<<'SQL'
            select count(*) as n from pg_catalog.pg_database d
            where d.datname <> current_database() and d.datallowconn
              and (pg_catalog.has_database_privilege(?, d.oid, 'CONNECT') or pg_catalog.has_database_privilege(?, d.oid, 'TEMPORARY')
                   or pg_catalog.has_database_privilege(?, d.oid, 'CREATE'))
            SQL, [$runtime, $runtime, $runtime])->n;
        if ($others > 0) {
            $creates[] = "the runtime can connect to or create in {$others} other database(s)";
        }
        $record('the runtime can create no schema object, nothing in the database and no temporary object, and reaches no other database', $creates);

        // 6. N-9.
        $databaseOwnerIsSuperuser = (bool) $connection->selectOne(
            'select r.rolsuper as s from pg_catalog.pg_database d join pg_catalog.pg_roles r on r.oid = d.datdba where d.datname = current_database()'
        )->s;
        $record('the database is owned by a superuser (N-9)', $databaseOwnerIsSuperuser ? [] : ['the database owner is not a superuser (N-9)']);

        // 7 and 11. Trigger functions, trigger states, and exactly the expected guard triggers.
        $functions = [];
        foreach ($connection->select(<<<'SQL'
            select distinct p.oid, p.proname, pg_catalog.pg_get_userbyid(p.proowner) as owner, p.prosecdef,
                   coalesce(array_to_string(p.proconfig, chr(1)), '') as config,
                   encode(pg_catalog.sha256(convert_to(p.prosrc, 'UTF8')), 'hex') as body,
                   (select l.lanname from pg_catalog.pg_language l where l.oid = p.prolang) as language
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
            // Round 3, finding 5: a guard's settings are exactly the provisioning
            // value, so no search_path entry can put a public overload in front of
            // what the body calls; and it stays plpgsql.
            if ($settings !== self::GUARD_FUNCTION_CONFIG) {
                $functions[] = "guard function {$row->proname} settings are not exactly ".implode(' ', self::GUARD_FUNCTION_CONFIG);
            }
            if ($row->language !== 'plpgsql') {
                $functions[] = "guard function {$row->proname} is not plpgsql";
            }
            // Round 2, N4: the body itself is pinned, so a replaced guard that keeps
            // its owner, pin and trigger text is still caught.
            $pinned = (array) config('runtime_privilege_classes.guard_functions', []);
            if (! isset($pinned[$row->proname])) {
                $functions[] = "trigger function {$row->proname} is not a pinned guard function";
            } elseif (! hash_equals((string) $pinned[$row->proname], (string) $row->body)) {
                $functions[] = "guard function {$row->proname} body differs from its pinned SHA-256";
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
            // Under the pinned search_path the trigger function prints qualified;
            // the configured definitions name it as the migrations do.
            $live[str_replace(' EXECUTE FUNCTION public.', ' EXECUTE FUNCTION ', (string) $row->def)] = true;
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

        // 8 and 12. Functions the runtime must not execute. Round 2, N1: the
        // baseline is each function's INITIAL privileges (pg_init_privs, recorded
        // by initdb and by CREATE EXTENSION), not its current ACL, so a restricted
        // built-in granted later to PUBLIC, to a monitoring role or to the runtime
        // is caught however it was granted.
        $guards = [];
        foreach ($connection->select(<<<'SQL'
            with reach as (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER')),
            cur as (
                select p.oid, a.grantee
                from pg_catalog.pg_proc p
                cross join lateral pg_catalog.aclexplode(coalesce(p.proacl, pg_catalog.acldefault('f', p.proowner))) a
                where a.privilege_type = 'EXECUTE'
            ),
            ini as (
                select i.objoid as oid, a.grantee
                from pg_catalog.pg_init_privs i
                cross join lateral pg_catalog.aclexplode(i.initprivs) a
                where i.classoid = 'pg_catalog.pg_proc'::regclass and i.objsubid = 0 and a.privilege_type = 'EXECUTE'
            )
            select distinct c.oid::regprocedure::text as signature,
                   case when c.grantee = 0 then 'PUBLIC' else pg_catalog.pg_get_userbyid(c.grantee) end as grantee
            from cur c
            where exists (select 1 from pg_catalog.pg_init_privs ip where ip.objoid = c.oid and ip.classoid = 'pg_catalog.pg_proc'::regclass and ip.objsubid = 0)
              and (c.grantee = 0 or c.grantee in (select oid from reach))
              and not exists (select 1 from ini where ini.oid = c.oid and ini.grantee = c.grantee)
            order by 1, 2
            SQL, [$runtime]) as $row) {
            $to = $row->grantee === 'PUBLIC' ? 'PUBLIC' : ($row->grantee === $runtime ? 'the runtime' : self::roleLabel((string) $row->grantee));
            $guards[] = "the runtime can EXECUTE {$row->signature} beyond its initial privileges (granted to {$to})";
        }
        foreach ($connection->select(<<<'SQL'
            select p.oid::regprocedure::text as signature
            from pg_catalog.pg_proc p
            where (p.proname = 'pg_stat_statements_reset'
                   or p.oid in ('pg_catalog.lo_export(oid, text)'::regprocedure, 'pg_catalog.lo_import(text)'::regprocedure, 'pg_catalog.lo_import(text, oid)'::regprocedure))
              and pg_catalog.has_function_privilege(?, p.oid, 'EXECUTE')
            order by 1
            SQL, [$runtime]) as $row) {
            $guards[] = "the runtime can EXECUTE {$row->signature}";
        }
        // Round 3, finding 1c: the round-1 current-ACL rule is kept as well, so an
        // erased or forged initial-privilege row does not hide a function whose
        // ACL withholds EXECUTE from PUBLIC yet grants it to the runtime.
        foreach ($connection->select(<<<'SQL'
            select p.oid::regprocedure::text as signature
            from pg_catalog.pg_proc p
            where p.proacl is not null
              and not exists (select 1 from pg_catalog.aclexplode(p.proacl) a
                              where a.privilege_type = 'EXECUTE'
                                and (a.grantee = 0 or a.grantee in (select oid from pg_catalog.pg_roles where rolname in ('pg_read_all_settings', 'pg_read_all_stats'))))
              and pg_catalog.has_function_privilege(?, p.oid, 'EXECUTE')
              and p.proowner <> (select oid from pg_catalog.pg_roles where rolname = ?)
            order by 1
            SQL, [$runtime, $runtime]) as $row) {
            $guards[] = "the runtime can EXECUTE {$row->signature}, which PUBLIC may not";
        }
        // Round 3, finding 2: an aggregate runs its support functions under the
        // aggregate owner's EXECUTE check, so each must be executable by the runtime itself.
        foreach ($connection->select(<<<'SQL'
            select distinct a.aggfnoid::regprocedure::text as aggregate
            from pg_catalog.pg_aggregate a
            cross join lateral unnest(array[a.aggtransfn, a.aggfinalfn, a.aggcombinefn, a.aggserialfn, a.aggdeserialfn,
                                            a.aggmtransfn, a.aggminvtransfn, a.aggmfinalfn]::oid[]) as f(fn)
            where f.fn <> 0
              and pg_catalog.has_function_privilege(?, a.aggfnoid, 'EXECUTE')
              and not pg_catalog.has_function_privilege(?, f.fn, 'EXECUTE')
            order by 1
            SQL, [$runtime, $runtime]) as $row) {
            $guards[] = "aggregate {$row->aggregate} runs a support function the runtime may not execute";
        }
        // Round 3, finding 2: internal or C code added after initdb, outside an extension.
        foreach ($connection->select(<<<'SQL'
            select p.oid::regprocedure::text as signature
            from pg_catalog.pg_proc p join pg_catalog.pg_language l on l.oid = p.prolang
            where l.lanname in ('internal', 'c') and p.oid >= 16384
              and not exists (select 1 from pg_catalog.pg_depend d where d.classid = 'pg_catalog.pg_proc'::regclass and d.objid = p.oid and d.deptype = 'e')
            order by 1
            SQL) as $row) {
            $guards[] = "function {$row->signature} is internal or C code added after initdb, outside an extension";
        }
        $allowed = "'".implode("', '", self::ALLOWED_EXTENSIONS)."'";
        foreach ($connection->select("select extname from pg_catalog.pg_extension where extname not in ({$allowed}) order by 1") as $row) {
            $guards[] = "extension {$row->extname} is not on the allowlist";
        }
        foreach ($connection->select("select lanname from pg_catalog.pg_language where not lanpltrusted and lanname not in ('internal', 'c') order by 1") as $row) {
            $guards[] = "untrusted language {$row->lanname} is installed";
        }
        $record('the runtime can execute no restricted function beyond its initial privileges (statistics reset, server files, other withheld built-ins), and no foreign code is installed', $guards);

        // 9. Triggers are not switched off, and large objects keep their ACL
        // checks, in the runtime's sessions (finding 1; round 3, finding 9).
        $replication = [];
        foreach (self::SAFE_SETTINGS as $setting => $safe) {
            if (($sessionValues[$setting] ?? '') !== $safe) {
                $replication[] = "{$setting} is not {$safe} in the runtime session".($setting === 'session_replication_role' ? ': triggers and rules do not fire' : '');
            }
            foreach ($connection->select(<<<'SQL'
                select case when s.setrole = 0 then 'every role' else 'the runtime role' end as who,
                       case when s.setdatabase = 0 then 'every database' else 'this database' end as scope
                from pg_catalog.pg_db_role_setting s
                cross join lateral pg_catalog.unnest(s.setconfig) as cfg(setting)
                where s.setrole in (0, (select oid from pg_catalog.pg_roles where rolname = ?))
                  and s.setdatabase in (0, (select oid from pg_catalog.pg_database where datname = pg_catalog.current_database()))
                  and pg_catalog.lower(pg_catalog.split_part(cfg.setting, '=', 1)) = ?
                  -- Round 5, F2: a default that sets the safe value is hardening, not drift.
                  and pg_catalog.lower(pg_catalog.btrim(pg_catalog.substr(cfg.setting, pg_catalog.strpos(cfg.setting, '=') + 1), ' "''')) <> ?
                SQL, [$runtime, $setting, $safe]) as $row) {
                $replication[] = "a default sets {$setting} for {$row->who} in {$row->scope}";
            }
        }
        $record('triggers and rules fire, and large-object checks hold, in the runtime session; no default changes either', $replication);

        // 10. Owner rights reachable by the runtime (finding 2).
        // Round 2, N2: EVERY SECURITY DEFINER function, in any schema and whatever
        // its EXECUTE privilege (an aggregate's support function or an event
        // trigger runs it without the runtime calling it). PostgreSQL 16 ships
        // none, and migrations may not create one.
        $definer = array_map(fn (object $r): string => "SECURITY DEFINER function {$r->signature} exists", $connection->select(<<<'SQL'
            select p.oid::regprocedure::text as signature
            from pg_catalog.pg_proc p
            where p.prosecdef
            order by 1
            SQL));
        foreach ($connection->select('select evtname from pg_catalog.pg_event_trigger order by 1') as $row) {
            $definer[] = "event trigger {$row->evtname} exists";
        }
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
        $record('no SECURITY DEFINER function, event trigger or rewrite rule on a table exists', $definer);

        // 17. Reads (round 3, finding 3). Every class includes SELECT, so reading a
        // registry table is by design; reading anything else is not. System
        // relations may be read only as far as their initial privileges allow;
        // no view, materialized view, foreign table, or table outside `public` may
        // be readable unless it belongs to an allowlisted extension.
        $reads = [];
        foreach ($connection->select(<<<'SQL'
            with reach as (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER')),
            cur as (
                select c.oid, 0 as sub, a.grantee, a.privilege_type from pg_catalog.pg_class c
                cross join lateral pg_catalog.aclexplode(coalesce(c.relacl, pg_catalog.acldefault((case when c.relkind = 'S' then 's' else 'r' end)::"char", c.relowner))) a
                where c.relnamespace in ('pg_catalog'::regnamespace, 'information_schema'::regnamespace)
                union all
                select att.attrelid, att.attnum, a.grantee, a.privilege_type from pg_catalog.pg_attribute att
                cross join lateral pg_catalog.aclexplode(att.attacl) a
                where att.attacl is not null and att.attrelid in (select oid from pg_catalog.pg_class where relnamespace in ('pg_catalog'::regnamespace, 'information_schema'::regnamespace))
            ),
            ini as (
                select i.objoid as oid, i.objsubid as sub, a.grantee, a.privilege_type from pg_catalog.pg_init_privs i
                cross join lateral pg_catalog.aclexplode(i.initprivs) a
                where i.classoid = 'pg_catalog.pg_class'::regclass
                union all
                -- initdb creates information_schema after recording initial privileges;
                -- its relations are granted exactly SELECT to PUBLIC, so that is their baseline.
                select c.oid, 0, 0::oid, 'SELECT' from pg_catalog.pg_class c
                where c.relnamespace = 'information_schema'::regnamespace and c.oid < 16384
                  and c.relname <> all (?::name[])
                  and not exists (select 1 from pg_catalog.pg_init_privs i where i.classoid = 'pg_catalog.pg_class'::regclass and i.objoid = c.oid)
            )
            select distinct c.oid::regclass::text as relation, c.sub
            from cur c
            where (c.grantee = 0 or c.grantee in (select oid from reach))
              and not exists (select 1 from ini where ini.oid = c.oid and ini.sub = c.sub and ini.grantee = c.grantee and ini.privilege_type = c.privilege_type)
            order by 1, 2
            SQL, [$runtime, '{'.implode(',', self::INFORMATION_SCHEMA_WITHOUT_ACL).'}']) as $row) {
            $reads[] = "the runtime can reach system relation {$row->relation}".((int) $row->sub > 0 ? ' (a column)' : '').' beyond its initial privileges';
        }
        $allowedExtensions = "'".implode("', '", self::ALLOWED_EXTENSIONS)."'";
        foreach ($connection->select(<<<SQL
            select n.nspname, c.relname, c.relkind
            from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            where n.nspname not in ('pg_catalog', 'information_schema') and not pg_catalog.starts_with(n.nspname, 'pg_toast') and not pg_catalog.starts_with(n.nspname, 'pg_temp_')
              and (c.relkind in ('v', 'm', 'f') or (c.relkind in ('r', 'p') and n.nspname <> 'public'))
              and (pg_catalog.has_table_privilege(?, c.oid, 'SELECT') or pg_catalog.has_any_column_privilege(?, c.oid, 'SELECT'))
              and not exists (
                  select 1 from pg_catalog.pg_depend d join pg_catalog.pg_extension e on e.oid = d.refobjid
                  where d.classid = 'pg_catalog.pg_class'::regclass and d.objid = c.oid and d.deptype = 'e' and e.extname in ({$allowedExtensions}))
            order by 1, 2
            SQL, [$runtime, $runtime]) as $row) {
            $reads[] = "the runtime can read relation {$row->nspname}.{$row->relname} (kind {$row->relkind}) outside the table registry";
        }
        foreach ($connection->select(<<<'SQL'
            select count(*) as n from pg_catalog.pg_largeobject_metadata lo
            cross join lateral pg_catalog.aclexplode(lo.lomacl) a
            where lo.lomacl is not null and (a.grantee = 0 or a.grantee in (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER')))
            SQL, [$runtime]) as $row) {
            if ((int) $row->n > 0) {
                $reads[] = "the runtime or a role it reaches holds a privilege on {$row->n} large object(s)";
            }
        }
        foreach ($connection->select(<<<'SQL'
            select s.srvname from pg_catalog.pg_foreign_server s where pg_catalog.has_server_privilege(?, s.oid, 'USAGE')
            union
            select m.srvname from pg_catalog.pg_user_mappings m where m.umuser = 0 or m.umuser in (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER'))
            order by 1
            SQL, [$runtime, $runtime]) as $row) {
            $reads[] = "the runtime can use foreign server {$row->srvname}";
        }
        $record('the runtime reads nothing outside the registry tables, its initial system privileges and allowlisted extensions', $reads);

        // 18. Exact inventory of `public` (round 4, N1): besides the registry's
        // tables, their sequences and row types, `public` holds only the pinned guard
        // functions; no other function, aggregate, operator, type, cast, conversion,
        // collation, view, materialized view or foreign table, extension members aside.
        $inventory = [];
        $notExtension = "not exists (select 1 from pg_catalog.pg_depend d where d.classid = %s and d.objid = %s and d.deptype = 'e')";
        $guardNames = array_keys((array) config('runtime_privilege_classes.guard_functions', []));
        foreach ($connection->select(
            "select p.oid::regprocedure::text as signature, p.proname, (p.pronargs = 0 and p.prorettype = 'pg_catalog.trigger'::regtype) as is_trigger from pg_catalog.pg_proc p where p.pronamespace = 'public'::regnamespace and "
            .sprintf($notExtension, "'pg_catalog.pg_proc'::regclass", 'p.oid').' order by 1'
        ) as $row) {
            // Round 5, F3: a guard is exempt only as name() returning trigger, not under any signature.
            if (! in_array((string) $row->proname, $guardNames, true) || ! (bool) $row->is_trigger) {
                $inventory[] = "public holds function {$row->signature}, which is not a pinned guard function";
            }
        }
        foreach ([
            'operator' => ['pg_operator', 'oprnamespace', 'o.oid::regoperator::text'],
            'conversion' => ['pg_conversion', 'connamespace', 'o.conname::text'],
            'collation' => ['pg_collation', 'collnamespace', 'o.collname::text'],
            'text search configuration' => ['pg_ts_config', 'cfgnamespace', 'o.cfgname::text'],
            'text search dictionary' => ['pg_ts_dict', 'dictnamespace', 'o.dictname::text'],
            'statistics object' => ['pg_statistic_ext', 'stxnamespace', 'o.stxname::text'],
        ] as $kind => [$catalog, $column, $label]) {
            foreach ($connection->select(
                "select {$label} as name from pg_catalog.{$catalog} o where o.{$column} = 'public'::regnamespace and "
                .sprintf($notExtension, "'pg_catalog.{$catalog}'::regclass", 'o.oid').' order by 1'
            ) as $row) {
                $inventory[] = "public holds {$kind} {$row->name}";
            }
        }
        foreach ($connection->select(
            "select t.typname from pg_catalog.pg_type t where t.typnamespace = 'public'::regnamespace and t.typrelid = 0"
            .' and not exists (select 1 from pg_catalog.pg_type e where e.oid = t.typelem and e.typrelid <> 0) and '
            .sprintf($notExtension, "'pg_catalog.pg_type'::regclass", 't.oid').' order by 1'
        ) as $row) {
            $inventory[] = "public holds type {$row->typname}";
        }
        foreach ($connection->select(
            "select c.relname, c.relkind from pg_catalog.pg_class c where c.relnamespace = 'public'::regnamespace and c.relkind in ('v', 'm', 'f', 'c') and "
            .sprintf($notExtension, "'pg_catalog.pg_class'::regclass", 'c.oid').' order by 1'
        ) as $row) {
            $inventory[] = "public holds relation {$row->relname} (kind {$row->relkind}) outside the table registry";
        }
        $casts = (int) $connection->selectOne(<<<'SQL'
            select count(*) as n from pg_catalog.pg_cast k
            where k.castfunc in (select oid from pg_catalog.pg_proc where pronamespace = 'public'::regnamespace)
               or k.castsource in (select oid from pg_catalog.pg_type where typnamespace = 'public'::regnamespace)
               or k.casttarget in (select oid from pg_catalog.pg_type where typnamespace = 'public'::regnamespace)
            SQL)->n;
        if ($casts > 0) {
            $inventory[] = "public is involved in {$casts} cast(s)";
        }
        $record('public holds only the registry tables, their sequences and row types, and the pinned guard functions', $inventory);

        // 16. Table inheritance (round 2, N3): DML on a parent is checked on the
        // parent only, so it would reach a child of another class.
        $record('no table inherits from another (declared partitions aside)', array_map(
            fn (object $r): string => "table {$r->child} inherits from {$r->parent}",
            $connection->select(<<<'SQL'
                select c.relname as child, p.relname as parent
                from pg_catalog.pg_inherits i
                join pg_catalog.pg_class c on c.oid = i.inhrelid
                join pg_catalog.pg_class p on p.oid = i.inhparent
                where not c.relispartition
                order by 1, 2
                SQL)
        ));

        // 13. Parameter privileges (finding 5).
        $parameters = array_map(fn (object $r): string => "the runtime holds {$r->privilege} on parameter {$r->parname}", $connection->select(<<<'SQL'
            select a.parname, p.privilege
            from pg_catalog.pg_parameter_acl a
            cross join (values ('SET'), ('ALTER SYSTEM')) as p(privilege)
            where pg_catalog.has_parameter_privilege(?, a.parname, p.privilege)
            order by 1, 2
            SQL, [$runtime]));
        $record('the runtime holds no SET or ALTER SYSTEM privilege on any parameter', $parameters);

        // 14. Grant options (finding 8; round 2, N7): for the runtime and every
        // role it reaches, on every ACL-bearing object kind; and ADMIN OPTION on
        // any membership. The database's name is never printed.
        $grantable = array_map(fn (object $r): string => "the runtime or a role it reaches holds a grant option ({$r->privilege_type}) on {$r->kind} {$r->name}", $connection->select(<<<'SQL'
            with reach as (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER'))
            select 'relation' as kind, c.relname as name, a.privilege_type
              from pg_catalog.pg_class c cross join lateral pg_catalog.aclexplode(c.relacl) a
             where c.relacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'function' as kind, p.oid::regprocedure::text as name, a.privilege_type
              from pg_catalog.pg_proc p cross join lateral pg_catalog.aclexplode(p.proacl) a
             where p.proacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'schema' as kind, n.nspname as name, a.privilege_type
              from pg_catalog.pg_namespace n cross join lateral pg_catalog.aclexplode(n.nspacl) a
             where n.nspacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'type' as kind, t.oid::regtype::text as name, a.privilege_type
              from pg_catalog.pg_type t cross join lateral pg_catalog.aclexplode(t.typacl) a
             where t.typacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'language' as kind, l.lanname as name, a.privilege_type
              from pg_catalog.pg_language l cross join lateral pg_catalog.aclexplode(l.lanacl) a
             where l.lanacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'foreign-data wrapper' as kind, w.fdwname as name, a.privilege_type
              from pg_catalog.pg_foreign_data_wrapper w cross join lateral pg_catalog.aclexplode(w.fdwacl) a
             where w.fdwacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'foreign server' as kind, fs.srvname as name, a.privilege_type
              from pg_catalog.pg_foreign_server fs cross join lateral pg_catalog.aclexplode(fs.srvacl) a
             where fs.srvacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'large object' as kind, lo.oid::text as name, a.privilege_type
              from pg_catalog.pg_largeobject_metadata lo cross join lateral pg_catalog.aclexplode(lo.lomacl) a
             where lo.lomacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'parameter' as kind, pa.parname as name, a.privilege_type
              from pg_catalog.pg_parameter_acl pa cross join lateral pg_catalog.aclexplode(pa.paracl) a
             where pa.paracl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'tablespace' as kind, ts.spcname as name, a.privilege_type
              from pg_catalog.pg_tablespace ts cross join lateral pg_catalog.aclexplode(ts.spcacl) a
             where ts.spcacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'column', c.relname || '.' || att.attname, a.privilege_type
              from pg_catalog.pg_attribute att join pg_catalog.pg_class c on c.oid = att.attrelid
              cross join lateral pg_catalog.aclexplode(att.attacl) a
             where att.attacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            union all
            select 'database', 'the database', a.privilege_type
              from pg_catalog.pg_database d cross join lateral pg_catalog.aclexplode(d.datacl) a
             where d.datname = current_database() and d.datacl is not null and a.is_grantable and a.grantee in (select oid from reach)
            order by 1, 2, 3
            SQL, [$runtime]));
        foreach ($connection->select(<<<'SQL'
            select pg_catalog.pg_get_userbyid(m.roleid) as granted
            from pg_catalog.pg_auth_members m
            where m.admin_option and m.member in (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER'))
            order by 1
            SQL, [$runtime]) as $row) {
            $grantable[] = 'the runtime or a role it reaches holds ADMIN OPTION on '.($row->granted === $owner ? 'the owner role' : self::roleLabel((string) $row->granted));
        }
        $record('the runtime, and every role it reaches, holds no grant option and no ADMIN OPTION', $grantable);

        // 15. Default privileges (finding 13).
        $defaults = [];
        foreach ($connection->select(<<<'SQL'
            select d.defaclobjtype as objtype, a.privilege_type, a.is_grantable,
                   case when a.grantee = 0 then 'PUBLIC' else pg_catalog.pg_get_userbyid(a.grantee) end as grantee
            from pg_catalog.pg_default_acl d
            cross join lateral pg_catalog.aclexplode(d.defaclacl) a
            where a.grantee = 0 or a.grantee in (select b.oid from pg_catalog.pg_roles b where pg_catalog.pg_has_role(?, b.oid, 'MEMBER'))
            order by 1, 2, 4
            SQL, [$runtime]) as $row) {
            $row->who = $row->grantee === $runtime ? 'the runtime' : ($row->grantee === 'PUBLIC' ? 'PUBLIC' : self::roleLabel((string) $row->grantee));
            $allowed = self::ALLOWED_DEFAULTS[(string) $row->objtype] ?? [];
            if ($row->who !== 'the runtime' || (bool) $row->is_grantable || ! in_array($row->privilege_type, $allowed, true)) {
                $defaults[] = "a default privilege gives {$row->who} {$row->privilege_type} on new objects of type {$row->objtype}".((bool) $row->is_grantable ? ' with grant option' : '');
            }
        }
        $record('default privileges give the runtime at most SELECT on new tables and USAGE, SELECT on new sequences', $defaults);

        // Round 2, N5 (pre-existing schema, an owner decision): a foreign-key action
        // runs with the referencing table owner's rights, so on a table that is
        // not FULL_DML and has no guard trigger it changes rows the class forbids.
        // Reported as a NOTE: it does not change the verdict until the owner decides.
        $notes = [];
        $restricted = array_keys(array_filter($entries, fn (array $e): bool => $e['class'] !== RuntimePrivilegeClass::FULL_DML));
        if ($restricted !== []) {
            $placeholders = implode(', ', array_fill(0, count($restricted), '?'));
            foreach ($connection->select(<<<SQL
                select c.relname, k.conname, k.confupdtype, k.confdeltype
                from pg_catalog.pg_constraint k
                join pg_catalog.pg_class c on c.oid = k.conrelid
                join pg_catalog.pg_namespace n on n.oid = c.relnamespace
                where k.contype = 'f' and n.nspname = 'public' and c.relname in ({$placeholders})
                  and (k.confupdtype not in ('a', 'r') or k.confdeltype not in ('a', 'r'))
                  and not exists (select 1 from pg_catalog.pg_trigger t where t.tgrelid = c.oid and not t.tgisinternal)
                order by 1, 2
                SQL, $restricted) as $row) {
                $notes[] = "foreign key {$row->conname} on {$row->relname} (not FULL_DML, no guard trigger) has ON UPDATE {$row->confupdtype} / ON DELETE {$row->confdeltype}: the action changes rows with the owner's rights (owner decision pending)";
            }
        }

        $roles = array_values(array_filter(
            array_map(fn (object $r): string => (string) $r->rolname, $connection->select('select rolname from pg_catalog.pg_roles')),
            fn (string $r): bool => ! str_starts_with($r, 'pg_')
        ));
        $problems = array_values(array_unique(array_map(fn (string $p): string => self::withholdRoleNames($p, $roles), $problems)));
        $notes = array_map(fn (string $n): string => self::withholdRoleNames($n, $roles), $notes);
        sort($problems);

        return ['status' => $problems === [] ? self::MATCH : self::DRIFT, 'problems' => $problems, 'passed' => $passed, 'notes' => $notes];
    }

    /**
     * Round 2, N6: an object may be named after a role (CREATE SCHEMA AUTHORIZATION
     * is the usual idiom), so every whole identifier equal to a non-predefined
     * role name is withheld from the output (OD-R6-6).
     *
     * @param  list<string>  $roles
     */
    public static function withholdRoleNames(string $text, array $roles): string
    {
        foreach ($roles as $role) {
            // Round 3, finding 4: case-insensitive and inside longer names too;
            // over-withholding is the accepted price.
            $text = (string) preg_replace('/'.preg_quote($role, '/').'/iu', '<name withheld>', $text);
        }

        return $text;
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
        return ['status' => self::CANNOT_VERIFY, 'problems' => [$reason], 'passed' => [], 'notes' => []];
    }
}
