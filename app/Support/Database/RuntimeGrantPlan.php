<?php

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B3 (rule 245 #6; owner decision D-Q3-b) — the
 * per-deploy runtime grants, computed from the runtime privilege registry.
 *
 * For every classified table the runtime gets exactly its class, and for every
 * sequence in `public` USAGE and SELECT (the application never calls setval or
 * nextval itself, so UPDATE is never granted):
 *
 *     FULL_DML                  SELECT, INSERT, UPDATE, DELETE
 *     APPEND_ONLY               SELECT, INSERT
 *     APPEND_ONLY_WITH_COLUMNS  SELECT, INSERT, and UPDATE of the named columns
 *     READ_ONLY_RUNTIME         SELECT
 *
 * Each object's statements are `REVOKE ALL … FROM <runtime>` and then the class
 * grant, so the result never depends on what the runtime held before. Only the
 * runtime's own entries are touched: PUBLIC and every other grantee are left as
 * they are, and a grant reaching the runtime through them is reported by the
 * check, never silently kept.
 *
 * The forward SQL depends only on the registry, the sequences and the role
 * names, never on the current grants, so its SHA-256 is stable (R6-C1, IAR-5).
 * The rollback SQL is the exact inverse for the runtime's entries: it restores
 * the runtime's explicit grants as they are now (table and column privileges,
 * with their grant option). It does not restore the grantor, which becomes the
 * role that runs it.
 *
 * Role and object names come only from configuration and the catalog; each one
 * is validated and double-quoted. Provisioning (the one-time ownership transfer,
 * database and schema privileges, default privileges, trigger-function settings)
 * is not here: it is the administrator's R6-D SQL.
 */
final class RuntimeGrantPlan
{
    public const IDENTIFIER = '/\A[a-z_][a-z0-9_]{0,62}\z/';

    /** @var array<string, list<string>> table privileges per class, in canonical order */
    public const TABLE_PRIVILEGES = [
        RuntimePrivilegeClass::FULL_DML => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        RuntimePrivilegeClass::APPEND_ONLY => ['SELECT', 'INSERT'],
        RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS => ['SELECT', 'INSERT'],
        RuntimePrivilegeClass::READ_ONLY_RUNTIME => ['SELECT'],
    ];

    /** @var list<string> */
    public const SEQUENCE_PRIVILEGES = ['USAGE', 'SELECT'];

    /** Canonical order of every table and sequence privilege the rollback can restore. */
    private const PRIVILEGE_ORDER = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER', 'MAINTAIN', 'USAGE'];

    public function __construct(private readonly RuntimePrivilegeRegistry $registry) {}

    /** Placeholder role names of the template SQL, whose SHA-256 is the one ever printed (review round 1, finding 7). */
    public const TEMPLATE_RUNTIME = 'r6_template_runtime';

    public const TEMPLATE_OWNER = 'r6_template_owner';

    /** Names a role setting must never take: they mean something else in GRANT, or are system roles (finding 12). */
    private const RESERVED_ROLES = ['public', 'none', 'all', 'current_user', 'current_role', 'session_user', 'user', 'postgres'];

    /** A plain lower-case role name that is not reserved, not `pg_*` and not `postgres`. */
    public static function plainRole(string $name): bool
    {
        return preg_match(self::IDENTIFIER, $name) === 1
            && ! in_array($name, self::RESERVED_ROLES, true)
            && ! str_starts_with($name, 'pg_');
    }

    /**
     * The SHA-256 of the forward SQL with placeholder role names. It depends only
     * on the registry and the sequences (both derivable from the public
     * repository), so it is comparable across environments (R6-C1, IAR-5) and
     * reveals no role name (OD-R6-6). The SQL that names the real roles is only
     * ever printed for the administrator, never hashed into a log.
     *
     * @param  array<string, array{class: string, columns: list<string>, source?: string}>  $entries
     * @param  list<string>  $sequences
     */
    public static function templateSha256(array $entries, array $sequences): string
    {
        return hash('sha256', self::script(self::forwardStatements($entries, $sequences, self::TEMPLATE_RUNTIME), self::TEMPLATE_OWNER, 'forward'));
    }

