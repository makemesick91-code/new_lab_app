<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B3 (rule 245 #6; owner decision D-Q3-b; Design Lock
| §12.5 and §13.4 N-9): foundation:db-privilege-apply (per-deploy runtime grants,
| dry run by default, --print-sql, --apply on pgsql_migrate only) and the
| read-only foundation:db-privilege-check.
|
| Group A runs everywhere: the SQL the plan prints, its determinism, its exact
| rollback, every refusal, and (on PostgreSQL) the dry run and --print-sql.
|
| Group B needs the private PostgreSQL 16 role-test cluster (R6_ROLE_TEST_*,
| plus R6_ROLE_TEST_SUPERUSER for the drift injections). No CI job provides it
| until PB-7, so in CI group B SKIPS and is never reported as a pass.
*/

use App\Support\Database\DatabasePrivilegeCheck;
use App\Support\Database\RuntimeGrantPlan;
use App\Support\Database\RuntimePrivilegeClass;
use App\Support\Database\RuntimePrivilegeRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function fgdpRoles(?string $runtime = 'fgdp_runtime', ?string $owner = 'fgdp_owner'): void
{
    config(['runtime_privilege_classes.roles' => ['runtime' => $runtime, 'owner' => $owner]]);
}

/** @return array{0: int, 1: string} exit code and output */
function fgdpRun(string $command, array $options = []): array
{
    $code = Artisan::call($command, $options);

    return [$code, Artisan::output()];
}

function fgdpEntries(): array
{
    return [
        'zz_full' => ['class' => RuntimePrivilegeClass::FULL_DML, 'columns' => []],
        'aa_ledger' => ['class' => RuntimePrivilegeClass::APPEND_ONLY_WITH_COLUMNS, 'columns' => ['updated_at', 'batch_id']],
        'mm_append' => ['class' => RuntimePrivilegeClass::APPEND_ONLY, 'columns' => []],
        'migrations' => ['class' => RuntimePrivilegeClass::READ_ONLY_RUNTIME, 'columns' => []],
    ];
}

// ---------------------------------------------------------------- Group A

it('prints exactly the class grants, sorted, quoted and table by table', function () {
    expect(RuntimeGrantPlan::forwardStatements(fgdpEntries(), ['zz_full_id_seq', 'aa_ledger_id_seq'], 'fgdp_runtime'))->toBe([
        'REVOKE ALL ON TABLE public."aa_ledger" FROM "fgdp_runtime"',
        'GRANT SELECT, INSERT ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'GRANT UPDATE ("batch_id", "updated_at") ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."migrations" FROM "fgdp_runtime"',
        'GRANT SELECT ON TABLE public."migrations" TO "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."mm_append" FROM "fgdp_runtime"',
        'GRANT SELECT, INSERT ON TABLE public."mm_append" TO "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."zz_full" FROM "fgdp_runtime"',
        'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public."zz_full" TO "fgdp_runtime"',
        'REVOKE ALL ON SEQUENCE public."aa_ledger_id_seq" FROM "fgdp_runtime"',
        'GRANT USAGE, SELECT ON SEQUENCE public."aa_ledger_id_seq" TO "fgdp_runtime"',
        'REVOKE ALL ON SEQUENCE public."zz_full_id_seq" FROM "fgdp_runtime"',
        'GRANT USAGE, SELECT ON SEQUENCE public."zz_full_id_seq" TO "fgdp_runtime"',
    ]);
});

it('never grants a sequence UPDATE, a table TRUNCATE, REFERENCES or TRIGGER, or DELETE outside FULL_DML', function () {
    $sql = implode("\n", RuntimeGrantPlan::forwardStatements(fgdpEntries(), ['zz_full_id_seq'], 'fgdp_runtime'));

    expect($sql)->not->toContain('TRUNCATE')
        ->and($sql)->not->toContain('REFERENCES')
        ->and($sql)->not->toContain('TRIGGER')
        ->and($sql)->not->toMatch('/GRANT [^\n]*UPDATE[^\n]*SEQUENCE/')
        ->and(substr_count($sql, 'DELETE'))->toBe(1);
});

it('prints the same SQL whatever order its inputs come in', function () {
    $entries = fgdpEntries();
    $reversed = array_reverse($entries, true);

    expect(RuntimeGrantPlan::forwardStatements($reversed, ['b_seq', 'a_seq', 'a_seq'], 'fgdp_runtime'))
        ->toBe(RuntimeGrantPlan::forwardStatements($entries, ['a_seq', 'b_seq'], 'fgdp_runtime'));
});

