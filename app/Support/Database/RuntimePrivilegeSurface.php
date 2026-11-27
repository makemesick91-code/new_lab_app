<?php

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B3c (rule 245; pack 63c §1, C1–C4) — the runtime's
 * exact privilege surface, and the exact set it must equal.
 *
 * R6-B3's check is a rule set: it lists known-bad patterns and treats the rest
 * as safe. This class closes the two classes that review round 3 left open: a
 * baseline read from a mutable catalog (pg_init_privs) and a privilege kind
 * nobody wrote a rule for. It enumerates, as the session user, inside the
 * check's pinned read-only transaction, every privilege the login holds
 * directly, through PUBLIC and through every role it reaches (MEMBER: inherited
 * or not, so a SET-only or ADMIN-only membership counts too), on every
 * ACL-bearing catalog of PostgreSQL 16, plus ownership, memberships, elevated
 * attributes, role and database defaults, and default privileges. Each item is
 * one line `kind|object identity|privilege|via role|grantable`:
 * - no OID: a large object is `large object`, a toast table is `toast of <its
 *   table>`, a temporary schema is `pg_temp_N`, another database's object is
 *   `OTHER_DATABASE <catalog>`;
 * - no deployment role name: the session user is RUNTIME, the configured owner
 *   role OWNER, PUBLIC is PUBLIC, OID 10 is BOOTSTRAP_SUPERUSER, a predefined
 *   role keeps its documented `pg_*` name, any other role is OTHER_ROLE;
 * - a database is CURRENT_DATABASE, template0, template1, postgres or
 *   OTHER_DATABASE; a member of an allowlisted extension that lives in that
 *   extension's own schema is named `extension:<name>.<object>`, and so is that
 *   schema, so the placement of pg_stat_statements does not change a line; a
 *   member added in any other schema keeps its real schema (review round 1,
 *   #1: otherwise a member named like a real one reproduces a baseline line);
 * - every field is escaped (`|`, `\`, control and non-ASCII bytes as \xHH), so
 *   an object name can never forge or merge lines; names are quote_ident()ed.
 *
 * The expected set (C2) is the repo-pinned PostgreSQL 16 baseline
 * (tests/Fixtures/r6/pg16-runtime-baseline.txt, SHA-256 pinned in
 * config/runtime_privilege_classes.php and in FoundationGovernanceDbPrivilegeTest)
 * plus what the registry and the rule 245 layout derive: the class grants, the
 * sequences' USAGE and SELECT, the row types and guard functions PostgreSQL
 * grants to PUBLIC by default, USAGE on `public`, CONNECT on the application
 * database and the two monitoring memberships with INHERIT. A small, closed set
 * of PERMITTED lines may be present or absent; each one is a PostgreSQL default
 * that duplicates a grant the runtime holds anyway, an inert grant, a
 * restriction, or the default privileges R6-B3 already bounds (see permitted()).
 *
 * Not claimed (C6): a superuser can still edit any catalog this reads; the
 * comparison proves the surface at the time of the check.
 */
final class RuntimePrivilegeSurface
{
    /** PostgreSQL's FirstNormalObjectId: every object initdb creates has a lower OID. */
    public const FIRST_NORMAL_OBJECT_ID = 16384;

    public const SERVER_MAJOR = 16;

    public const RUNTIME = 'RUNTIME';

    public const OWNER = 'OWNER';

    public const PUBLIC = 'PUBLIC';

    public const BOOTSTRAP = 'BOOTSTRAP_SUPERUSER';

    public const OTHER_ROLE = 'OTHER_ROLE';

    /** Kinds the deployment layout governs, never PostgreSQL's baseline. */
    public const LAYOUT_KINDS = ['database', 'default privilege', 'membership', 'object in another database', 'ownership', 'role attribute', 'role setting'];

    /** At most this many differences per direction become problem lines; the JSON lists them all. */
    public const PRINTED_DIFFERENCES = 50;

    public function __construct(private readonly RuntimePrivilegeRegistry $registry) {}

    /**
     * C1: the session user's surface, sorted bytewise and unique. Must run inside
     * a transaction pinned by RuntimeGrantPlan::pinSession().
     *
     * @return list<string>
     */
    public static function enumerate(ConnectionInterface $connection, ?string $owner): array
    {
        $tokens = self::roleTokens($connection, $owner);
        $allowed = "'".implode("', '", DatabasePrivilegeCheck::ALLOWED_EXTENSIONS)."'";
        $first = self::FIRST_NORMAL_OBJECT_ID;

        $rows = $connection->select(<<<SQL
            with
            reach as (select r.oid from pg_catalog.pg_roles r where pg_catalog.pg_has_role(session_user, r.oid, 'MEMBER')),
            grantees as (select 0::pg_catalog.oid as oid union all select oid from reach),
            curdb as (select d.oid from pg_catalog.pg_database d where d.datname = pg_catalog.current_database()),
            dbs as (
                select d.oid, case when d.oid = (select oid from curdb) then 'CURRENT_DATABASE'
                                   when d.oid < {$first} and d.datname in ('template0', 'template1', 'postgres') then d.datname::pg_catalog.text
                                   else 'OTHER_DATABASE' end as token
                from pg_catalog.pg_database d
            ),
            member as (
                -- Review round 1, #1: a member is renamed only in its extension's own
                -- schema (the joins below compare it), so a member added elsewhere under
                -- a member's name keeps its real schema and cannot reproduce a baseline line.
                select d.classid, d.objid, e.extname::pg_catalog.text as extname, e.extnamespace
                from pg_catalog.pg_depend d join pg_catalog.pg_extension e on e.oid = d.refobjid
                where d.refclassid = 'pg_catalog.pg_extension'::pg_catalog.regclass and d.deptype = 'e' and e.extname in ({$allowed})
            ),
            extnsp as (
                select e.extnamespace as oid, pg_catalog.min(e.extname::pg_catalog.text) as extname
                from pg_catalog.pg_extension e
                where e.extname in ({$allowed}) and e.extnamespace not in ('pg_catalog'::pg_catalog.regnamespace, 'public'::pg_catalog.regnamespace)
                group by 1
            ),
            rel as (
                select c.oid, c.relkind, c.relacl, c.relowner, c.reltoastrelid,
                       coalesce('extension:' || m.extname, pg_catalog.quote_ident(n.nspname)) || '.' || pg_catalog.quote_ident(c.relname) as ident
                from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace
                left join member m on m.classid = 'pg_catalog.pg_class'::pg_catalog.regclass and m.objid = c.oid and m.extnamespace = c.relnamespace
            ),
            own as (
                select 'pg_catalog.pg_class'::pg_catalog.regclass as cls, oid, relowner as owner from pg_catalog.pg_class
                union all select 'pg_catalog.pg_proc'::pg_catalog.regclass, oid, proowner from pg_catalog.pg_proc
                union all select 'pg_catalog.pg_type'::pg_catalog.regclass, oid, typowner from pg_catalog.pg_type
                union all select 'pg_catalog.pg_namespace'::pg_catalog.regclass, oid, nspowner from pg_catalog.pg_namespace
                union all select 'pg_catalog.pg_largeobject'::pg_catalog.regclass, oid, lomowner from pg_catalog.pg_largeobject_metadata
                union all select 'pg_catalog.pg_extension'::pg_catalog.regclass, oid, extowner from pg_catalog.pg_extension
                union all select 'pg_catalog.pg_collation'::pg_catalog.regclass, oid, collowner from pg_catalog.pg_collation
                union all select 'pg_catalog.pg_conversion'::pg_catalog.regclass, oid, conowner from pg_catalog.pg_conversion
                union all select 'pg_catalog.pg_operator'::pg_catalog.regclass, oid, oprowner from pg_catalog.pg_operator
                union all select 'pg_catalog.pg_opclass'::pg_catalog.regclass, oid, opcowner from pg_catalog.pg_opclass
                union all select 'pg_catalog.pg_opfamily'::pg_catalog.regclass, oid, opfowner from pg_catalog.pg_opfamily
                union all select 'pg_catalog.pg_ts_config'::pg_catalog.regclass, oid, cfgowner from pg_catalog.pg_ts_config
                union all select 'pg_catalog.pg_ts_dict'::pg_catalog.regclass, oid, dictowner from pg_catalog.pg_ts_dict
                union all select 'pg_catalog.pg_foreign_data_wrapper'::pg_catalog.regclass, oid, fdwowner from pg_catalog.pg_foreign_data_wrapper
                union all select 'pg_catalog.pg_foreign_server'::pg_catalog.regclass, oid, srvowner from pg_catalog.pg_foreign_server
                union all select 'pg_catalog.pg_event_trigger'::pg_catalog.regclass, oid, evtowner from pg_catalog.pg_event_trigger
                union all select 'pg_catalog.pg_publication'::pg_catalog.regclass, oid, pubowner from pg_catalog.pg_publication
                union all select 'pg_catalog.pg_statistic_ext'::pg_catalog.regclass, oid, stxowner from pg_catalog.pg_statistic_ext
                union all select 'pg_catalog.pg_language'::pg_catalog.regclass, oid, lanowner from pg_catalog.pg_language
                union all select 'pg_catalog.pg_default_acl'::pg_catalog.regclass, oid, defaclrole from pg_catalog.pg_default_acl
                -- Shared catalogs: every database, tablespace and subscription of the cluster.
                union all select 'pg_catalog.pg_database'::pg_catalog.regclass, oid, datdba from pg_catalog.pg_database
                union all select 'pg_catalog.pg_tablespace'::pg_catalog.regclass, oid, spcowner from pg_catalog.pg_tablespace
                union all select 'pg_catalog.pg_subscription'::pg_catalog.regclass, oid, subowner from pg_catalog.pg_subscription
            )
            -- Relations, every privilege kind (tables, views, materialized views, foreign tables, sequences, ...).
            select case r.relkind when 'r' then 'table' when 'p' then 'partitioned table' when 'v' then 'view' when 'm' then 'materialized view'
                                  when 'f' then 'foreign table' when 'S' then 'sequence' when 'i' then 'index' when 'I' then 'partitioned index'
                                  when 't' then 'toast table' when 'c' then 'composite type' else 'relation' end as kind,
                   case when r.relkind = 't' then coalesce((select 'toast of ' || p.ident from rel p where p.reltoastrelid = r.oid), r.ident) else r.ident end as ident,
                   a.privilege_type as privilege, a.grantee, a.is_grantable as grantable, null::pg_catalog.oid as role
            from rel r cross join lateral pg_catalog.aclexplode(coalesce(r.relacl, pg_catalog.acldefault((case when r.relkind = 'S' then 's' else 'r' end)::pg_catalog."char", r.relowner))) a
            where a.grantee in (select oid from grantees)
            union all
            -- Columns.
            select 'column', r.ident || '.' || pg_catalog.quote_ident(att.attname), a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_attribute att join rel r on r.oid = att.attrelid
            cross join lateral pg_catalog.aclexplode(att.attacl) a
            where att.attacl is not null and not att.attisdropped and a.grantee in (select oid from grantees)
            union all
            -- Functions, aggregates, procedures (EXECUTE).
            select case p.prokind when 'a' then 'aggregate' when 'p' then 'procedure' when 'w' then 'window function' else 'function' end,
                   coalesce('extension:' || m.extname, pg_catalog.quote_ident(n.nspname)) || '.' || pg_catalog.quote_ident(p.proname) || '('
                   || coalesce((select pg_catalog.string_agg(pg_catalog.format_type(t.typ, null), ',' order by t.ord)
                                from pg_catalog.unnest(p.proargtypes::pg_catalog.oid[]) with ordinality as t(typ, ord)), '') || ')',
                   a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_proc p join pg_catalog.pg_namespace n on n.oid = p.pronamespace
            left join member m on m.classid = 'pg_catalog.pg_proc'::pg_catalog.regclass and m.objid = p.oid and m.extnamespace = p.pronamespace
            cross join lateral pg_catalog.aclexplode(coalesce(p.proacl, pg_catalog.acldefault('f', p.proowner))) a
            where a.grantee in (select oid from grantees)
            union all
            -- Types. A true array and a multirange take their privileges from their
            -- element or range type (PostgreSQL refuses a GRANT on them), so they are not listed.
            select 'type', coalesce('extension:' || coalesce(mt.extname, mr.extname), pg_catalog.quote_ident(n.nspname)) || '.' || pg_catalog.quote_ident(t.typname),
                   a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_type t join pg_catalog.pg_namespace n on n.oid = t.typnamespace
            left join member mt on mt.classid = 'pg_catalog.pg_type'::pg_catalog.regclass and mt.objid = t.oid and mt.extnamespace = t.typnamespace
            left join member mr on t.typrelid <> 0 and mr.classid = 'pg_catalog.pg_class'::pg_catalog.regclass and mr.objid = t.typrelid and mr.extnamespace = t.typnamespace
            cross join lateral pg_catalog.aclexplode(coalesce(t.typacl, pg_catalog.acldefault('T', t.typowner))) a
            where not (t.typelem <> 0 and t.typsubscript = 'pg_catalog.array_subscript_handler'::pg_catalog.regproc) and t.typtype <> 'm'
              and a.grantee in (select oid from grantees)
            union all
            select 'schema', coalesce('extension:' || en.extname, pg_catalog.quote_ident(n.nspname)), a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_namespace n left join extnsp en on en.oid = n.oid
            cross join lateral pg_catalog.aclexplode(coalesce(n.nspacl, pg_catalog.acldefault('n', n.nspowner))) a
            where a.grantee in (select oid from grantees)
            union all
            -- Every database of the cluster.
            select 'database', b.token, a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_database d join dbs b on b.oid = d.oid
            cross join lateral pg_catalog.aclexplode(coalesce(d.datacl, pg_catalog.acldefault('d', d.datdba))) a
            where a.grantee in (select oid from grantees)
            union all
            select 'language', pg_catalog.quote_ident(l.lanname), a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_language l cross join lateral pg_catalog.aclexplode(coalesce(l.lanacl, pg_catalog.acldefault('l', l.lanowner))) a
            where a.grantee in (select oid from grantees)
            union all
            select 'foreign-data wrapper', pg_catalog.quote_ident(w.fdwname), a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_foreign_data_wrapper w cross join lateral pg_catalog.aclexplode(coalesce(w.fdwacl, pg_catalog.acldefault('F', w.fdwowner))) a
            where a.grantee in (select oid from grantees)
            union all
            select 'foreign server', pg_catalog.quote_ident(s.srvname), a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_foreign_server s cross join lateral pg_catalog.aclexplode(coalesce(s.srvacl, pg_catalog.acldefault('S', s.srvowner))) a
            where a.grantee in (select oid from grantees)
            union all
            select 'user mapping', pg_catalog.quote_ident(m.srvname), 'MAPPED', m.umuser, false, null
            from pg_catalog.pg_user_mappings m where m.umuser in (select oid from grantees)
            union all
            select 'large object', 'large object', a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_largeobject_metadata lo cross join lateral pg_catalog.aclexplode(coalesce(lo.lomacl, pg_catalog.acldefault('L', lo.lomowner))) a
            where a.grantee in (select oid from grantees)
            union all
            select 'parameter', pa.parname::pg_catalog.text, a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_parameter_acl pa cross join lateral pg_catalog.aclexplode(coalesce(pa.paracl, pg_catalog.acldefault('p', 10::pg_catalog.oid))) a
            where a.grantee in (select oid from grantees)
            union all
            select 'tablespace', pg_catalog.quote_ident(s.spcname), a.privilege_type, a.grantee, a.is_grantable, null
            from pg_catalog.pg_tablespace s cross join lateral pg_catalog.aclexplode(coalesce(s.spcacl, pg_catalog.acldefault('t', s.spcowner))) a
            where a.grantee in (select oid from grantees)
            union all
            -- Default privileges.
            select 'default privilege',
                   '{role} ' || case when d.defaclnamespace = 0 then 'in every schema' else 'in schema ' || coalesce('extension:' || en.extname, pg_catalog.quote_ident(n.nspname)) end
                   || ': ' || case d.defaclobjtype when 'r' then 'tables' when 'S' then 'sequences' when 'f' then 'functions' when 'T' then 'types' when 'n' then 'schemas' else d.defaclobjtype::pg_catalog.text end,
                   a.privilege_type, a.grantee, a.is_grantable, d.defaclrole
            from pg_catalog.pg_default_acl d
            left join pg_catalog.pg_namespace n on n.oid = d.defaclnamespace
            left join extnsp en on en.oid = d.defaclnamespace
            cross join lateral pg_catalog.aclexplode(d.defaclacl) a
            where a.grantee in (select oid from grantees)
            union all
            -- Memberships of the login and of every role it reaches, with their options.
            select 'membership', '{role}', v.privilege, m.member, m.admin_option, m.roleid
            from pg_catalog.pg_auth_members m
            cross join lateral (values ('MEMBER', true), ('INHERIT', m.inherit_option), ('SET', m.set_option)) as v(privilege, held)
            where v.held and m.member in (select oid from reach)
            union all
            select 'role attribute', '{role}', v.attribute, r.oid, false, r.oid
            from pg_catalog.pg_roles r
            cross join lateral (values ('SUPERUSER', r.rolsuper), ('CREATEROLE', r.rolcreaterole), ('CREATEDB', r.rolcreatedb),
                                       ('REPLICATION', r.rolreplication), ('BYPASSRLS', r.rolbypassrls)) as v(attribute, held)
            where v.held and r.oid in (select oid from reach)
            union all
            -- Role and database defaults that apply to the login's sessions here.
            select 'role setting', case when s.setdatabase = 0 then 'ANY_DATABASE' else 'CURRENT_DATABASE' end, cfg.setting, s.setrole, false, null
            from pg_catalog.pg_db_role_setting s cross join lateral pg_catalog.unnest(s.setconfig) as cfg(setting)
            where s.setrole in (0, (select r.oid from pg_catalog.pg_roles r where r.rolname = session_user))
              and s.setdatabase in (0, (select oid from curdb))
            union all
            -- Ownership: every owner column, shared catalogs included (a predefined
            -- role has no pg_shdepend row) ...
            select 'ownership',
                   case when o.cls = 'pg_catalog.pg_database'::pg_catalog.regclass then 'database ' || (select b.token from dbs b where b.oid = o.oid)
                        when o.cls = 'pg_catalog.pg_largeobject'::pg_catalog.regclass then 'large object'
                        else (select i.type || ' ' || i.identity from pg_catalog.pg_identify_object(o.cls, o.oid, 0) i) end,
                   'OWNER', o.owner, false, null
            from own o where o.owner in (select oid from reach)
            union all
            -- ... and pg_shdepend, here and among shared objects ...
            select 'ownership',
                   case when d.classid = 'pg_catalog.pg_database'::pg_catalog.regclass then 'database ' || (select b.token from dbs b where b.oid = d.objid)
                        when d.classid = 'pg_catalog.pg_largeobject'::pg_catalog.regclass then 'large object'
                        else (select i.type || ' ' || i.identity from pg_catalog.pg_identify_object(d.classid, d.objid, d.objsubid) i) end,
                   'OWNER', d.refobjid, false, null
            from pg_catalog.pg_shdepend d
            where d.refclassid = 'pg_catalog.pg_authid'::pg_catalog.regclass and d.deptype = 'o'
              and d.dbid in (0, (select oid from curdb)) and d.refobjid in (select oid from reach)
            union all
            -- ... and in every other database, whose catalogs this session cannot read.
            select 'object in another database', 'OTHER_DATABASE ' || d.classid::pg_catalog.regclass::pg_catalog.text,
                   case d.deptype when 'o' then 'OWNER' when 'a' then 'PRIVILEGE' when 'r' then 'POLICY' else 'DEPENDENCY' end, d.refobjid, false, null
            from pg_catalog.pg_shdepend d
            where d.refclassid = 'pg_catalog.pg_authid'::pg_catalog.regclass and d.dbid <> 0
              and d.dbid <> (select oid from curdb) and d.refobjid in (select oid from reach)
            SQL);

        $lines = [];
        foreach ($rows as $row) {
            $identity = (string) $row->ident;
            if ($row->role !== null) {
                $identity = preg_replace('/\A\{role\}/', $tokens[(int) $row->role] ?? self::OTHER_ROLE, $identity, 1) ?? $identity;
            }
            $privilege = (string) $row->privilege;
            if ($row->kind === 'role setting') {
                $privilege = 'SET '.self::canonicalSetting($privilege);
            }
            $lines[] = self::line((string) $row->kind, self::withoutOids($identity), $privilege, $tokens[(int) $row->grantee] ?? self::OTHER_ROLE, (bool) $row->grantable);
        }
        $lines = array_values(array_unique($lines));
        sort($lines, SORT_STRING);

        return $lines;
    }

    /**
     * C2: the expected set. `required` must all be present; `permitted` may be.
     *
     * @param  list<string>  $baseline
     * @return array{required: list<string>, permitted: list<string>}
     */
    public function expected(ConnectionInterface $connection, array $baseline): array
    {
        [$entries] = $this->registry->expected();
        $guards = array_map('strval', array_keys((array) config('runtime_privilege_classes.guard_functions', [])));
        $sequences = array_map(
            fn (object $row): string => (string) $row->relname,
            $connection->select("select c.relname from pg_catalog.pg_class c where c.relnamespace = 'public'::pg_catalog.regnamespace and c.relkind = 'S' order by 1")
        );
        $names = ['public'];
        foreach ($entries as $table => $entry) {
            $names[] = (string) $table;
            array_push($names, ...array_map('strval', $entry['columns']));
        }
        array_push($names, ...$sequences, ...$guards);
        $quoted = [];
        foreach ($connection->select(
            'select n.name, pg_catalog.quote_ident(n.name) as quoted from pg_catalog.json_array_elements_text(?::pg_catalog.json) as n(name)',
            [json_encode(array_values(array_unique($names)), JSON_THROW_ON_ERROR)]
        ) as $row) {
            $quoted[(string) $row->name] = (string) $row->quoted;
        }
        $relkinds = [];
        foreach ($connection->select(
            "select c.relname, c.relkind from pg_catalog.pg_class c where c.relnamespace = 'public'::pg_catalog.regnamespace and c.relkind in ('r', 'p')"
        ) as $row) {
            $relkinds[(string) $row->relname] = (string) $row->relkind;
        }
        $q = fn (string $name): string => $quoted[$name] ?? $name;
        $public = $q('public');

        $required = $baseline;
        foreach ($entries as $table => $entry) {
            $table = (string) $table;
            $object = $public.'.'.$q($table);
            $kind = ($relkinds[$table] ?? 'r') === 'p' ? 'partitioned table' : 'table';
            foreach (RuntimeGrantPlan::TABLE_PRIVILEGES[$entry['class']] ?? [] as $privilege) {
                $required[] = self::line($kind, $object, $privilege, self::RUNTIME, false);
            }
            if ($entry['class'] === RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS) {
                foreach ($entry['columns'] as $column) {
                    $required[] = self::line('column', $object.'.'.$q((string) $column), 'UPDATE', self::RUNTIME, false);
                }
            }
            // A table's row type: PostgreSQL's default ACL gives PUBLIC USAGE.
            $required[] = self::line('type', $object, 'USAGE', self::PUBLIC, false);
        }
        foreach ($sequences as $sequence) {
            foreach (RuntimeGrantPlan::SEQUENCE_PRIVILEGES as $privilege) {
                $required[] = self::line('sequence', $public.'.'.$q($sequence), $privilege, self::RUNTIME, false);
            }
        }
        // A guard function's default ACL gives PUBLIC EXECUTE; a trigger function cannot be called directly.
        foreach ($guards as $guard) {
            $required[] = self::line('function', $public.'.'.$q($guard).'()', 'EXECUTE', self::PUBLIC, false);
        }
        // D-Q1: the two monitoring roles, inherited.
        foreach (DatabasePrivilegeCheck::ALLOWED_MEMBERSHIPS as $role) {
            $required[] = self::line('membership', $role, 'MEMBER', self::RUNTIME, false);
            $required[] = self::line('membership', $role, 'INHERIT', self::RUNTIME, false);
        }
        $required[] = self::line('database', 'CURRENT_DATABASE', 'CONNECT', self::RUNTIME, false);
        $required[] = self::line('schema', $public, 'USAGE', self::RUNTIME, false);

        $required = array_values(array_unique($required));
        sort($required, SORT_STRING);

        return ['required' => $required, 'permitted' => self::permitted($public)];
    }

    /**
     * The closed set of lines that may be present or absent, each a reviewed
     * acceptance of R6-B3 that changes nothing the runtime can do:
     * - SET on a monitoring membership (round 6: INHERIT TRUE, SET optional);
     * - PUBLIC CONNECT on the application database and PUBLIC USAGE on `public`
     *   (PostgreSQL defaults that duplicate the runtime's own grants);
     * - PUBLIC CONNECT on template0, which accepts no connection (check 5 still
     *   reads datallowconn);
     * - USAGE on an allowlisted extension's own schema (pg_stat_statements may
     *   live in `public` or in its own schema);
     * - a role or database default that pins a protected setting to its safe
     *   value (round 5, F2);
     * - the owner role's default privileges within the floor every class has
     *   (check 15: SELECT on new tables; USAGE, SELECT on new sequences).
     *
     * @return list<string>
     */
    public static function permitted(string $public = 'public'): array
    {
        $permitted = [];
        foreach (DatabasePrivilegeCheck::ALLOWED_MEMBERSHIPS as $role) {
            $permitted[] = self::line('membership', $role, 'SET', self::RUNTIME, false);
        }
        $permitted[] = self::line('database', 'CURRENT_DATABASE', 'CONNECT', self::PUBLIC, false);
        $permitted[] = self::line('database', 'template0', 'CONNECT', self::PUBLIC, false);
        $permitted[] = self::line('schema', $public, 'USAGE', self::PUBLIC, false);
        // plpgsql always lives in pg_catalog, so only pg_stat_statements can have a schema of its own.
        $permitted[] = self::line('schema', 'extension:pg_stat_statements', 'USAGE', self::RUNTIME, false);
        foreach (DatabasePrivilegeCheck::SAFE_SETTINGS as $setting => $safe) {
            foreach (['ANY_DATABASE', 'CURRENT_DATABASE'] as $scope) {
                foreach ([self::RUNTIME, self::PUBLIC] as $who) {
                    $permitted[] = self::line('role setting', $scope, "SET {$setting}={$safe}", $who, false);
                }
            }
        }
        foreach (["in schema {$public}", 'in every schema'] as $scope) {
            $permitted[] = self::line('default privilege', self::OWNER." {$scope}: tables", 'SELECT', self::RUNTIME, false);
            $permitted[] = self::line('default privilege', self::OWNER." {$scope}: sequences", 'USAGE', self::RUNTIME, false);
            $permitted[] = self::line('default privilege', self::OWNER." {$scope}: sequences", 'SELECT', self::RUNTIME, false);
        }
        sort($permitted, SORT_STRING);

        return $permitted;
    }

    /**
     * C4: what the surface holds beyond the expected set, and what it lacks.
     *
     * @param  list<string>  $surface
     * @param  list<string>  $required
     * @param  list<string>  $permitted
     * @return array{extra: list<string>, missing: list<string>}
     */
    public static function compare(array $surface, array $required, array $permitted): array
    {
        $have = array_fill_keys($surface, true);
        $need = array_fill_keys($required, true);
        $may = array_fill_keys($permitted, true);

        $extra = array_values(array_filter($surface, fn (string $line): bool => ! isset($need[$line]) && ! isset($may[$line])));
        $missing = array_values(array_filter($required, fn (string $line): bool => ! isset($have[$line])));
        sort($extra, SORT_STRING);
        sort($missing, SORT_STRING);

        return ['extra' => array_values(array_unique($extra)), 'missing' => array_values(array_unique($missing))];
    }

    /**
     * The committed baseline lines, after its SHA-256 is checked against the pin.
     *
     * @return array{lines: list<string>, sha256: string}
     */
    public static function baseline(): array
    {
        $relative = (string) config('runtime_privilege_classes.baseline.path', '');
        $pinned = strtolower((string) config('runtime_privilege_classes.baseline.sha256', ''));
        $path = base_path($relative);
        if ($relative === '' || ! is_file($path)) {
            throw new RuntimeException('the PostgreSQL 16 baseline fixture is missing');
        }
        $text = (string) file_get_contents($path);
        $sha256 = hash('sha256', $text);
        if ($pinned === '' || ! hash_equals($pinned, $sha256)) {
            throw new RuntimeException('the PostgreSQL 16 baseline fixture does not match its pinned SHA-256');
        }

        return ['lines' => self::documentLines($text), 'sha256' => $sha256];
    }

    /** @return list<string> the item lines of a baseline document (comments and blank lines dropped) */
    public static function documentLines(string $text): array
    {
        return array_values(array_filter(explode("\n", $text), fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
    }

    /**
     * C3: the baseline document of a fresh database, as the session user (a
     * plain login holding exactly the two monitoring roles). Refuses a database
     * that is visibly not fresh: an object initdb or an allowlisted extension did
     * not create, a privilege that differs from its initial privileges, a
     * parameter ACL, a default. Must run inside a pinned transaction.
     *
     * @return array{text: ?string, problems: list<string>}
     */
    public static function baselineDocument(ConnectionInterface $connection): array
    {
        $problems = self::freshnessProblems($connection);
        if ($problems !== []) {
            return ['text' => null, 'problems' => $problems];
        }

        $lines = array_values(array_filter(self::enumerate($connection, null), function (string $line): bool {
            [$kind, $identity] = explode('|', $line, 3);

            return ! in_array($kind, self::LAYOUT_KINDS, true)
                && ! ($kind === 'schema' && ($identity === 'public' || str_starts_with($identity, 'extension:')));
        }));

        // The document is committed to a public repository: a line that names any
        // non-predefined role of this cluster refuses the generation instead of being
        // withheld. A plain role name must equal a whole identifier of the line (so a
        // bootstrap superuser called postgres does not collide with the built-in
        // postgresql_fdw_validator); any other role name may not occur at all.
        $roles = array_values(array_filter(
            array_map(fn (object $r): string => (string) $r->rolname, $connection->select('select rolname from pg_catalog.pg_roles')),
            fn (string $r): bool => ! str_starts_with($r, 'pg_')
        ));
        foreach ($lines as $line) {
            preg_match_all('/[A-Za-z0-9_$]+/', $line, $words);
            $identifiers = array_fill_keys(array_map('strtolower', $words[0]), true);
            foreach ($roles as $role) {
                // Review round 1, #4: a name is also looked for in its escaped and quoted forms.
                $named = preg_match('/\A[A-Za-z0-9_$]+\z/', $role) === 1
                    ? isset($identifiers[strtolower($role)])
                    : array_filter(self::roleForms($role), fn (string $form): bool => stripos($line, $form) !== false) !== [];
                if ($named) {
                    return ['text' => null, 'problems' => ['a baseline line names a role of this cluster; generate the baseline in a fresh cluster with other role names']];
                }
            }
        }

        $version = (int) $connection->selectOne("select pg_catalog.current_setting('server_version_num') as v")->v;
        $extensions = implode(', ', array_map(
            fn (object $r): string => "{$r->extname} {$r->extversion}",
            $connection->select('select extname, extversion from pg_catalog.pg_extension order by 1')
        ));
        $header = [
            '# R6-B3c PostgreSQL 16 runtime privilege baseline (DMS-SPN4-READY-PILOT-1, rule 245, pack 63c C2/C3).',
            '# Generated by `php artisan foundation:db-privilege-check --print-baseline`, connected as a plain login that holds',
            '# pg_read_all_settings and pg_read_all_stats WITH INHERIT TRUE, in a fresh database of a fresh initdb cluster.',
            // Review round 1, #2: the major only, so the pinned bytes do not depend on the 16.x minor
            // (catalog contents are fixed per major); the generator's exact minor goes into the evidence.
            '# Server: PostgreSQL '.intdiv($version, 10000),
            "# Extensions: {$extensions}",
            '# Excluded (governed by the deployment layout): '.implode(', ', self::LAYOUT_KINDS).', and the public and extension schemas.',
            '# Format: kind|object identity|privilege|via role|grantable; sorted bytewise; no OID; role names replaced by tokens.',
            '# Items: '.count($lines),
        ];

        return ['text' => implode("\n", [...$header, ...$lines])."\n", 'problems' => []];
    }

    /** @return list<string> */
    private static function freshnessProblems(ConnectionInterface $connection): array
    {
        $problems = [];
        $first = self::FIRST_NORMAL_OBJECT_ID;
        $role = $connection->selectOne('select rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls from pg_catalog.pg_roles where rolname = session_user');
        foreach (['rolsuper', 'rolcreaterole', 'rolcreatedb', 'rolreplication', 'rolbypassrls'] as $attribute) {
            if ($role === null || (bool) $role->{$attribute}) {
                $problems[] = 'the login is not a plain login (it holds an elevated attribute)';
                break;
            }
        }
        $reached = array_map(fn (object $r): string => (string) $r->rolname, $connection->select(
            "select b.rolname from pg_catalog.pg_roles b where b.rolname <> session_user and pg_catalog.pg_has_role(session_user, b.oid, 'MEMBER') order by 1"
        ));
        $inherited = array_map(fn (object $r): string => (string) $r->rolname, $connection->select(<<<'SQL'
            select distinct b.rolname from pg_catalog.pg_auth_members m join pg_catalog.pg_roles b on b.oid = m.roleid
            where m.member = (select oid from pg_catalog.pg_roles where rolname = session_user) and m.inherit_option and not m.admin_option
            order by 1
            SQL));
        $monitoring = DatabasePrivilegeCheck::ALLOWED_MEMBERSHIPS;
        sort($monitoring);
        if ($reached !== $monitoring || $inherited !== $monitoring) {
            $problems[] = 'the login must hold exactly pg_read_all_settings and pg_read_all_stats, WITH INHERIT TRUE and no ADMIN OPTION';
        }
        $extensions = array_map(fn (object $r): string => (string) $r->extname, $connection->select('select extname from pg_catalog.pg_extension order by 1'));
        $allowed = DatabasePrivilegeCheck::ALLOWED_EXTENSIONS;
        sort($allowed);
        if ($extensions !== $allowed) {
            $problems[] = 'the database must hold exactly the allowlisted extensions ('.implode(', ', $allowed).')';
        }
        $allowedList = "'".implode("', '", DatabasePrivilegeCheck::ALLOWED_EXTENSIONS)."'";
        $leftovers = (int) $connection->selectOne(<<<SQL
            with member as (
                select d.classid, d.objid from pg_catalog.pg_depend d join pg_catalog.pg_extension e on e.oid = d.refobjid
                where d.refclassid = 'pg_catalog.pg_extension'::pg_catalog.regclass and d.deptype = 'e' and e.extname in ({$allowedList})
            ),
            memberrel as (select objid as oid from member where classid = 'pg_catalog.pg_class'::pg_catalog.regclass),
            membertype as (
                select t.oid from pg_catalog.pg_type t
                where t.oid in (select objid from member where classid = 'pg_catalog.pg_type'::pg_catalog.regclass) or t.typrelid in (select oid from memberrel)
            )
            select (select count(*) from pg_catalog.pg_class c where c.oid >= {$first} and c.oid not in (select oid from memberrel)
                      and not (c.relkind = 't' and exists (select 1 from pg_catalog.pg_class p where p.reltoastrelid = c.oid and p.oid in (select oid from memberrel))))
                 + (select count(*) from pg_catalog.pg_proc p where p.oid >= {$first} and p.oid not in (select objid from member where classid = 'pg_catalog.pg_proc'::pg_catalog.regclass))
                 + (select count(*) from pg_catalog.pg_type t where t.oid >= {$first} and t.oid not in (select oid from membertype) and t.typelem not in (select oid from membertype))
                 + (select count(*) from pg_catalog.pg_namespace n where n.oid >= {$first}
                      and n.oid not in (select e.extnamespace from pg_catalog.pg_extension e where e.extname in ({$allowedList})))
                 + (select count(*) from pg_catalog.pg_language l where l.oid >= {$first})
                 + (select count(*) from pg_catalog.pg_foreign_data_wrapper)
                 + (select count(*) from pg_catalog.pg_foreign_server)
                 + (select count(*) from pg_catalog.pg_largeobject_metadata)
                 + (select count(*) from pg_catalog.pg_default_acl)
                 + (select count(*) from pg_catalog.pg_tablespace s where s.oid >= {$first})
                 + (select count(*) from pg_catalog.pg_db_role_setting s
                      where s.setrole in (0, (select oid from pg_catalog.pg_roles where rolname = session_user))
                        and s.setdatabase in (0, (select oid from pg_catalog.pg_database where datname = pg_catalog.current_database())))
                 as n
            SQL)->n;
        if ($leftovers > 0) {
            $problems[] = "the database is not fresh: {$leftovers} object(s) or default(s) exist beyond initdb and the allowlisted extensions";
        }

        // Review round 1, #3: a privilege changed on an object initdb or an allowlisted
        // extension created would be pinned as baseline (and become required). Every
        // ACL must grant what its initial privileges grant (pg_init_privs, initdb's 'i'
        // or the extension's 'e' entry; PostgreSQL's default ACL when there is none),
        // compared as (grantee, privilege, grant option). initdb creates
        // information_schema after recording initial privileges: its schema keeps
        // initdb's PUBLIC USAGE, and its relations initdb's PUBLIC SELECT, except
        // the ones it leaves without an ACL (DatabasePrivilegeCheck::INFORMATION_SCHEMA_WITHOUT_ACL).
        $withoutAcl = '{'.implode(',', DatabasePrivilegeCheck::INFORMATION_SCHEMA_WITHOUT_ACL).'}';
        $changed = (int) $connection->selectOne(<<<'SQL'
            with cur as (
                select 'pg_catalog.pg_class'::pg_catalog.regclass as cls, c.oid, 0 as sub, c.relacl as acl,
                       pg_catalog.acldefault((case when c.relkind = 'S' then 's' else 'r' end)::pg_catalog."char", c.relowner) as dflt,
                       case when c.relnamespace = 'information_schema'::pg_catalog.regnamespace and c.relname <> all (?::pg_catalog.name[])
                            then 'SELECT' end as initdb_public, c.relowner as owner
                from pg_catalog.pg_class c
                union all
                -- A column has no default privilege; NULL, not '{}' (aclexplode refuses a zero-dimensional array).
                select 'pg_catalog.pg_class'::pg_catalog.regclass, a.attrelid, a.attnum, a.attacl, null::pg_catalog.aclitem[], null, 0::pg_catalog.oid
                from pg_catalog.pg_attribute a where a.attacl is not null
                union all
                select 'pg_catalog.pg_proc'::pg_catalog.regclass, p.oid, 0, p.proacl, pg_catalog.acldefault('f', p.proowner), null, p.proowner from pg_catalog.pg_proc p
                union all
                select 'pg_catalog.pg_type'::pg_catalog.regclass, t.oid, 0, t.typacl, pg_catalog.acldefault('T', t.typowner), null, t.typowner from pg_catalog.pg_type t
                union all
                select 'pg_catalog.pg_namespace'::pg_catalog.regclass, n.oid, 0, n.nspacl, pg_catalog.acldefault('n', n.nspowner),
                       case when n.nspname = 'information_schema' then 'USAGE' end, n.nspowner
                from pg_catalog.pg_namespace n
                union all
                select 'pg_catalog.pg_language'::pg_catalog.regclass, l.oid, 0, l.lanacl, pg_catalog.acldefault('l', l.lanowner), null, l.lanowner from pg_catalog.pg_language l
                union all
                select 'pg_catalog.pg_foreign_data_wrapper'::pg_catalog.regclass, w.oid, 0, w.fdwacl, pg_catalog.acldefault('F', w.fdwowner), null, w.fdwowner from pg_catalog.pg_foreign_data_wrapper w
                union all
                select 'pg_catalog.pg_foreign_server'::pg_catalog.regclass, s.oid, 0, s.srvacl, pg_catalog.acldefault('S', s.srvowner), null, s.srvowner from pg_catalog.pg_foreign_server s
                union all
                -- Shared: no initial privileges are recorded for a tablespace.
                select 'pg_catalog.pg_tablespace'::pg_catalog.regclass, s.oid, 0, s.spcacl, pg_catalog.acldefault('t', s.spcowner), null, s.spcowner from pg_catalog.pg_tablespace s
            ),
            compared as (
                select (select pg_catalog.string_agg(a.grantee::pg_catalog.text || ':' || a.privilege_type || ':' || a.is_grantable::pg_catalog.text, ',' order by a.grantee, a.privilege_type, a.is_grantable)
                        from pg_catalog.aclexplode(coalesce(x.acl, x.dflt)) a) as now,
                       (select pg_catalog.string_agg(a.grantee::pg_catalog.text || ':' || a.privilege_type || ':' || a.is_grantable::pg_catalog.text, ',' order by a.grantee, a.privilege_type, a.is_grantable)
                        from pg_catalog.aclexplode(coalesce(
                            i.initprivs,
                            case when x.initdb_public is null then x.dflt
                                 else pg_catalog.array_append(x.dflt, pg_catalog.makeaclitem(0, x.owner, x.initdb_public, false)) end)) a) as initial
                from cur x
                left join pg_catalog.pg_init_privs i on i.classoid = x.cls and i.objoid = x.oid and i.objsubid = x.sub
            )
            select count(*) as n from compared where now is distinct from initial
            SQL, [$withoutAcl])->n;
        if ($changed > 0) {
            $problems[] = "the database is not fresh: {$changed} object(s) carry privileges that differ from their initial privileges (initdb's or their extension's)";
        }
        $parameters = (int) $connection->selectOne('select count(*) as n from pg_catalog.pg_parameter_acl')->n;
        if ($parameters > 0) {
            $problems[] = "the cluster is not fresh: pg_parameter_acl holds {$parameters} row(s)";
        }

        return $problems;
    }

    /**
     * Review round 1, #4: the forms in which a role name can reach the output: as
     * written, and as escape() prints it, plainly or quoted (quote_ident doubles a
     * double quote), in its own, lower and upper case.
     *
     * @return list<string>
     */
    public static function roleForms(string $role): array
    {
        $variants = [$role];
        if (mb_check_encoding($role, 'UTF-8')) {
            $variants[] = mb_strtolower($role, 'UTF-8');
            $variants[] = mb_strtoupper($role, 'UTF-8');
        }
        $forms = [];
        foreach ($variants as $variant) {
            foreach ([$variant, str_replace('"', '""', $variant)] as $form) {
                $forms[] = $form;
                $forms[] = self::escape($form);
            }
        }
        $forms = array_values(array_unique(array_filter($forms, fn (string $f): bool => $f !== '')));
        // Longest first, so a longer form is withheld before a shorter one inside it.
        usort($forms, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $forms;
    }

    /**
     * The token of every role: the session user, the owner role, PUBLIC, the
     * bootstrap superuser, the predefined roles by name, any other role as OTHER_ROLE.
     *
     * @return array<int, string>
     */
    private static function roleTokens(ConnectionInterface $connection, ?string $owner): array
    {
        $tokens = [0 => self::PUBLIC];
        foreach ($connection->select('select oid, rolname, rolname = session_user as is_session from pg_catalog.pg_roles') as $row) {
            $oid = (int) $row->oid;
            $name = (string) $row->rolname;
            $tokens[$oid] = match (true) {
                (bool) $row->is_session => self::RUNTIME,
                $owner !== null && $name === $owner => self::OWNER,
                $oid === 10 => self::BOOTSTRAP,
                $oid < self::FIRST_NORMAL_OBJECT_ID && str_starts_with($name, 'pg_') => $name,
                default => self::OTHER_ROLE,
            };
        }

        return $tokens;
    }

    /** One surface line; every field escaped so that no name can forge a separator or a line. */
    public static function line(string $kind, string $identity, string $privilege, string $via, bool $grantable): string
    {
        return implode('|', array_map(self::escape(...), [$kind, $identity, $privilege, $via])).'|'.($grantable ? 'YES' : 'NO');
    }

    public static function escape(string $field): string
    {
        return (string) preg_replace_callback('/[\x00-\x1F\x7F-\xFF|\\\\]/', fn (array $m): string => sprintf('\\x%02X', ord($m[0])), $field);
    }

    /** Session-numbered schema and toast names carry an OID or a backend number: they are printed as N. */
    private static function withoutOids(string $identity): string
    {
        return (string) preg_replace('/\bpg_(toast_temp|temp|toast)_\d+\b/', 'pg_$1_N', $identity);
    }

    /** A protected setting pinned to its safe value is printed canonically (round 5, F2); any other default as stored. */
    private static function canonicalSetting(string $setting): string
    {
        $position = strpos($setting, '=');
        if ($position === false) {
            return $setting;
        }
        $name = strtolower(substr($setting, 0, $position));
        $value = strtolower(trim(substr($setting, $position + 1), ' "\''));
        $safe = DatabasePrivilegeCheck::SAFE_SETTINGS[$name] ?? null;

        return $safe !== null && $value === $safe ? "{$name}={$safe}" : $setting;
    }
}