    public static function quote(string $identifier): string
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new InvalidArgumentException('an identifier is not a plain lower-case name (letters, digits, underscore; at most 63).');
        }

        return '"'.$identifier.'"';
    }

    /** A role name, validated by plainRole() and double-quoted. */
    public static function role(string $name): string
    {
        if (! self::plainRole($name)) {
            throw new InvalidArgumentException('a role name is not a plain, non-reserved lower-case name.');
        }

        return '"'.$name.'"';
    }

    /**
     * The forward statements, without the transaction wrapper.
     *
     * @param  array<string, array{class: string, columns: list<string>, source?: string}>  $entries  table => class
     * @param  list<string>  $sequences
     * @return list<string>
     */
    public static function forwardStatements(array $entries, array $sequences, string $runtime): array
    {
        $grantee = self::role($runtime);
        ksort($entries);
        $sequences = array_values(array_unique($sequences));
        sort($sequences);

        $statements = [];
        foreach ($entries as $table => $entry) {
            if (! isset(self::TABLE_PRIVILEGES[$entry['class']])) {
                throw new InvalidArgumentException("{$table}: unknown runtime privilege class.");
            }
            $object = 'public.'.self::quote((string) $table);
            $statements[] = "REVOKE ALL ON TABLE {$object} FROM {$grantee}";
            $statements[] = 'GRANT '.implode(', ', self::TABLE_PRIVILEGES[$entry['class']])." ON TABLE {$object} TO {$grantee}";
            if ($entry['class'] === RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS) {
                $columns = $entry['columns'];
                sort($columns);
                $statements[] = 'GRANT UPDATE ('.implode(', ', array_map(self::quote(...), $columns)).") ON TABLE {$object} TO {$grantee}";
            }
        }
        foreach ($sequences as $sequence) {
            $object = 'public.'.self::quote($sequence);
            $statements[] = "REVOKE ALL ON SEQUENCE {$object} FROM {$grantee}";
            $statements[] = 'GRANT '.implode(', ', self::SEQUENCE_PRIVILEGES)." ON SEQUENCE {$object} TO {$grantee}";
        }

        return $statements;
    }

    /**
     * The statements that restore the runtime's current explicit grants on the
     * same objects as the forward statements.
     *
     * @param  array<string, array{class: string, columns: list<string>, source?: string}>  $entries
     * @param  list<string>  $sequences
     * @param  array<string, array{table: array<string, bool>, columns: array<string, array<string, bool>>}>  $current
     *                                                                                                                  object => privilege => grantable, and column => privilege => grantable
     * @return list<string>
     */
    public static function rollbackStatements(array $entries, array $sequences, array $current, string $runtime): array
    {
        $grantee = self::role($runtime);
        $objects = [];
        foreach (array_keys($entries) as $table) {
            $objects[(string) $table] = 'TABLE';
        }
        foreach ($sequences as $sequence) {
            $objects[$sequence] = 'SEQUENCE';
        }
        ksort($objects);

        $statements = [];
        foreach ($objects as $name => $kind) {
            $object = 'public.'.self::quote($name);
            $statements[] = "REVOKE ALL ON {$kind} {$object} FROM {$grantee}";
            $held = $current[$name] ?? ['table' => [], 'columns' => []];
            foreach ([false, true] as $grantable) {
                $privileges = self::ordered(array_keys(array_filter($held['table'], fn (bool $g): bool => $g === $grantable)));
                if ($privileges !== []) {
                    $statements[] = 'GRANT '.implode(', ', $privileges)." ON {$kind} {$object} TO {$grantee}".($grantable ? ' WITH GRANT OPTION' : '');
                }
            }
            $columns = $held['columns'];
            ksort($columns);
            foreach ($columns as $column => $privileges) {
                foreach ([false, true] as $grantable) {
                    $selected = self::ordered(array_keys(array_filter($privileges, fn (bool $g): bool => $g === $grantable)));
                    foreach ($selected as $privilege) {
                        $statements[] = "GRANT {$privilege} (".self::quote((string) $column).") ON TABLE {$object} TO {$grantee}".($grantable ? ' WITH GRANT OPTION' : '');
                    }
                }
            }
        }

        return $statements;
    }

    /**
     * The printable SQL: a guard that refuses any role but the owner role, then
     * the statements in one transaction. Deterministic: no timestamp, no host.
     *
     * @param  list<string>  $statements
     */
    public static function script(array $statements, string $owner, string $kind): string
    {
        self::role($owner);
        $lines = [
            "-- foundation:db-privilege-apply {$kind} SQL (DMS-SPN4-READY-PILOT-1 R6-B3). Run with psql -X -v ON_ERROR_STOP=1.",
            'BEGIN;',
            "SET LOCAL lock_timeout = '5s';",
            "SET LOCAL statement_timeout = '120s';",
            "DO \$guard\$ BEGIN IF current_user <> '{$owner}' THEN RAISE EXCEPTION 'R6-B3 grants run only as the owner role'; END IF; END \$guard\$;",
        ];
        foreach ($statements as $statement) {
            $lines[] = $statement.';';
        }
        $lines[] = 'COMMIT;';

        return implode("\n", $lines)."\n";
    }

    /**
     * The registry entries and the sequences of the connection's schema, or the
     * problems that stop a plan.
     *
     * @return array{0: array<string, array{class: string, columns: list<string>, source: string}>, 1: list<string>, 2: list<string>}
     */
    public function inputs(ConnectionInterface $connection): array
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('runtime grants exist only on PostgreSQL.');
        }
        if ((string) $connection->selectOne('select current_schema() as s')->s !== 'public') {
            throw new RuntimeException('the connection does not resolve to the public schema.');
        }

        $errors = $this->registry->verify($connection);
        [$entries] = $this->registry->expected();
        $sequences = array_map(
            fn (object $row): string => (string) $row->relname,
            $connection->select("select c.relname from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'S' order by c.relname")
        );

        return [$entries, $sequences, $errors];
    }

    /**
     * The runtime's explicit grants on every table and sequence in `public`, read
     * from the ACLs (never from has_*_privilege, which also counts PUBLIC).
     *
     * @return array<string, array{table: array<string, bool>, columns: array<string, array<string, bool>>}>
     */
    public static function currentGrants(ConnectionInterface $connection, string $runtime): array
    {
        $current = [];
        $tableRows = $connection->select(<<<'SQL'
            select c.relname, a.privilege_type, a.is_grantable
            from pg_catalog.pg_class c
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            cross join lateral pg_catalog.aclexplode(coalesce(c.relacl, pg_catalog.acldefault((case when c.relkind = 'S' then 's' else 'r' end)::"char", c.relowner))) a
            where n.nspname = 'public' and c.relkind in ('r', 'p', 'S')
              and a.grantee = (select oid from pg_catalog.pg_roles where rolname = ?)
            SQL, [$runtime]);
        foreach ($tableRows as $row) {
            $current[(string) $row->relname]['table'][(string) $row->privilege_type] = (bool) $row->is_grantable;
        }
        $columnRows = $connection->select(<<<'SQL'
            select c.relname, att.attname, a.privilege_type, a.is_grantable
            from pg_catalog.pg_attribute att
            join pg_catalog.pg_class c on c.oid = att.attrelid
            join pg_catalog.pg_namespace n on n.oid = c.relnamespace
            cross join lateral pg_catalog.aclexplode(att.attacl) a
            where n.nspname = 'public' and c.relkind in ('r', 'p') and att.attnum > 0 and not att.attisdropped
              and att.attacl is not null
              and a.grantee = (select oid from pg_catalog.pg_roles where rolname = ?)
            SQL, [$runtime]);
        foreach ($columnRows as $row) {
            $current[(string) $row->relname]['columns'][(string) $row->attname][(string) $row->privilege_type] = (bool) $row->is_grantable;
        }
        foreach ($current as $name => $held) {
            $current[$name] = ['table' => $held['table'] ?? [], 'columns' => $held['columns'] ?? []];
        }

        return $current;
    }

    /**
     * The runtime's expected explicit grants per object, in the shape of currentGrants().
     *
     * @param  array<string, array{class: string, columns: list<string>, source?: string}>  $entries
     * @param  list<string>  $sequences
     * @return array<string, array{table: array<string, bool>, columns: array<string, array<string, bool>>}>
     */
    public static function expectedGrants(array $entries, array $sequences): array
    {
        $expected = [];
        foreach ($entries as $table => $entry) {
            $columns = [];
            if ($entry['class'] === RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS) {
                foreach ($entry['columns'] as $column) {
                    $columns[$column] = ['UPDATE' => false];
                }
            }
            $expected[(string) $table] = ['table' => array_fill_keys(self::TABLE_PRIVILEGES[$entry['class']] ?? [], false), 'columns' => $columns];
        }
        foreach ($sequences as $sequence) {
            $expected[$sequence] = ['table' => array_fill_keys(self::SEQUENCE_PRIVILEGES, false), 'columns' => []];
        }

        return $expected;
    }

    /**
     * The objects whose explicit runtime grants differ from their class.
     *
     * @param  array<string, array{table: array<string, bool>, columns: array<string, array<string, bool>>}>  $expected
     * @param  array<string, array{table: array<string, bool>, columns: array<string, array<string, bool>>}>  $current
     * @return list<string>
     */
    public static function changedObjects(array $expected, array $current): array
    {
        $changed = [];
        foreach ($expected as $name => $want) {
            $have = $current[$name] ?? ['table' => [], 'columns' => []];
            if (self::normalised($want) !== self::normalised($have)) {
                $changed[] = $name;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * Only the statements of the given objects, in forward order.
     *
     * @param  list<string>  $statements
     * @param  list<string>  $objects
     * @return list<string>
     */
    public static function statementsFor(array $statements, array $objects): array
    {
        $wanted = array_fill_keys(array_map(fn (string $o): string => 'public.'.self::quote($o), $objects), true);

        return array_values(array_filter($statements, function (string $statement) use ($wanted): bool {
            return preg_match('/ ON (?:TABLE|SEQUENCE) (public\."[a-z0-9_]+")/', $statement, $m) === 1 && isset($wanted[$m[1]]);
        }));
    }

    /** @param  array{table: array<string, bool>, columns: array<string, array<string, bool>>}  $grants */
    private static function normalised(array $grants): array
    {
        $table = $grants['table'];
        ksort($table);
        $columns = array_filter($grants['columns'], fn (array $p): bool => $p !== []);
        foreach ($columns as $column => $privileges) {
            ksort($privileges);
            $columns[$column] = $privileges;
        }
        ksort($columns);

        return ['table' => $table, 'columns' => $columns];
    }

    /**
     * @param  list<string>  $privileges
     * @return list<string>
     */
    private static function ordered(array $privileges): array
    {
        $order = array_flip(self::PRIVILEGE_ORDER);
        foreach ($privileges as $privilege) {
            // An unknown privilege would make the rollback inexact: refuse it.
            if (! isset($order[$privilege])) {
                throw new RuntimeException('the runtime holds a privilege the rollback cannot restore exactly.');
            }
        }
        usort($privileges, fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $privileges;
    }
}