it('refuses any name that is not a plain lower-case identifier', function (string $name) {
    expect(fn () => RuntimeGrantPlan::forwardStatements(fgdpEntries(), [], $name))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RuntimeGrantPlan::forwardStatements([$name => ['class' => 'FULL_DML', 'columns' => []]], [], 'fgdp_runtime'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RuntimeGrantPlan::script([], $name, 'forward'))->toThrow(InvalidArgumentException::class);
})->with(['Runtime', 'a-b', 'x"; drop table users; --', '', str_repeat('a', 64), '1abc', 'a b', "a\nb"]);

it('wraps the SQL in one transaction behind an owner-role guard, with no timestamp', function () {
    $script = RuntimeGrantPlan::script(['GRANT SELECT ON TABLE public."t" TO "r"'], 'fgdp_owner', 'forward');

    expect($script)->toStartWith('-- foundation:db-privilege-apply forward SQL')
        ->and($script)->toContain("BEGIN;\nSET LOCAL lock_timeout = '5s';\nSET LOCAL statement_timeout = '120s';")
        ->and($script)->toContain("IF current_user <> 'fgdp_owner' THEN RAISE EXCEPTION")
        ->and($script)->toEndWith("GRANT SELECT ON TABLE public.\"t\" TO \"r\";\nCOMMIT;\n")
        ->and($script)->not->toMatch('/20\d\d-\d\d-\d\d/')
        ->and(hash('sha256', $script))->toBe(hash('sha256', RuntimeGrantPlan::script(['GRANT SELECT ON TABLE public."t" TO "r"'], 'fgdp_owner', 'forward')));
});

it('builds the exact rollback of the runtime grants, grant option and columns included', function () {
    $current = [
        'zz_full' => ['table' => ['SELECT' => false, 'DELETE' => false, 'UPDATE' => true], 'columns' => []],
        'aa_ledger' => ['table' => ['SELECT' => false], 'columns' => ['updated_at' => ['UPDATE' => false], 'batch_id' => ['UPDATE' => false, 'REFERENCES' => true]]],
        'zz_full_id_seq' => ['table' => ['USAGE' => false, 'SELECT' => false], 'columns' => []],
    ];

    expect(RuntimeGrantPlan::rollbackStatements(fgdpEntries(), ['zz_full_id_seq'], $current, 'fgdp_runtime'))->toBe([
        'REVOKE ALL ON TABLE public."aa_ledger" FROM "fgdp_runtime"',
        'GRANT SELECT ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'GRANT UPDATE ("batch_id") ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'GRANT REFERENCES ("batch_id") ON TABLE public."aa_ledger" TO "fgdp_runtime" WITH GRANT OPTION',
        'GRANT UPDATE ("updated_at") ON TABLE public."aa_ledger" TO "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."migrations" FROM "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."mm_append" FROM "fgdp_runtime"',
        'REVOKE ALL ON TABLE public."zz_full" FROM "fgdp_runtime"',
        'GRANT SELECT, DELETE ON TABLE public."zz_full" TO "fgdp_runtime"',
        'GRANT UPDATE ON TABLE public."zz_full" TO "fgdp_runtime" WITH GRANT OPTION',
        'REVOKE ALL ON SEQUENCE public."zz_full_id_seq" FROM "fgdp_runtime"',
        'GRANT SELECT, USAGE ON SEQUENCE public."zz_full_id_seq" TO "fgdp_runtime"',
    ]);
});

it('refuses a rollback it could not make exact', function () {
    $current = ['zz_full' => ['table' => ['SOMETHING_NEW' => false], 'columns' => []]];

    expect(fn () => RuntimeGrantPlan::rollbackStatements(fgdpEntries(), [], $current, 'fgdp_runtime'))
        ->toThrow(RuntimeException::class, 'cannot restore exactly');
});

it('changes only the objects whose runtime grants differ from their class', function () {
    $entries = fgdpEntries();
    $expected = RuntimeGrantPlan::expectedGrants($entries, ['zz_full_id_seq']);
    $current = $expected;
    $current['mm_append']['table']['UPDATE'] = false;      // one privilege too many
    unset($current['zz_full_id_seq']);                     // nothing granted yet
    $current['aa_ledger']['columns']['batch_id'] = [];     // a listed column without its grant

    $changed = RuntimeGrantPlan::changedObjects($expected, $current);
    expect($changed)->toBe(['aa_ledger', 'mm_append', 'zz_full_id_seq']);

    $statements = RuntimeGrantPlan::statementsFor(RuntimeGrantPlan::forwardStatements($entries, ['zz_full_id_seq'], 'fgdp_runtime'), $changed);
    expect($statements)->toHaveCount(7)
        ->and(implode("\n", $statements))->not->toContain('"zz_full"')
        ->and(implode("\n", $statements))->not->toContain('"migrations"')
        ->and(RuntimeGrantPlan::changedObjects($expected, $expected))->toBe([]);
});

