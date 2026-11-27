<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Migrations\Migrator;
use PDO;
use RuntimeException;

/**
 * DMS-SPN4-READY-PILOT-1 R6-B2b (rule 245 #2, A-17; OD-R6-1) — the dedicated
 * migration connection `pgsql_migrate`.
 *
 * Migrations run as a separate login role that acts only through the owner
 * role, so every object they create is owned by the owner role. The server
 * applies that role at the start of EVERY session through the libpq startup
 * option `-c role=…` (config `server_options.role`, built into the DSN by
 * Laravel's PostgresConnector):
 *
 * - A reconnect can never run as the bare migrator. A one-off `SET ROLE` after
 *   connect would be lost when Laravel rebuilds the PDO
 *   (DatabaseManager::refreshPdoConnections()).
 * - A role the login may not assume makes the server refuse the session.
 *
 * Fail closed, on the first connect and on every reconnect (an extension keyed
 * by the connection name runs for both):
 * 1. Before connecting, every value that reaches the DSN is validated strictly:
 *    - an absolute socket directory, a numeric port, a plain database name, a
 *      fixed charset and sslmode, and `search_path` `public`;
 *    - only known configuration keys, so no `url`, `read`/`write`, `options`,
 *      `connect_via_*` or extra startup option;
 *    - no password: authentication is local peer only (OD-R6-1);
 *    - a plain, non-reserved login and owner role that differ;
 *    - the driver stays `pgsql`, because migrations branch on the driver name.
 *    - no `name` key, and the built connection keeps the name `pgsql_migrate`;
 *    - no libpq variable that can redirect the session (PGHOSTADDR, PGSERVICE,
 *      PGSERVICEFILE).
 * 2. After the server accepts the session, the server itself must confirm:
 *    - `current_user` is the owner role and `session_user` is the login;
 *    - the session came over a Unix socket (`inet_server_addr()` is null)
 *      through peer authentication (`system_user` = `peer:…`), so a TCP route
 *      is refused even if one slipped past step 1;
 *    - neither role holds an elevated attribute, and the owner role cannot log in;
 *    - the login can SET the owner role but inherits it through no chain, and
 *      cannot create in `public` itself.
 * 3. After every migration of a `pgsql_migrate` run (assertAfterMigration):
 *    the session is still the owner role and nothing in `public` is owned by
 *    another role; a refusal rolls the migration back.
 *
 * Which OS account may log in as the migration login is decided by the server's
 * peer map (pg_ident), not here: it must list only the dedicated migration OS
 * account (OD-R6-1; proven at the OS level on a disposable VM).
 *
 * The connection ships unconfigured: nothing selects it until the deploy
 * runner's host switch (R6-B4).
 *
 * This class's own refusals never contain a configured value. Laravel's
 * QueryException and the server's own messages add the connection details and
 * the role names; those reach only the private application log. That is why
 * production role names stay out of every public artifact (OD-R6-6).
 */
final class MigrationConnection
{
    public const NAME = 'pgsql_migrate';

    private const IDENTIFIER = '/\A[a-z_][a-z0-9_]{0,62}\z/';

    private const SOCKET_DIR = '#\A/[A-Za-z0-9._/-]{0,100}\z#';

    private const DATABASE = '/\A[A-Za-z0-9_][A-Za-z0-9_-]{0,62}\z/';

    // Review round 2, finding 1: no `name` key. ConnectionFactory keeps a declared
    // `name`, and Migrator::runMethod() makes `getName()` the default connection
    // inside every migration, so `'name' => 'pgsql'` would send the migration's own
    // DDL to the runtime connection, past every check here.
    private const ALLOWED_KEYS = [
        'driver', 'host', 'port', 'database', 'username', 'password', 'charset',
        'prefix', 'prefix_indexes', 'search_path', 'sslmode', 'server_options',
    ];

    /**
     * Review round 2, finding 6: libpq variables that can move a session off the
     * configured Unix socket (a TCP `hostaddr`, or a service entry carrying one).
     * The server-side check would refuse such a session, but only after the
     * connection is open; these are refused before any connect.
     */
    private const REDIRECTING_LIBPQ_ENV = ['PGHOSTADDR', 'PGSERVICE', 'PGSERVICEFILE'];

    private const SSLMODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    private const PREFIX = 'pgsql_migrate refused: ';

    public static function register(DatabaseManager $db, ConnectionFactory $factory): void
    {
        $db->extend(self::NAME, static function (array $config, string $name) use ($factory): Connection {
            // Laravel merges a `url` into the configuration before an extension
            // runs, so the URL and key checks also read the connection as declared.
            self::assertConfigured($config, (array) config('database.connections.'.$name, []));
            self::assertEnvironment();

            $connection = $factory->make($config, $name);
            if ($connection->getName() !== self::NAME) {
                self::refuse('the connection must keep its own name.');
            }
            $login = (string) $config['username'];
            $role = (string) $config['server_options']['role'];
            $raw = $connection->getRawPdo();

            $connection->setPdo(static function () use ($raw, $login, $role): PDO {
                $pdo = $raw instanceof Closure ? $raw() : $raw;
                self::assertSession($pdo, $login, $role);

                return $pdo;
            });

            return $connection;
        });
    }

