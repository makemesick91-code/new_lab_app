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
 * - A role the login may not assume makes the server refuse the session, so
 *   nothing can be created.
 *
 * Fail closed, on the first connect and on every reconnect (an extension keyed
 * by the connection name runs for both):
 * 1. the driver stays `pgsql`, because migrations branch on the driver name;
 * 2. no URL and no password: authentication is local peer only over a Unix
 *    socket (OD-R6-1), so no migration credential exists to store or leak;
 * 3. the login role and the owner role are set, distinct, and plain lower-case
 *    identifiers;
 * 4. once the server accepts the session, `current_user` must equal the owner
 *    role.
 *
 * The connection ships unconfigured: nothing selects it until the deploy
 * runner's host switch (R6-B4).
 *
 * Messages never contain a configured value (host, login or role name).
 */
final class MigrationConnection
{
    public const NAME = 'pgsql_migrate';

    private const IDENTIFIER = '/\A[a-z_][a-z0-9_]{0,62}\z/';

    private const PREFIX = 'pgsql_migrate refused: ';

    public static function register(DatabaseManager $db, ConnectionFactory $factory): void
    {
        $db->extend(self::NAME, static function (array $config, string $name) use ($factory): Connection {
            // Laravel merges a `url` into host, user and password before an
            // extension runs, so the URL refusal reads the connection as declared.
            self::assertConfigured($config, (array) config('database.connections.'.$name, []));

            $connection = $factory->make($config, $name);
            $role = (string) $config['server_options']['role'];
            $raw = $connection->getRawPdo();

            $connection->setPdo(static function () use ($raw, $role): PDO {
                $pdo = $raw instanceof Closure ? $raw() : $raw;
                self::assertSessionRole($pdo, $role);

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
            throw new RuntimeException(self::PREFIX.'the driver must stay pgsql.');
        }

        if (! empty($config['url']) || ! empty($declared['url'])) {
            throw new RuntimeException(self::PREFIX.'a URL is configured; the migration connection takes none.');
        }

        if ((string) ($config['password'] ?? '') !== '') {
            throw new RuntimeException(self::PREFIX.'a password is configured; it authenticates by local peer only.');
        }

        $host = (string) ($config['host'] ?? '');
        if ($host === '' || ! str_starts_with($host, '/')) {
            throw new RuntimeException(self::PREFIX.'the host must be the server\'s Unix socket directory.');
        }

        $login = (string) ($config['username'] ?? '');
        $role = (string) ($config['server_options']['role'] ?? '');

        if (preg_match(self::IDENTIFIER, $login) !== 1) {
            throw new RuntimeException(self::PREFIX.'the migration login role is not configured or not a plain identifier.');
        }

        if (preg_match(self::IDENTIFIER, $role) !== 1) {
            throw new RuntimeException(self::PREFIX.'the owner role is not configured or not a plain identifier.');
        }

        if ($login === $role) {
            throw new RuntimeException(self::PREFIX.'the login role and the owner role must differ.');
        }
    }

    public static function assertSessionRole(PDO $pdo, string $role): void
    {
        $statement = $pdo->query('select current_user');
        $current = $statement === false ? null : $statement->fetchColumn();

        if ($current !== $role) {
            throw new RuntimeException(self::PREFIX.'the session does not run as the configured owner role.');
        }
    }
}