it('refuses conflicting or unsafe options before it reads anything', function (array $options, string $reason) {
    fgdpRoles();
    [$code, $output] = fgdpRun('foundation:db-privilege-apply', $options);

    expect($code)->toBe(1)->and($output)->toContain($reason);
})->with([
    'apply with print-sql' => [['--apply' => true, '--print-sql' => true], '--apply and --print-sql exclude each other'],
    'rollback alone' => [['--rollback' => true], '--rollback only prints'],
    'apply elsewhere' => [['--apply' => true, '--database' => 'pgsql'], '--apply runs only on pgsql_migrate'],
]);

it('refuses unconfigured, malformed or identical roles without echoing them', function (?string $runtime, ?string $owner, string $reason) {
    fgdpRoles($runtime, $owner);
    [$code, $output] = fgdpRun('foundation:db-privilege-apply');

    expect($code)->toBe(1)->and($output)->toContain($reason);
    foreach (array_filter([$runtime, $owner]) as $value) {
        expect($output)->not->toContain($value);
    }
})->with([
    'no runtime' => [null, 'fgdp_owner', 'the runtime role is not configured'],
    'no owner' => ['fgdp_runtime', null, 'the owner role is not configured'],
    'malformed runtime' => ['Fgdp-Runtime', 'fgdp_owner', 'the runtime role is not configured'],
    'malformed owner' => ['fgdp_runtime', 'x"; drop', 'the owner role is not configured'],
    'identical' => ['fgdp_same', 'fgdp_same', 'must differ'],
]);

it('cannot verify without PostgreSQL or an owner role, and never echoes the role', function (?string $owner) {
    fgdpRoles('fgdp_runtime', $owner);
    [$code, $output] = fgdpRun('foundation:db-privilege-check');

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($output)->toContain('DB PRIVILEGE CHECK: CANNOT VERIFY');
    if ($owner !== null) {
        expect($output)->not->toContain($owner);
    }
})->with([
    'no owner configured' => [null],
    'an owner that does not exist (or no PostgreSQL)' => ['fgdp_no_such_owner_role'],
]);

