<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B2b (rule 245 #2, A-17; OD-R6-1): the dedicated
| migration connection `pgsql_migrate`.
|
| Group A (always runs, no database server contact):
| - the connection's shape: driver `pgsql`, Unix socket, no URL, no password,
|   the owner role carried as the only server startup option;
| - the guard fails closed before any connect on every misconfiguration,
|   including DSN injection through host, port, database, sslmode or charset,
|   unknown keys (read/write, options, connect_via), extra startup options and
|   reserved role names;
| - the guard is wired in, and it runs again on reconnect;
| - the server-side session assertion: the owner role, the configured login, a
|   Unix socket, peer authentication, a non-superuser, a non-inheriting
|   membership;
| - no refusal of this class echoes a configured value;
| - no schema dump can bypass the role.
|
| Group B (PostgreSQL 16 role scenarios) runs only against a private,
| disposable cluster provisioned for it. The private role-test environment
| variables name the socket directory, port, database, login role, owner
| role, a non-member role and the runtime login. Without them the group is
| SKIPPED, and a skip is never reported as a pass. NO CI job provides them
| yet (review round 2, finding 2): until PB-7 adds the PostgreSQL 16
| role-separation job, group B SKIPS in CI, and its only evidence is the
| private-cluster rehearsal recorded outside the repository.
|
| Selected by the `FoundationGovernance` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `fgmc`.
*/

use App\Support\Database\MigrationConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\PostgresConnection;
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

/** A PDO double whose session-verification query returns $row. */
function fgmcSessionPdo(array|false $row): PDO
{
    $statement = Mockery::mock(PDOStatement::class);
    $statement->shouldReceive('fetch')->with(PDO::FETCH_ASSOC)->andReturn($row);
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')->andReturn($statement);

    return $pdo;
}

function fgmcGoodSession(array $overrides = []): array
{
    return array_replace([
        'cur' => 'fgmc_owner',
        'ses' => 'fgmc_login',
        'transport' => 'socket',
        'auth' => 'peer:dms-migrate',
        'login_kind' => 'plain',
        'owner_kind' => 'plain',
        'membership' => 'noinherit',
        'login_creates' => 'no',
    ], $overrides);
}

afterEach(function () {
    DB::purge('pgsql_migrate');
});

// ---------------------------------------------------------------- Group A

it('ships the migration connection unconfigured, peer-only, with the role as its only startup option', function () {
    $source = (string) file_get_contents(config_path('database.php'));
    preg_match("/'pgsql_migrate' => \[(.*?)\n        \],/s", $source, $block);

    expect($block)->not->toBe([]);
    expect($block[1])->not->toContain('PASSWORD')
        ->and($block[1])->not->toContain("'url'")
        ->and($block[1])->not->toContain("'read'")
        ->and($block[1])->not->toContain("'options'")
        ->and($block[1])->toContain("'password' => ''")
        ->and($block[1])->toContain("'driver' => 'pgsql'")
        ->and($block[1])->toContain("env('DB_MIGRATE_OWNER_ROLE')");

    $config = config('database.connections.pgsql_migrate');
    expect($config['driver'])->toBe('pgsql')
        ->and($config['password'])->toBe('')
        ->and($config)->not->toHaveKey('url')
        ->and(array_keys($config['server_options']))->toBe(['role'])
        ->and($config['search_path'])->toBe('public');
});

it('registers the guard for the migration connection only, never for the pgsql driver', function () {
    $extensions = (new ReflectionProperty(DatabaseManager::class, 'extensions'))->getValue(app('db'));

    expect($extensions)->toHaveKey(MigrationConnection::NAME)
        ->and($extensions)->not->toHaveKey('pgsql');

    foreach (['pgsql', 'pgsql_read'] as $name) {
        expect(config("database.connections.{$name}"))->not->toHaveKey('server_options');
    }
    expect(config('database.default'))->not->toBe(MigrationConnection::NAME);
});

it('refuses the unconfigured default before any connect', function () {
    expect(fgmcRefusal(config('database.connections.pgsql_migrate')))->toStartWith('pgsql_migrate refused: ');
});

it('fails closed before connecting on every misconfiguration', function (array $overrides, string $reason) {
    $config = fgmcConfig();
    foreach ($overrides as $key => $value) {
        data_set($config, $key, $value);
    }

    expect(fgmcRefusal($config))->toContain($reason);
})->with([
    'driver renamed' => [['driver' => 'pgsql_migrate'], 'driver must stay pgsql'],
    'a URL' => [['url' => 'pgsql://fgmc_login@localhost/fgmc_database'], 'URL is configured'],
    'read sub-array' => [['read' => ['host' => '127.0.0.1']], 'unsupported option'],
    'write sub-array' => [['write' => ['host' => '127.0.0.1']], 'unsupported option'],
    'PDO options' => [['options' => [PDO::ATTR_PERSISTENT => true]], 'unsupported option'],
    'connect_via' => [['connect_via_database' => 'other'], 'unsupported option'],
    'application_name' => [['application_name' => "x' host='127.0.0.1"], 'unsupported option'],
    'extra startup option' => [['server_options.search_path' => 'evil'], 'only server option'],
    'a password' => [['password' => 'fgmc-secret'], 'password is configured'],
    'a TCP host' => [['host' => '127.0.0.1'], 'Unix socket directory'],
    'no host' => [['host' => null], 'Unix socket directory'],
    'host injecting a second host' => [['host' => '/a;host=127.0.0.1'], 'Unix socket directory'],
    'host list' => [['host' => '/a,/b'], 'Unix socket directory'],
    'host with a space' => [['host' => '/a hostaddr=127.0.0.1'], 'Unix socket directory'],
    'port injecting a host' => [['port' => '5432;host=127.0.0.1'], 'port must be a plain number'],
    'port zero' => [['port' => '0'], 'port must be a plain number'],
    'port too large' => [['port' => '70000'], 'port must be a plain number'],
    'database injecting a host' => [['database' => "db' host='127.0.0.1"], 'database must be a plain name'],
    'database with a semicolon' => [['database' => 'db;host=127.0.0.1'], 'database must be a plain name'],
    'charset injection' => [['charset' => "utf8';x='"], 'charset must be utf8'],
    'sslmode injection' => [['sslmode' => 'disable;host=127.0.0.1'], 'sslmode is not a known mode'],
    'search_path changed' => [['search_path' => 'evil'], 'search_path must be public'],
    'no login role' => [['username' => null], 'login role is not configured'],
    'login with a quote' => [['username' => "fgmc'login"], 'login role is not configured'],
    'login postgres' => [['username' => 'postgres'], 'login role is not configured'],
    'login pg_ reserved' => [['username' => 'pg_monitor'], 'login role is not configured'],
    'no owner role' => [['server_options.role' => null], 'owner role is not configured'],
    'owner role with a space' => [['server_options.role' => 'fgmc owner'], 'owner role is not configured'],
    'owner role injecting an option' => [['server_options.role' => 'x -c search_path=evil'], 'owner role is not configured'],
    'owner role upper case' => [['server_options.role' => 'FGMC_OWNER'], 'owner role is not configured'],
    'owner role postgres' => [['server_options.role' => 'postgres'], 'owner role is not configured'],
    'owner role pg_ reserved' => [['server_options.role' => 'pg_write_all_data'], 'owner role is not configured'],
    'login equals owner' => [['username' => 'fgmc_owner'], 'must differ'],
    // Review round 2, finding 1: a declared name would make the runtime connection
    // the default inside every migration.
    'a declared name' => [['name' => 'pgsql'], 'unsupported option'],
]);

it('refuses before connecting when a libpq variable could redirect the session (review round 2, finding 6)', function (string $variable) {
    putenv($variable.'=fgmc-redirect');

    try {
        expect(fgmcRefusal(fgmcConfig()))->toContain('libpq environment variable')
            ->and(fgmcRefusal(fgmcConfig()))->not->toContain('fgmc-redirect');
    } finally {
        putenv($variable);
    }
})->with(['PGHOSTADDR', 'PGSERVICE', 'PGSERVICEFILE']);

it('refuses a built connection that does not keep its own name (review round 2, finding 1)', function () {
    $pdo = fgmcSessionPdo(fgmcGoodSession());
    $factory = Mockery::mock(ConnectionFactory::class);
    $factory->shouldReceive('make')->andReturnUsing(
        fn (array $config, string $name) => new PostgresConnection(fn () => $pdo, $config['database'], '', $config + ['name' => 'pgsql'])
    );
    MigrationConnection::register(app('db'), $factory);
    fgmcUse(fgmcConfig());

    expect(fn () => DB::connection('pgsql_migrate'))->toThrow(RuntimeException::class, 'must keep its own name');
});

it('never echoes a configured value in any of its refusals', function (array $break) {
    $probe = fgmcConfig([
        'host' => '/fgmcleakdir',
        'port' => '5999',
        'database' => 'fgmc_leak_db',
        'username' => 'fgmc_leak_login',
        'server_options' => ['role' => 'fgmc_leak_role'],
    ]);
    foreach ($break as $key => $value) {
        data_set($probe, $key, $value);
    }

    $message = (string) fgmcRefusal($probe);

    expect($message)->toStartWith('pgsql_migrate refused: ')
        ->and($message)->not->toContain('fgmcleak')
        ->and($message)->not->toContain('fgmc_leak')
        ->and($message)->not->toContain('5999');
})->with([
    'password' => [['password' => 'fgmc_leak_secret']],
    'host' => [['host' => 'fgmc_leak_tcp_host']],
    'port' => [['port' => 'fgmc_leak_port']],
    'database' => [['database' => 'fgmc_leak db']],
    'login' => [['username' => 'fgmc_leak LOGIN']],
    'role' => [['server_options.role' => 'fgmc_leak ROLE']],
    'same roles' => [['username' => 'fgmc_leak_role']],
    'unknown key' => [['options' => ['fgmc_leak_option']]],
]);

it('runs the guard again on reconnect', function () {
    fgmcUse(fgmcConfig());
    expect(DB::connection('pgsql_migrate')->getName())->toBe('pgsql_migrate');

    config(['database.connections.pgsql_migrate.server_options.role' => null]);

    expect(fn () => DB::reconnect('pgsql_migrate'))
        ->toThrow(RuntimeException::class, 'owner role is not configured');
});

it('verifies the session through the wired extension before the PDO is handed out', function (array|false $row, bool $accepted) {
    $pdo = fgmcSessionPdo($row);
    $factory = Mockery::mock(ConnectionFactory::class);
    $factory->shouldReceive('make')->andReturnUsing(
        fn (array $config, string $name) => new PostgresConnection(fn () => $pdo, $config['database'], '', $config + ['name' => $name])
    );
    MigrationConnection::register(app('db'), $factory);
    fgmcUse(fgmcConfig());

    $handOut = fn () => DB::connection('pgsql_migrate')->getPdo();

    if ($accepted) {
        expect($handOut())->toBe($pdo);
    } else {
        expect($handOut)->toThrow(RuntimeException::class, 'pgsql_migrate refused: ');
    }
})->with([
    'a verified session' => [fgmcGoodSession(), true],
    'the bare login (SET ROLE NONE)' => [fgmcGoodSession(['cur' => 'fgmc_login']), false],
    'another role' => [fgmcGoodSession(['cur' => 'fgmc_other']), false],
    'another login' => [fgmcGoodSession(['ses' => 'fgmc_other_login']), false],
    'over TCP' => [fgmcGoodSession(['transport' => 'tcp']), false],
    'password authentication' => [fgmcGoodSession(['auth' => 'scram-sha-256:fgmc_login']), false],
    'trust (no identity)' => [fgmcGoodSession(['auth' => '']), false],
    'a superuser login' => [fgmcGoodSession(['login_kind' => 'privileged']), false],
    'an inheriting membership' => [fgmcGoodSession(['membership' => 'inherit']), false],
    'no membership' => [fgmcGoodSession(['membership' => 'none']), false],
    'membership unknown' => [fgmcGoodSession(['membership' => null]), false],
    // Review round 2, findings 3 and 5.
    'an owner role that can log in or is elevated' => [fgmcGoodSession(['owner_kind' => 'privileged']), false],
    'a login that can create in public itself' => [fgmcGoodSession(['login_creates' => 'yes']), false],
    'no row' => [false, false],
]);

/** A migrator double whose current run uses $connection. */
function fgmcMigrator(?string $connection): Migrator
{
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('getConnection')->andReturn($connection);

    return $migrator;
}

/** A MigrationEnded event for a migration that declares $connection. */
function fgmcMigrationEnded(?string $connection = null): MigrationEnded
{
    $migration = new class($connection) extends Migration
    {
        public function __construct(?string $connection)
        {
            $this->connection = $connection;
        }
    };

    return new MigrationEnded($migration, 'up');
}

/** A database manager double whose pgsql_migrate catalog check returns $row. */
function fgmcCatalogDb(array $row): DatabaseManager
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')->once()->andReturn((object) $row);
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->with('pgsql_migrate')->andReturn($connection);

    return $db;
}