    /**
     * @param  array<string, mixed>  $config  the parsed configuration
     * @param  array<string, mixed>  $declared  the configuration as declared, before URL parsing
     */
    public static function assertConfigured(array $config, array $declared = []): void
    {
        if (($config['driver'] ?? null) !== 'pgsql') {
            self::refuse('the driver must stay pgsql.');
        }

        if (! empty($config['url']) || ! empty($declared['url'])) {
            self::refuse('a URL is configured; the migration connection takes none.');
        }

        foreach ([$config, $declared] as $layer) {
            if (array_diff(array_keys($layer), self::ALLOWED_KEYS) !== []) {
                self::refuse('an unsupported option is configured (read/write, options, connect_via or similar).');
            }
        }

        $serverOptions = $config['server_options'] ?? null;
        if (! is_array($serverOptions) || array_keys($serverOptions) !== ['role']) {
            self::refuse('the only server option may be the owner role.');
        }

        if ((string) ($config['password'] ?? '') !== '') {
            self::refuse('a password is configured; it authenticates by local peer only.');
        }

        $host = (string) ($config['host'] ?? '');
        if ($host === '' || preg_match(self::SOCKET_DIR, $host) !== 1) {
            self::refuse('the host must be the server\'s Unix socket directory (a plain absolute path).');
        }

        $port = (string) ($config['port'] ?? '');
        if (preg_match('/\A[0-9]{1,5}\z/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            self::refuse('the port must be a plain number.');
        }

        if (preg_match(self::DATABASE, (string) ($config['database'] ?? '')) !== 1) {
            self::refuse('the database must be a plain name.');
        }

        if (! in_array($config['charset'] ?? 'utf8', ['utf8', 'UTF8'], true)) {
            self::refuse('the charset must be utf8.');
        }

        if (! in_array($config['sslmode'] ?? 'prefer', self::SSLMODES, true)) {
            self::refuse('the sslmode is not a known mode.');
        }

        if (($config['search_path'] ?? 'public') !== 'public') {
            self::refuse('the search_path must be public.');
        }

        $login = (string) ($config['username'] ?? '');
        $role = (string) $serverOptions['role'];

        if (! self::plainRole($login)) {
            self::refuse('the migration login role is not configured, not a plain identifier, or reserved.');
        }

        if (! self::plainRole($role)) {
            self::refuse('the owner role is not configured, not a plain identifier, or reserved.');
        }

        if ($login === $role) {
            self::refuse('the login role and the owner role must differ.');
        }
    }

    /** Refuses, before any connect, a libpq environment that could redirect the session. */
    public static function assertEnvironment(): void
    {
        foreach (self::REDIRECTING_LIBPQ_ENV as $variable) {
            $value = getenv($variable);
            if ($value !== false && $value !== '') {
                self::refuse('a libpq environment variable that can redirect the connection is set ('.$variable.').');
            }
        }
    }

    public static function assertSession(PDO $pdo, string $login, string $role): void
    {
        // Review round 2, findings 3 and 5:
        // - membership is judged by the server's effective answer, not by one
        //   direct grant row: the login must be able to SET the owner role and
        //   must not inherit it through ANY chain (pg_has_role USAGE), and the bare
        //   login must not be able to create in `public` itself. Two grants of the
        //   same membership by different grantors no longer break the check;
        // - both roles are checked for elevated attributes, and the owner role must
        //   not be able to log in (rule 245 #1, #5).
        $statement = $pdo->query(<<<'SQL'
            select current_user::text as cur,
                   session_user::text as ses,
                   case when inet_server_addr() is null then 'socket' else 'tcp' end as transport,
                   coalesce(system_user, '') as auth,
                   (select case when r.rolsuper or r.rolcreaterole or r.rolcreatedb or r.rolbypassrls or r.rolreplication
                                then 'privileged' else 'plain' end
                      from pg_roles r where r.rolname = session_user) as login_kind,
                   (select case when r.rolsuper or r.rolcreaterole or r.rolcreatedb or r.rolbypassrls or r.rolreplication
                                     or r.rolcanlogin
                                then 'privileged' else 'plain' end
                      from pg_roles r where r.rolname = current_user) as owner_kind,
                   case when pg_has_role(session_user, current_user, 'USAGE') then 'inherit'
                        when pg_has_role(session_user, current_user, 'SET') then 'noinherit'
                        else 'none' end as membership,
                   case when has_schema_privilege(session_user, 'public', 'CREATE') then 'yes' else 'no' end
                     as login_creates
            SQL);
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row)) {
            self::refuse('the session could not be verified.');
        }