it('dry-runs on PostgreSQL: executes nothing, reports the plan and that apply would refuse before provisioning', function () {
    fgdpRoles('fgdp_runtime', 'fgdp_no_such_owner_role');
    $before = DB::selectOne("select count(*) as n from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relacl is not null")->n;

    [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--json' => true]);
    $report = json_decode($output, true);

    expect($code)->toBe(0)
        ->and($report['mode'])->toBe('dry-run')
        ->and($report['tables'])->toBeGreaterThanOrEqual(186)
        ->and($report['classes']['APPEND_ONLY_WITH_COLUMNS'] ?? 0)->toBe(3)
        ->and($report['apply_would_refuse'])->toBeTrue()
        ->and($report['objects_to_change'])->toBe($report['tables'] + $report['sequences'])
        ->and(DB::selectOne("select count(*) as n from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relacl is not null")->n)->toBe($before);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs PostgreSQL as the test database');

it('prints a forward SQL whose SHA-256 is stable and matches the dry run', function () {
    fgdpRoles('fgdp_runtime', 'fgdp_owner');

    [$c1, $first] = fgdpRun('foundation:db-privilege-apply', ['--print-sql' => true]);
    [$c2, $second] = fgdpRun('foundation:db-privilege-apply', ['--print-sql' => true]);
    [, $dry] = fgdpRun('foundation:db-privilege-apply', ['--json' => true]);

    expect($c1)->toBe(0)->and($c2)->toBe(0)
        ->and(hash('sha256', $first))->toBe(hash('sha256', $second))
        ->and(json_decode($dry, true)['forward_sha256'])->toBe(hash('sha256', $first))
        ->and($first)->toContain('GRANT UPDATE ("inventory_batch_id", "updated_at") ON TABLE public."trx_inventory_movements" TO "fgdp_runtime";')
        ->and($first)->toContain('GRANT SELECT ON TABLE public."migrations" TO "fgdp_runtime";');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'needs PostgreSQL as the test database');

it('is declared a critical-gate suite (CG-19)', function () {
    expect(config('ci_runner.critical_gate_mandatory_suites'))->toContain('tests/Feature/Foundation/FoundationGovernanceDbPrivilegeTest.php');
});

// ---------------------------------------------------------------- Group B

function fgdpEnv(string $key): ?string
{
    $value = getenv('R6_ROLE_TEST_'.$key);

    return $value === false || $value === '' ? null : $value;
}

function fgdpClusterConfigured(): bool
{
    foreach (['SOCKET_DIR', 'PORT', 'DATABASE', 'MIGRATOR', 'OWNER', 'RUNTIME', 'RUNTIME_PASSWORD', 'SUPERUSER'] as $key) {
        if (fgdpEnv($key) === null) {
            return false;
        }
    }

    return true;
}

/** Points pgsql_migrate, a runtime connection and the role config at the private cluster. */
function fgdpUseCluster(): void
{
    $base = [
        'driver' => 'pgsql', 'host' => fgdpEnv('SOCKET_DIR'), 'port' => fgdpEnv('PORT'), 'database' => fgdpEnv('DATABASE'),
        'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true, 'search_path' => 'public', 'sslmode' => 'disable',
    ];
    config([
        'database.connections.pgsql_migrate' => $base + ['username' => fgdpEnv('MIGRATOR'), 'password' => '', 'server_options' => ['role' => fgdpEnv('OWNER')]],
        'database.connections.fgdp_runtime' => $base + ['username' => fgdpEnv('RUNTIME'), 'password' => fgdpEnv('RUNTIME_PASSWORD')],
    ]);
    DB::purge('pgsql_migrate');
    DB::purge('fgdp_runtime');
    fgdpRoles(fgdpEnv('RUNTIME'), fgdpEnv('OWNER'));
}

function fgdpPdo(string $user, string $password = ''): PDO
{
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', fgdpEnv('SOCKET_DIR'), fgdpEnv('PORT'), fgdpEnv('DATABASE'));

    return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function fgdpSuper(): PDO
{
    return fgdpPdo(fgdpEnv('SUPERUSER'));
}

function fgdpRuntimePdo(): PDO
{
    return fgdpPdo(fgdpEnv('RUNTIME'), (string) fgdpEnv('RUNTIME_PASSWORD'));
}

/** SQLSTATE of $sql on $pdo, or 'ok'. */
function fgdpState(PDO $pdo, string $sql): string
{
    try {
        $pdo->exec($sql);

        return 'ok';
    } catch (PDOException $e) {
        return (string) $e->getCode();
    }
}

/** $sql with the design role names substituted (:runtime, :owner, :db, :super). */
function fgdpSql(string $sql): string
{
    return strtr($sql, [':runtime' => fgdpEnv('RUNTIME'), ':owner' => fgdpEnv('OWNER'), ':db' => fgdpEnv('DATABASE'), ':super' => fgdpEnv('SUPERUSER')]);
}

/**
 * The catalog digest (ownership, normalised ACLs of every relation, column,
 * schema and the database, memberships, the runtime's attributes, function
 * owners and settings, trigger states), read as the superuser.
 */
function fgdpDigest(): string
{
    $rows = fgdpSuper()->query(<<<'SQL'
        select 'rel|' || c.relname || '|' || c.relkind::text || '|' || pg_get_userbyid(c.relowner) || '|' ||
               coalesce((select string_agg(pg_get_userbyid(a.grantor) || '>' || case when a.grantee = 0 then 'PUBLIC' else pg_get_userbyid(a.grantee) end || ':' || a.privilege_type || ':' || a.is_grantable, ',' order by 1)
                         from aclexplode(coalesce(c.relacl, acldefault((case when c.relkind = 'S' then 's' else 'r' end)::"char", c.relowner))) a), '')
          from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public'
        union all
        select 'col|' || c.relname || '.' || att.attname || '|' ||
               (select string_agg(case when a.grantee = 0 then 'PUBLIC' else pg_get_userbyid(a.grantee) end || ':' || a.privilege_type || ':' || a.is_grantable, ',' order by 1) from aclexplode(att.attacl) a)
          from pg_attribute att join pg_class c on c.oid = att.attrelid join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = 'public' and att.attacl is not null
        union all
        select 'nsp|' || pg_get_userbyid(nspowner) || '|' || coalesce(array_to_string(nspacl, ','), '') from pg_namespace where nspname = 'public'
        union all
        select 'db|' || pg_get_userbyid(datdba) || '|' || coalesce(array_to_string(datacl, ','), '') from pg_database where datname = current_database()
        union all
        select 'mem|' || pg_get_userbyid(roleid) || '>' || pg_get_userbyid(member) || '|' || admin_option || inherit_option || set_option from pg_auth_members
        union all
        select 'role|' || rolname || '|' || rolsuper || rolcreaterole || rolcreatedb || rolbypassrls || rolreplication || rolcanlogin from pg_roles where rolname not like 'pg\_%'
        union all
        select 'fn|' || p.proname || '|' || pg_get_userbyid(p.proowner) || '|' || p.prosecdef || '|' || coalesce(array_to_string(p.proconfig, ','), '') || '|' || coalesce(array_to_string(p.proacl, ','), '')
          from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public'
        union all
        select 'trg|' || c.relname || '.' || t.tgname || '|' || t.tgenabled::text from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and not t.tgisinternal
        order by 1
        SQL)->fetchAll(PDO::FETCH_COLUMN);

    return hash('sha256', implode("\n", $rows));
}

/** The schema migrated as the owner role (once); returns nothing. */
function fgdpMigrated(): void
{
    expect(Artisan::call('migrate', ['--database' => 'pgsql_migrate', '--force' => true]))->toBe(0);
}

/**
 * The state before any R6-B3 grant: the runtime holds only SELECT on every
 * table, as the cluster's default privileges gave it.
 */
function fgdpUngranted(): void
{
    $super = fgdpSuper();
    $super->exec(fgdpSql('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM ":runtime"; REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM ":runtime"; GRANT SELECT ON ALL TABLES IN SCHEMA public TO ":runtime"'));
}

/**
 * The state after provisioning (R6-D does this with psql as the superuser):
 * every trigger function pins search_path; pg_stat_statements is installed;
 * then the per-deploy grants are applied.
 */
function fgdpProvisionedAndGranted(): void
{
    $super = fgdpSuper();
    foreach ($super->query("select distinct p.oid::regprocedure::text from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace join pg_proc p on p.oid = t.tgfoid where n.nspname = 'public' and not t.tgisinternal")->fetchAll(PDO::FETCH_COLUMN) as $function) {
        // These trigger functions reference no table (they only raise or compare
        // NEW and OLD), so pinning pg_catalog is safe for every one of them.
        $super->exec("ALTER FUNCTION {$function} SET search_path = pg_catalog, pg_temp");
    }
    // Its own schema, as an administrator would install it: in `public` its views
    // would be objects the owner role does not own.
    $super->exec(fgdpSql('CREATE SCHEMA IF NOT EXISTS fgdp_ext; CREATE EXTENSION IF NOT EXISTS pg_stat_statements SCHEMA fgdp_ext; GRANT USAGE ON SCHEMA fgdp_ext TO ":runtime"'));
    [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);
    expect($code)->toBe(0, $output);
}

$fgdpSkip = fn () => ! fgdpClusterConfigured();
$fgdpSkipReason = 'needs the private PostgreSQL 16 role-test cluster (R6_ROLE_TEST_*, with SUPERUSER); no CI job provides it until PB-7';

afterEach(function () {
    DB::purge('pgsql_migrate');
    DB::purge('fgdp_runtime');
});

it('reports drift before any grant, as the runtime, and names no role', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpUngranted();

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);

    expect($code)->toBe(DatabasePrivilegeCheck::DRIFT)
        ->and($output)->toContain('FAIL  table users (FULL_DML): the runtime lacks INSERT')
        ->and($output)->toContain('FAIL  table trx_inventory_movements: the runtime cannot UPDATE column inventory_batch_id')
        ->and($output)->toContain('PASS  the runtime holds no elevated attribute')
        ->and($output)->toContain('PASS  the database is owned by a superuser (N-9)')
        ->and($output)->toContain('DB PRIVILEGE CHECK: DRIFT')
        ->and($output)->not->toContain(fgdpEnv('RUNTIME'))
        ->and($output)->not->toContain(fgdpEnv('OWNER'));
})->skip($fgdpSkip, $fgdpSkipReason);

it('dry-runs on the cluster without changing the catalog', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpUngranted();
    $before = fgdpDigest();

    [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--database' => 'pgsql_migrate', '--json' => true]);
    $report = json_decode($output, true);

    expect($code)->toBe(0)
        ->and($report['apply_would_refuse'])->toBeFalse()
        // Every object differs except the read-only table: the runtime already holds
        // exactly SELECT there, which is its class.
        ->and($report['objects_to_change'])->toBe($report['tables'] + $report['sequences'] - $report['classes']['READ_ONLY_RUNTIME'])
        ->and($report['changed'])->not->toContain('migrations')
        ->and(fgdpDigest())->toBe($before);
})->skip($fgdpSkip, $fgdpSkipReason);

it('applies idempotently and its printed rollback restores the pre-state digest exactly', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpUngranted();
    $pre = fgdpDigest();

    [, $rollback] = fgdpRun('foundation:db-privilege-apply', ['--database' => 'pgsql_migrate', '--print-sql' => true, '--rollback' => true]);
    expect(fgdpDigest())->toBe($pre);

    [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);
    expect($code)->toBe(0, $output)->and($output)->toContain('APPLIED:');
    $granted = fgdpDigest();
    expect($granted)->not->toBe($pre);

    // Idempotent: a second run changes no object and leaves the same digest.
    [$again, $againOutput] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);
    expect($again)->toBe(0)->and($againOutput)->toContain('APPLIED: 0 statement(s) for 0 object(s)')
        ->and(fgdpDigest())->toBe($granted);

    // The printed rollback, run as the owner role, restores the pre-state exactly.
    DB::connection('pgsql_migrate')->unprepared($rollback);
    expect(fgdpDigest())->toBe($pre);

    // Forward again reaches the same granted state.
    expect(fgdpRun('foundation:db-privilege-apply', ['--apply' => true])[0])->toBe(0)
        ->and(fgdpDigest())->toBe($granted);
})->skip($fgdpSkip, $fgdpSkipReason);

it('matches once provisioned and granted', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);

    expect($code)->toBe(DatabasePrivilegeCheck::MATCH, $output)
        ->and($output)->toContain('DB PRIVILEGE CHECK: MATCH')
        ->and($output)->not->toContain('FAIL');
})->skip($fgdpSkip, $fgdpSkipReason);