it('leaves every migration run on another connection untouched (review round 2, finding 4)', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    MigrationConnection::assertAfterMigration(fgmcMigrationEnded(), fgmcMigrator('pgsql'), $db);
    MigrationConnection::assertAfterMigration(fgmcMigrationEnded(), fgmcMigrator(null), $db);

    expect(true)->toBeTrue();
});

it('checks the server after every migration of a pgsql_migrate run and refuses what the scan cannot see (review round 2, finding 4)', function (?string $declared, array $row, ?string $refusal) {
    config(['database.connections.pgsql_migrate.server_options.role' => 'fgmc_owner']);
    $db = $declared === null || $declared === 'pgsql_migrate' ? fgmcCatalogDb($row) : Mockery::mock(DatabaseManager::class);

    $check = fn () => MigrationConnection::assertAfterMigration(fgmcMigrationEnded($declared), fgmcMigrator('pgsql_migrate'), $db);

    if ($refusal === null) {
        $check();
        expect(true)->toBeTrue();
    } else {
        expect($check)->toThrow(RuntimeException::class, $refusal);
    }
})->with([
    'still the owner, nothing foreign' => [null, ['cur' => 'fgmc_owner', 'foreign_owned' => 0], null],
    'the migration names pgsql_migrate itself' => ['pgsql_migrate', ['cur' => 'fgmc_owner', 'foreign_owned' => 0], null],
    'the migration chose the runtime connection' => ['pgsql', [], 'selected another connection'],
    'the session left the owner role' => [null, ['cur' => 'fgmc_login', 'foreign_owned' => 0], 'no longer runs as the owner role'],
    'an object owned by another role' => [null, ['cur' => 'fgmc_owner', 'foreign_owned' => 1], 'owned by another role'],
    'no answer' => [null, ['cur' => 'fgmc_owner'], 'owned by another role'],
]);