        if (($row['cur'] ?? null) !== $role || ($row['ses'] ?? null) !== $login) {
            self::refuse('the session does not run as the configured owner role through the configured login.');
        }

        if (($row['transport'] ?? null) !== 'socket' || ! str_starts_with((string) ($row['auth'] ?? ''), 'peer:')) {
            self::refuse('the session did not arrive over a Unix socket by peer authentication.');
        }

        if (($row['login_kind'] ?? null) !== 'plain') {
            self::refuse('the migration login must not be a superuser or hold another elevated attribute.');
        }

        if (($row['owner_kind'] ?? null) !== 'plain') {
            self::refuse('the owner role must not log in or hold an elevated attribute.');
        }

        if (($row['membership'] ?? null) !== 'noinherit') {
            self::refuse('the login\'s membership in the owner role must exist and must not inherit, directly or through another role.');
        }

        if (($row['login_creates'] ?? null) !== 'no') {
            self::refuse('the migration login itself must not be able to create objects in public.');
        }
    }

    /**
     * Review round 2, finding 4: the backstop after every migration of a
     * `pgsql_migrate` run.
     *
     * A static scan cannot see every way a migration could leave the owner role or
     * leave an object to another role: dynamic SQL, string concatenation, its own
     * `$connection`, a named connection. Whatever it did, after it the server must
     * still report the owner role as `current_user`, and nothing in `public` (the
     * schema, relations, functions, types) may be owned by another role. The
     * MigrationEnded event fires inside the migration's own transaction, so a
     * refusal rolls the migration back, and the migrate command stops.
     */
    public static function assertAfterMigration(MigrationEnded $event, Migrator $migrator, DatabaseManager $db): void
    {
        if ($migrator->getConnection() !== self::NAME) {
            return;
        }

        $declared = $event->migration->getConnection();
        if ($declared !== null && $declared !== self::NAME) {
            self::refuse('a migration selected another connection during a migration-connection run.');
        }

        $owner = (string) config('database.connections.'.self::NAME.'.server_options.role');
        // R6-B4: the members of an allowlisted extension (pg_stat_statements, which
        // production may have installed in `public` and must not move) are owned by
        // whoever created the extension. Exactly those objects — pg_depend deptype
        // 'e' to that extension — and the row and array types of its relations are
        // exempt; nothing else in `public` is.
        $row = (array) $db->connection(self::NAME)->selectOne(<<<'SQL'
            with member as (
                select d.classid, d.objid
                from pg_catalog.pg_depend d
                join pg_catalog.pg_extension e on e.oid = d.refobjid
                where d.refclassid = 'pg_catalog.pg_extension'::regclass and d.deptype = 'e'
                  and e.extname = 'pg_stat_statements'
            )
            select current_user::text as cur,
                   (select count(*) from pg_catalog.pg_namespace n
                     where n.nspname = 'public' and pg_catalog.pg_get_userbyid(n.nspowner) <> ?)
                 + (select count(*) from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace
                     where n.nspname = 'public' and pg_catalog.pg_get_userbyid(c.relowner) <> ?
                       and (c.oid not in (select objid from member where classid = 'pg_catalog.pg_class'::regclass)))
                 + (select count(*) from pg_catalog.pg_proc p join pg_catalog.pg_namespace n on n.oid = p.pronamespace
                     where n.nspname = 'public' and pg_catalog.pg_get_userbyid(p.proowner) <> ?
                       and (p.oid not in (select objid from member where classid = 'pg_catalog.pg_proc'::regclass)))
                 + (select count(*) from pg_catalog.pg_type t join pg_catalog.pg_namespace n on n.oid = t.typnamespace
                     where n.nspname = 'public' and pg_catalog.pg_get_userbyid(t.typowner) <> ?
                       and (t.oid not in (select objid from member where classid = 'pg_catalog.pg_type'::regclass))
                       and (t.typrelid not in (select objid from member where classid = 'pg_catalog.pg_class'::regclass))
                       and (t.typelem = 0 or t.typelem not in (
                           select r.oid from pg_catalog.pg_type r
                           where r.typrelid in (select objid from member where classid = 'pg_catalog.pg_class'::regclass)))) as foreign_owned
            SQL, [$owner, $owner, $owner, $owner]);

        if (($row['cur'] ?? null) !== $owner) {
            self::refuse('after a migration the session no longer runs as the owner role.');
        }

        if ((int) ($row['foreign_owned'] ?? 1) !== 0) {
            self::refuse('after a migration an object in public is owned by another role.');
        }
    }

    private static function plainRole(string $name): bool
    {
        return preg_match(self::IDENTIFIER, $name) === 1
            && $name !== 'postgres'
            && ! str_starts_with($name, 'pg_');
    }

    private static function refuse(string $reason): never
    {
        throw new RuntimeException(self::PREFIX.$reason);
    }
}
