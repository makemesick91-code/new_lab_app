<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
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
 * 2. After the server accepts the session, the server itself must confirm:
 *    - `current_user` is the owner role and `session_user` is the login;
 *    - the session came over a Unix socket (`inet_server_addr()` is null)
 *      through peer authentication (`system_user` = `peer:…`), so a TCP route,
 *      for example via PGHOSTADDR, is refused even if one slipped past step 1;
 *    - the login is not a superuser, and its membership in the owner role
 *      does not inherit.
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

    private const ALLOWED_KEYS = [
        'driver', 'host', 'port', 'database', 'username', 'password', 'charset',
        'prefix', 'prefix_indexes', 'search_path', 'sslmode', 'server_options', 'name',
    ];

    private const SSLMODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    private const PREFIX = 'pgsql_migrate refused: ';

    public static function register(DatabaseManager $db, ConnectionFactory $factory): void
    {
        $db->extend(self::NAME, static function (array $config, string $name) use ($factory): Connection {
            // Laravel merges a `url` into the configuration before an extension
            // runs, so the URL and key checks also read the connection as declared.
            self::assertConfigured($config, (array) config('database.connections.'.$name, []));

            $connection = $factory->make($config, $name);
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

    public static function assertSession(PDO $pdo, string $login, string $role): void
    {
        $statement = $pdo->query(<<<'SQL'
            select current_user::text as cur,
                   session_user::text as ses,
                   case when inet_server_addr() is null then 'socket' else 'tcp' end as transport,
                   coalesce(system_user, '') as auth,
                   (select case when r.rolsuper then 'super' else 'plain' end
                      from pg_roles r where r.rolname = session_user) as login_kind,
                   (select case when m.inherit_option then 'inherit' else 'noinherit' end
                      from pg_auth_members m
                      join pg_roles o on o.oid = m.roleid
                      join pg_roles l on l.oid = m.member
                     where o.rolname = current_user and l.rolname = session_user) as membership
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
            self::refuse('the migration login must not be a superuser.');
        }

        if (($row['membership'] ?? null) !== 'noinherit') {
            self::refuse('the login\'s membership in the owner role must exist and must not inherit.');
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