it('wires the backstop to MigrationEnded', function () {
    expect(app('events')->hasListeners(MigrationEnded::class))->toBeTrue();
});

it('leaves no schema dump that would load around the startup role', function () {
    foreach (['sql', 'dump'] as $extension) {
        expect(file_exists(database_path('schema/'.MigrationConnection::NAME."-schema.{$extension}")))->toBeFalse();
    }
});

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

// PB-7 (D4): where the role-test cluster is required (R6_ROLE_TEST_REQUIRED=1, set only by the CI step that
// starts it), group B never skips: a missing cluster fails, so it can never silently turn into skips (CG-17).
$fgmcSkip = fn () => ! fgmcClusterConfigured() && getenv('R6_ROLE_TEST_REQUIRED') !== '1';
$fgmcSkipReason = 'needs the private PostgreSQL 16 role-test cluster (R6_ROLE_TEST_*); no CI job provides it until PB-7';

it('fails closed when the role-test cluster is required but not configured (PB-7, D4)', function () {
    expect(getenv('R6_ROLE_TEST_REQUIRED') !== '1' || fgmcClusterConfigured())
        ->toBeTrue('R6_ROLE_TEST_REQUIRED=1 but the role-test cluster (R6_ROLE_TEST_*) is not configured');
});

it('runs every session as the owner role over a socket by peer, also after a reconnect and a RESET ROLE', function () {
    fgmcUse(fgmcClusterConfig());
    $connection = DB::connection('pgsql_migrate');

    $who = fn () => (array) $connection->selectOne(
        "select current_user as cur, session_user as ses, inet_server_addr() is null as via_socket, split_part(system_user, ':', 1) as method"
    );

    expect($who())->toBe(['cur' => fgmcEnv('OWNER'), 'ses' => fgmcEnv('MIGRATOR'), 'via_socket' => true, 'method' => 'peer']);

    DB::reconnect('pgsql_migrate');
    expect($who()['cur'])->toBe(fgmcEnv('OWNER'));

    // The startup option makes the owner role the session default, so RESET ROLE returns to it.
    $connection->statement('reset role');
    expect($who()['cur'])->toBe(fgmcEnv('OWNER'));
})->skip($fgmcSkip, $fgmcSkipReason);

