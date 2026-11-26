<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B2b (rule 245 #2, A-17; OD-R6-1): the dedicated
| migration connection `pgsql_migrate`.
|
| Group A (always runs, no database contact):
| - the connection's shape: driver `pgsql`, Unix socket, no URL, no password,
|   the owner role carried as a server startup option;
| - the guard fails closed on every misconfiguration, before any connect;
| - the guard runs again on reconnect;
| - the session-role assertion;
| - no configured value appears in a refusal;
| - the runtime connections are unchanged.
|
| Group B (PostgreSQL 16 role scenarios) runs only against a private,
| disposable cluster provisioned for it. The private role-test environment
| variables name the socket directory, port, database, login role, owner
| role, a non-member role and the runtime login. Without them the group is
| SKIPPED, and a skip is never reported as a pass; the CI role-separation job
| (PB-7) runs it.
|
| Selected by the `FoundationGovernance` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `fgmc`.
*/

use App\Support\Database\MigrationConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** A complete, valid configuration that points at a socket directory nothing listens on. */
function fgmcConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'driver' => 'pgsql',
        'host' => '/nonexistent/fgmc-socket-dir',
        'port' => '5432',
        'database' => 'fgmc_database',
        'username' => 'fgmc_login',
        'password' => '',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'disable',
        'server_options' => ['role' => 'fgmc_owner'],
    ], $overrides);
}

function fgmcUse(array $config): void
{
    config(['database.connections.pgsql_migrate' => $config]);
    DB::purge('pgsql_migrate');
}