it('fails on each injected drift and matches again once it is undone', function (string $inject, string $undo, string $expected) {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();

    $super->exec(fgdpSql($inject));
    try {
        [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime']);
        expect($code)->toBe(DatabasePrivilegeCheck::DRIFT, $output)
            ->and($output)->toContain($expected)
            ->and($output)->not->toContain(fgdpEnv('RUNTIME'))
            ->and($output)->not->toContain(fgdpEnv('OWNER'));
    } finally {
        $super->exec(fgdpSql($undo));
    }

    DB::purge('fgdp_runtime');
    expect(fgdpRun('foundation:db-privilege-check', ['--database' => 'fgdp_runtime'])[0])->toBe(DatabasePrivilegeCheck::MATCH);
})->with([
    'UPDATE on an append-only table' => ['GRANT UPDATE ON public.trx_rme_record_versions TO ":runtime"', 'REVOKE UPDATE ON public.trx_rme_record_versions FROM ":runtime"', 'table trx_rme_record_versions (APPEND_ONLY): the runtime holds UPDATE'],
    'DELETE on an append-only table through PUBLIC' => ['GRANT DELETE ON public.trx_rme_record_versions TO PUBLIC', 'REVOKE DELETE ON public.trx_rme_record_versions FROM PUBLIC', 'the runtime holds DELETE'],
    'TRUNCATE on a FULL_DML table' => ['GRANT TRUNCATE ON public.users TO ":runtime"', 'REVOKE TRUNCATE ON public.users FROM ":runtime"', 'table users (FULL_DML): the runtime holds TRUNCATE'],
    'a missing FULL_DML privilege' => ['REVOKE INSERT ON public.users FROM ":runtime"', 'GRANT INSERT ON public.users TO ":runtime"', 'table users (FULL_DML): the runtime lacks INSERT'],
    'INSERT on the read-only table' => ['GRANT INSERT ON public.migrations TO ":runtime"', 'REVOKE INSERT ON public.migrations FROM ":runtime"', 'table migrations (READ_ONLY_RUNTIME): the runtime holds INSERT'],
    'an unlisted column UPDATE' => ['GRANT UPDATE (id) ON public.trx_inventory_movements TO ":runtime"', 'REVOKE UPDATE (id) ON public.trx_inventory_movements FROM ":runtime"', 'table trx_inventory_movements: the runtime can UPDATE column id'],
    'a column UPDATE on an append-only table' => ['GRANT UPDATE (id) ON public.trx_rme_record_versions TO ":runtime"', 'REVOKE UPDATE (id) ON public.trx_rme_record_versions FROM ":runtime"', 'the runtime holds UPDATE on a column'],
    'a sequence setval' => ['GRANT UPDATE ON SEQUENCE public.users_id_seq TO ":runtime"', 'REVOKE UPDATE ON SEQUENCE public.users_id_seq FROM ":runtime"', 'sequence users_id_seq: the runtime holds UPDATE (setval)'],
    'CREATE on public' => ['GRANT CREATE ON SCHEMA public TO ":runtime"', 'REVOKE CREATE ON SCHEMA public FROM ":runtime"', 'the runtime can CREATE in schema public'],
    'CREATE on the database' => ['GRANT CREATE ON DATABASE ":db" TO ":runtime"', 'REVOKE CREATE ON DATABASE ":db" FROM ":runtime"', 'the runtime can CREATE in the database'],
    'an elevated attribute' => ['ALTER ROLE ":runtime" CREATEDB', 'ALTER ROLE ":runtime" NOCREATEDB', 'the runtime holds CREATEDB'],
    'membership of the owner role' => ['GRANT ":owner" TO ":runtime"', 'REVOKE ":owner" FROM ":runtime"', 'the runtime is a member of the owner role'],
    'all of pg_monitor' => ['GRANT pg_monitor TO ":runtime"', 'REVOKE pg_monitor FROM ":runtime"', 'the runtime is a member of pg_monitor'],
    'server file writes' => ['GRANT pg_write_server_files TO ":runtime"', 'REVOKE pg_write_server_files FROM ":runtime"', 'the runtime is a member of pg_write_server_files'],
    'an object the runtime owns' => ['CREATE FUNCTION public.fgdp_owned() RETURNS int LANGUAGE sql AS $$ select 1 $$; ALTER FUNCTION public.fgdp_owned() OWNER TO ":runtime"', 'DROP FUNCTION public.fgdp_owned()', 'the runtime owns 1 object(s) in this database'],
    'a disabled guard trigger' => ['ALTER TABLE public.trx_rme_record_versions DISABLE TRIGGER trx_rme_record_versions_immutable', 'ALTER TABLE public.trx_rme_record_versions ENABLE TRIGGER trx_rme_record_versions_immutable', 'trigger trx_rme_record_versions_immutable on trx_rme_record_versions does not fire'],
    'an unpinned trigger function' => ['ALTER FUNCTION public.trx_rme_record_versions_immutable() RESET search_path', 'ALTER FUNCTION public.trx_rme_record_versions_immutable() SET search_path = pg_catalog, pg_temp', 'trigger function trx_rme_record_versions_immutable does not pin search_path'],
    'a SECURITY DEFINER trigger function' => ['ALTER FUNCTION public.trx_rme_record_versions_immutable() SECURITY DEFINER', 'ALTER FUNCTION public.trx_rme_record_versions_immutable() SECURITY INVOKER', 'trigger function trx_rme_record_versions_immutable is SECURITY DEFINER'],
    'a trigger function another role owns' => ['ALTER FUNCTION public.trx_rme_record_versions_immutable() OWNER TO ":super"', 'ALTER FUNCTION public.trx_rme_record_versions_immutable() OWNER TO ":owner"', 'trigger function trx_rme_record_versions_immutable is not owned by the owner role'],
    'a database owned by a non-superuser (N-9)' => ['ALTER DATABASE ":db" OWNER TO ":owner"', 'ALTER DATABASE ":db" OWNER TO ":super"', 'the database owner is not a superuser (N-9)'],
    'a statistics reset' => ['GRANT EXECUTE ON FUNCTION fgdp_ext.pg_stat_statements_reset(oid, oid, bigint) TO ":runtime"', 'REVOKE EXECUTE ON FUNCTION fgdp_ext.pg_stat_statements_reset(oid, oid, bigint) FROM ":runtime"', 'pg_stat_statements_reset'],
    'a server-file export' => ['GRANT EXECUTE ON FUNCTION pg_catalog.lo_export(oid, text) TO ":runtime"', 'REVOKE EXECUTE ON FUNCTION pg_catalog.lo_export(oid, text) FROM ":runtime"', 'the runtime can EXECUTE lo_export'],
    'switching triggers off' => ['GRANT SET ON PARAMETER session_replication_role TO ":runtime"', 'REVOKE SET ON PARAMETER session_replication_role FROM ":runtime"', 'the runtime can SET session_replication_role'],
])->skip($fgdpSkip, $fgdpSkipReason);

it('denies the runtime everything outside its class (42501)', function (string $sql) {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();

    expect(fgdpState(fgdpRuntimePdo(), fgdpSql($sql)))->toBe('42501');
})->with([
    'UPDATE an append-only table' => ['UPDATE public.trx_rme_record_versions SET id = id WHERE false'],
    'DELETE from an append-only table' => ['DELETE FROM public.trx_rme_record_versions WHERE false'],
    'TRUNCATE a FULL_DML table' => ['TRUNCATE public.cache'],
    'UPDATE an unlisted column' => ['UPDATE public.trx_inventory_movements SET id = id WHERE false'],
    'DELETE from a column-limited table' => ['DELETE FROM public.trx_inventory_movements WHERE false'],
    'INSERT into migrations' => ["INSERT INTO public.migrations (migration, batch) VALUES ('fgdp', 0)"],
    'setval' => ["SELECT setval('public.users_id_seq', 1)"],
    'CREATE TABLE' => ['CREATE TABLE public.fgdp_runtime_table (id int)'],
    'CREATE SCHEMA' => ['CREATE SCHEMA fgdp_runtime_schema'],
    'reset statistics' => ['SELECT fgdp_ext.pg_stat_statements_reset()'],
    'export a server file' => ["SELECT lo_export(0, '/tmp/fgdp')"],
    'switch triggers off' => ['SET session_replication_role = replica'],
    'assume the owner role' => ['SET ROLE ":owner"'],
    'row-lock an append-only table' => ['SELECT 1 FROM public.trx_rme_record_versions FOR UPDATE'],
])->skip($fgdpSkip, $fgdpSkipReason);

it('lets the runtime row-lock every table that keeps UPDATE, and update the listed columns', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $runtime = fgdpRuntimePdo();
    $failures = [];

    [$entries] = (new RuntimePrivilegeRegistry)->expected();
    foreach ($entries as $table => $entry) {
        if (! in_array($entry['class'], ['FULL_DML', 'APPEND_ONLY_WITH_COLUMNS'], true)) {
            continue;
        }
        foreach (['FOR UPDATE', 'FOR NO KEY UPDATE'] as $lock) {
            $runtime->beginTransaction();
            $state = fgdpState($runtime, "SELECT 1 FROM public.\"{$table}\" {$lock}");
            $runtime->rollBack();
            if ($state !== 'ok') {
                $failures[] = "{$table} {$lock}: {$state}";
            }
        }
        foreach ($entry['columns'] as $column) {
            $state = fgdpState($runtime, "UPDATE public.\"{$table}\" SET \"{$column}\" = \"{$column}\" WHERE false");
            if ($state !== 'ok') {
                $failures[] = "{$table}.{$column} UPDATE: {$state}";
            }
        }
    }

    expect($failures)->toBe([]);
})->skip($fgdpSkip, $fgdpSkipReason);