it('fails closed after SET ROLE NONE: the bare login can create nothing', function () {
    fgmcUse(fgmcClusterConfig());
    $connection = DB::connection('pgsql_migrate');

    $connection->statement('set role none');
    expect($connection->selectOne('select current_user as cur')->cur)->toBe(fgmcEnv('MIGRATOR'));

    expect(fn () => $connection->statement('create table public.fgmc_role_none (id int)'))
        ->toThrow(PDOException::class, 'permission denied for schema public');
})->skip($fgmcSkip, $fgmcSkipReason);

it('is refused by the server for a role the login may not assume, and creates nothing', function () {
    fgmcUse(fgmcClusterConfig(fgmcEnv('NON_MEMBER')));

    expect(fn () => DB::connection('pgsql_migrate')->statement('create table public.fgmc_should_not_exist (id int)'))
        ->toThrow(PDOException::class, 'permission denied to set role');

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
    // Review round 2, finding 8: every relation kind (composite and foreign tables
    // included), every type, and the schema itself; the earlier type filter
    // (typtype 'c' with typrelid 0) could never match.
    $foreignRelations = $pdo->query(
        "select c.relname || ':' || pg_get_userbyid(c.relowner) from pg_class c join pg_namespace n on n.oid = c.relnamespace
          where n.nspname = 'public' and pg_get_userbyid(c.relowner) <> ".$pdo->quote($owner)
    )->fetchAll(PDO::FETCH_COLUMN);
    $foreignFunctions = $pdo->query(
        "select p.proname from pg_proc p join pg_namespace n on n.oid = p.pronamespace
          where n.nspname = 'public' and pg_get_userbyid(p.proowner) <> ".$pdo->quote($owner)
    )->fetchAll(PDO::FETCH_COLUMN);
    $foreignTypes = $pdo->query(
        "select t.typname from pg_type t join pg_namespace n on n.oid = t.typnamespace
          where n.nspname = 'public' and pg_get_userbyid(t.typowner) <> ".$pdo->quote($owner)
    )->fetchAll(PDO::FETCH_COLUMN);
    $schemaOwner = $pdo->query("select pg_get_userbyid(nspowner) from pg_namespace where nspname = 'public'")->fetchColumn();

    expect($relations)->toBeGreaterThan(186)
        ->and($foreignRelations)->toBe([])
        ->and($foreignFunctions)->toBe([])
        ->and($foreignTypes)->toBe([])
        ->and($schemaOwner)->toBe($owner);
})->skip($fgmcSkip, $fgmcSkipReason);