/** The refusal message for $config, or null when the connection object is created. */
function fgmcRefusal(array $config): ?string
{
    fgmcUse($config);

    try {
        DB::connection('pgsql_migrate');
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return null;
}

afterEach(function () {
    DB::purge('pgsql_migrate');
});

// ---------------------------------------------------------------- Group A

it('ships the migration connection unconfigured, peer-only and with no credential', function () {
    $source = (string) file_get_contents(config_path('database.php'));
    preg_match("/'pgsql_migrate' => \[(.*?)\n        \],/s", $source, $block);

    expect($block)->not->toBe([]);
    expect($block[1])->not->toContain('PASSWORD')
        ->and($block[1])->not->toContain("'url'")
        ->and($block[1])->toContain("'password' => ''")
        ->and($block[1])->toContain("'driver' => 'pgsql'")
        ->and($block[1])->toContain("env('DB_MIGRATE_OWNER_ROLE')");

    $config = config('database.connections.pgsql_migrate');
    expect($config['driver'])->toBe('pgsql')
        ->and($config['password'])->toBe('')
        ->and($config)->not->toHaveKey('url')
        ->and($config['search_path'])->toBe('public');
});

it('leaves the default and the runtime connections untouched', function () {
    expect(config('database.default'))->not->toBe(MigrationConnection::NAME);

    foreach (['pgsql', 'pgsql_read'] as $name) {
        expect(config("database.connections.{$name}"))->not->toHaveKey('server_options');
    }

    // The extension is keyed by name: resolving the default connection never consults it.
    expect(DB::connection()->getName())->toBe(config('database.default'));
});

it('refuses the unconfigured default before any connect', function () {
    $message = fgmcRefusal(config('database.connections.pgsql_migrate'));

    expect($message)->toStartWith('pgsql_migrate refused: ');
});

it('fails closed on every misconfiguration', function (array $overrides, string $reason) {
    $config = fgmcConfig();
    foreach ($overrides as $key => $value) {
        data_set($config, $key, $value);
    }

    expect(fgmcRefusal($config))->toContain($reason);
})->with([
    'driver renamed' => [['driver' => 'pgsql_migrate'], 'driver must stay pgsql'],
    'a URL' => [['url' => 'pgsql://fgmc_login@localhost/fgmc_database'], 'URL is configured'],
    'a password' => [['password' => 'fgmc-secret'], 'password is configured'],
    'a TCP host' => [['host' => '127.0.0.1'], 'Unix socket directory'],
    'no host' => [['host' => null], 'Unix socket directory'],
    'no login role' => [['username' => null], 'login role is not configured'],
    'login with a quote' => [['username' => "fgmc'login"], 'login role is not configured'],
    'no owner role' => [['server_options.role' => null], 'owner role is not configured'],
    'owner role with a space' => [['server_options.role' => 'fgmc owner'], 'owner role is not configured'],
    'owner role injecting an option' => [['server_options.role' => 'x -c search_path=evil'], 'owner role is not configured'],
    'owner role upper case' => [['server_options.role' => 'FGMC_OWNER'], 'owner role is not configured'],
    'login equals owner' => [['username' => 'fgmc_owner'], 'must differ'],
]);

it('never echoes a configured value in a refusal', function () {
    $probe = fgmcConfig([
        'host' => '/fgmc-leak-probe-dir',
        'username' => 'fgmc_leak_probe_login',
        'password' => 'fgmc-leak-probe-secret',
        'server_options' => ['role' => 'fgmc_leak_probe_role'],
    ]);

    $message = (string) fgmcRefusal($probe);

    expect($message)->toStartWith('pgsql_migrate refused: ')
        ->and($message)->not->toContain('fgmc-leak-probe')
        ->and($message)->not->toContain('fgmc_leak_probe');
});

it('creates the connection lazily for a valid configuration and runs the guard again on reconnect', function () {
    fgmcUse(fgmcConfig());

    // Lazy PDO: no connect has been attempted yet.
    expect(DB::connection('pgsql_migrate')->getName())->toBe('pgsql_migrate');

    // A reconnect rebuilds the connection through the same extension, so a
    // configuration broken in between is refused instead of reconnecting.
    config(['database.connections.pgsql_migrate.server_options.role' => null]);

    expect(fn () => DB::reconnect('pgsql_migrate'))
        ->toThrow(RuntimeException::class, 'owner role is not configured');
});

it('asserts the session role after the server accepts the session', function (mixed $current, bool $accepted) {
    $statement = Mockery::mock(PDOStatement::class);
    $statement->shouldReceive('fetchColumn')->andReturn($current);
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')->with('select current_user')->andReturn($statement);

    $call = fn () => MigrationConnection::assertSessionRole($pdo, 'fgmc_owner');

    if ($accepted) {
        $call();
        expect(true)->toBeTrue();
    } else {
        expect($call)->toThrow(RuntimeException::class, 'does not run as the configured owner role');
    }
})->with([
    'the owner role' => ['fgmc_owner', true],
    'the bare login' => ['fgmc_login', false],
    'another role' => ['fgmc_other', false],
    'no row' => [false, false],
]);

// ---------------------------------------------------------------- Group B

function fgmcEnv(string $key): ?string
{
    $value = getenv('R6_ROLE_TEST_'.$key);

    return $value === false || $value === '' ? null : $value;
}

function fgmcClusterConfigured(): bool
{
    foreach (['SOCKET_DIR', 'PORT', 'DATABASE', 'MIGRATOR', 'OWNER', 'NON_MEMBER', 'RUNTIME', 'RUNTIME_PASSWORD'] as $key) {
        if (fgmcEnv($key) === null) {
            return false;
        }
    }

    return true;
}

function fgmcClusterConfig(?string $role = null): array
{
    return fgmcConfig([
        'host' => fgmcEnv('SOCKET_DIR'),
        'port' => fgmcEnv('PORT'),
        'database' => fgmcEnv('DATABASE'),
        'username' => fgmcEnv('MIGRATOR'),
        'server_options' => ['role' => $role ?? fgmcEnv('OWNER')],
    ]);
}

function fgmcPdo(string $user, string $password = ''): PDO
{
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', fgmcEnv('SOCKET_DIR'), fgmcEnv('PORT'), fgmcEnv('DATABASE'));

    return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** SQLSTATE of $sql run on $pdo, or 'ok'. */
function fgmcSqlState(PDO $pdo, string $sql): string
{
    try {
        $pdo->exec($sql);

        return 'ok';
    } catch (PDOException $e) {
        return (string) $e->getCode();
    }
}

$fgmcSkip = fn () => ! fgmcClusterConfigured();
$fgmcSkipReason = 'needs the private PostgreSQL 16 role-test cluster (R6_ROLE_TEST_*); run by PB-7';

it('runs every session as the owner role, also after a reconnect and a RESET ROLE', function () {
    fgmcUse(fgmcClusterConfig());
    $connection = DB::connection('pgsql_migrate');

    $who = fn () => (array) $connection->selectOne('select current_user as cur, session_user as ses');

    expect($who())->toBe(['cur' => fgmcEnv('OWNER'), 'ses' => fgmcEnv('MIGRATOR')]);

    DB::reconnect('pgsql_migrate');
    expect($who())->toBe(['cur' => fgmcEnv('OWNER'), 'ses' => fgmcEnv('MIGRATOR')]);

    // The startup option makes the owner role the session default, so a
    // RESET ROLE returns to it rather than to the bare login.
    $connection->statement('reset role');
    expect($who()['cur'])->toBe(fgmcEnv('OWNER'));
})->skip($fgmcSkip, $fgmcSkipReason);

it('is refused by the server for a role the login may not assume, and creates nothing', function () {
    fgmcUse(fgmcClusterConfig(fgmcEnv('NON_MEMBER')));

    expect(fn () => DB::connection('pgsql_migrate')->statement('create table public.fgmc_should_not_exist (id int)'))
        ->toThrow(PDOException::class);

    $exists = fgmcPdo(fgmcEnv('RUNTIME'), (string) fgmcEnv('RUNTIME_PASSWORD'))
        ->query("select to_regclass('public.fgmc_should_not_exist') is not null")->fetchColumn();
    expect($exists)->toBeFalse();
})->skip($fgmcSkip, $fgmcSkipReason);

it('denies the bare migrator and the runtime any DDL or the owner role', function () {
    $bare = fgmcPdo(fgmcEnv('MIGRATOR'));
    expect(fgmcSqlState($bare, 'create table public.fgmc_bare (id int)'))->toBe('42501');

    $runtime = fgmcPdo(fgmcEnv('RUNTIME'), (string) fgmcEnv('RUNTIME_PASSWORD'));
    expect(fgmcSqlState($runtime, 'set role '.fgmcEnv('OWNER')))->toBe('42501')
        ->and(fgmcSqlState($runtime, 'create table public.fgmc_runtime (id int)'))->toBe('42501');
})->skip($fgmcSkip, $fgmcSkipReason);

it('migrates the whole schema from empty with every object owned by the owner role', function () {
    fgmcUse(fgmcClusterConfig());

    expect(Artisan::call('migrate', ['--database' => 'pgsql_migrate', '--force' => true]))->toBe(0);

    $owner = fgmcEnv('OWNER');
    $pdo = fgmcPdo(fgmcEnv('RUNTIME'), (string) fgmcEnv('RUNTIME_PASSWORD'));

    $relations = (int) $pdo->query(
        "select count(*) from pg_class c join pg_namespace n on n.oid = c.relnamespace
          where n.nspname = 'public' and c.relkind in ('r','p','S','v','m','i','I')"
    )->fetchColumn();
    $foreignRelations = $pdo->query(
        "select c.relname || ':' || pg_get_userbyid(c.relowner) from pg_class c join pg_namespace n on n.oid = c.relnamespace
          where n.nspname = 'public' and c.relkind in ('r','p','S','v','m','i','I') and pg_get_userbyid(c.relowner) <> ".$pdo->quote($owner)
    )->fetchAll(PDO::FETCH_COLUMN);
    $foreignFunctions = $pdo->query(
        "select p.proname from pg_proc p join pg_namespace n on n.oid = p.pronamespace
          where n.nspname = 'public' and pg_get_userbyid(p.proowner) <> ".$pdo->quote($owner)
    )->fetchAll(PDO::FETCH_COLUMN);
    $foreignTypes = $pdo->query(
        "select t.typname from pg_type t join pg_namespace n on n.oid = t.typnamespace
          where n.nspname = 'public' and t.typtype in ('e','d','c') and t.typrelid = 0 and pg_get_userbyid(t.typowner) <> ".$pdo->quote($owner)
    )->fetchAll(PDO::FETCH_COLUMN);

    expect($relations)->toBeGreaterThan(186)
        ->and($foreignRelations)->toBe([])
        ->and($foreignFunctions)->toBe([])
        ->and($foreignTypes)->toBe([]);
})->skip($fgmcSkip, $fgmcSkipReason);