it('refuses to apply before provisioning and changes nothing', function () {
    fgdpUseCluster();
    fgdpMigrated();
    $super = fgdpSuper();
    $super->exec(fgdpSql('ALTER TABLE public.cache OWNER TO ":super"'));
    try {
        $before = fgdpDigest();
        [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);

        expect($code)->toBe(1)
            ->and($output)->toContain('not owned by the owner role: provisioning (R6-D) comes first')
            ->and(fgdpDigest())->toBe($before);
    } finally {
        $super->exec(fgdpSql('ALTER TABLE public.cache OWNER TO ":owner"'));
    }
})->skip($fgdpSkip, $fgdpSkipReason);

it('rolls the whole apply back when the result still differs from the classes', function () {
    fgdpUseCluster();
    fgdpMigrated();
    fgdpProvisionedAndGranted();
    $super = fgdpSuper();
    // One object to change, and a PUBLIC grant the apply never touches.
    $super->exec(fgdpSql('REVOKE INSERT ON public.users FROM ":runtime"; GRANT DELETE ON public.trx_rme_record_versions TO PUBLIC'));
    try {
        $before = fgdpDigest();
        [$code, $output] = fgdpRun('foundation:db-privilege-apply', ['--apply' => true]);

        expect($code)->toBe(1)
            ->and($output)->toContain('nothing was changed: after the grants the runtime still differs from its classes')
            ->and($output)->toContain('trx_rme_record_versions')
            ->and(fgdpDigest())->toBe($before);
    } finally {
        $super->exec(fgdpSql('REVOKE DELETE ON public.trx_rme_record_versions FROM PUBLIC; GRANT INSERT ON public.users TO ":runtime"'));
    }
})->skip($fgdpSkip, $fgdpSkipReason);

it('refuses to check from any session but the runtime itself', function () {
    fgdpUseCluster();
    fgdpMigrated();

    [$code, $output] = fgdpRun('foundation:db-privilege-check', ['--database' => 'pgsql_migrate']);

    expect($code)->toBe(DatabasePrivilegeCheck::CANNOT_VERIFY)
        ->and($output)->toContain('not under SET ROLE');
})->skip($fgdpSkip, $fgdpSkipReason);