it('rolls back a migration that leaves the owner role through dynamic SQL, by the backstop (review round 2, finding 4)', function () {
    fgmcUse(fgmcClusterConfig());
    $dir = sys_get_temp_dir().'/fgmc-backstop-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $file = $dir.'/2099_01_01_000001_fgmc_backstop.php';
    // No static scan can read this role change: the statement is assembled at run time.
    file_put_contents($file, <<<'PHP'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\DB;
        return new class extends Migration {
            public function up(): void {
                DB::statement('create table public.fgmc_backstop (id int)');
                DB::statement("do \$fgmc\$ begin execute format('%s %s none', 'set', 'role'); end \$fgmc\$");
            }
        };
        PHP);

    try {
        expect(fn () => Artisan::call('migrate', ['--database' => 'pgsql_migrate', '--path' => $dir, '--realpath' => true, '--force' => true]))
            ->toThrow(RuntimeException::class, 'no longer runs as the owner role');

        $pdo = fgmcPdo(fgmcEnv('RUNTIME'), (string) fgmcEnv('RUNTIME_PASSWORD'));
        expect($pdo->query("select to_regclass('public.fgmc_backstop') is not null")->fetchColumn())->toBeFalse()
            ->and((int) $pdo->query("select count(*) from public.migrations where migration = '2099_01_01_000001_fgmc_backstop'")->fetchColumn())->toBe(0);
    } finally {
        @unlink($file);
        @rmdir($dir);
    }
})->skip($fgmcSkip, $fgmcSkipReason);

it('exempts exactly the members of pg_stat_statements in public from the backstop, nothing else (R6-B4)', function () {
    if (fgmcEnv('SUPERUSER') === null) {
        expect(getenv('R6_ROLE_TEST_REQUIRED') !== '1')->toBeTrue('R6_ROLE_TEST_REQUIRED=1 but R6_ROLE_TEST_SUPERUSER is not configured');
        $this->markTestSkipped('needs R6_ROLE_TEST_SUPERUSER');
    }
    fgmcUse(fgmcClusterConfig());
    $super = fgmcPdo((string) fgmcEnv('SUPERUSER'));
    $schemaOf = fn (): ?string => $super->query("select n.nspname from pg_extension e join pg_namespace n on n.oid = e.extnamespace where e.extname = 'pg_stat_statements'")->fetchColumn() ?: null;
    $previous = $schemaOf();
    $super->exec($previous === null ? 'CREATE EXTENSION pg_stat_statements SCHEMA public' : 'ALTER EXTENSION pg_stat_statements SET SCHEMA public');

    $dir = sys_get_temp_dir().'/fgmc-ext-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $migrate = function (string $name, string $table) use ($dir): void {
        file_put_contents("{$dir}/{$name}.php", "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\nreturn new class extends Migration { public function up(): void { DB::statement('create table public.{$table} (id int)'); } };\n");
        Artisan::call('migrate', ['--database' => 'pgsql_migrate', '--path' => $dir, '--realpath' => true, '--force' => true]);
    };
    try {
        // The superuser-owned extension members in public do not stop a migration.
        $migrate('2099_01_01_000101_fgmc_ext_ok', 'fgmc_ext_ok');
        expect((bool) $super->query("select to_regclass('public.fgmc_ext_ok') is not null")->fetchColumn())->toBeTrue();

        // Any other foreign-owned object in public still does, and the migration rolls back.
        $super->exec('CREATE FUNCTION public.fgmc_foreign() RETURNS int LANGUAGE sql AS $$ select 1 $$');
        expect(fn () => $migrate('2099_01_01_000102_fgmc_ext_refused', 'fgmc_ext_refused'))
            ->toThrow(RuntimeException::class, 'an object in public is owned by another role');
        expect((bool) $super->query("select to_regclass('public.fgmc_ext_refused') is not null")->fetchColumn())->toBeFalse();
    } finally {
        $super->exec('DROP FUNCTION IF EXISTS public.fgmc_foreign(); DROP TABLE IF EXISTS public.fgmc_ext_ok, public.fgmc_ext_refused');
        $super->exec("DELETE FROM public.migrations WHERE migration LIKE '2099_01_01_0001%_fgmc_ext_%'");
        $super->exec($previous === null ? 'DROP EXTENSION pg_stat_statements' : 'ALTER EXTENSION pg_stat_statements SET SCHEMA "'.$previous.'"');
        array_map('unlink', glob("{$dir}/*.php") ?: []);
        @rmdir($dir);
    }
})->skip($fgmcSkip, $fgmcSkipReason);
