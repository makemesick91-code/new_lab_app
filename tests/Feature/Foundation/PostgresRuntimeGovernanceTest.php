<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Foundation\PostgresRuntimeGovernanceService;
use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses()->group('Foundation', 'PostgresRuntime', 'Dbperf2');

it('postgres_runtime_governance config exists with DBPERF-2 metadata', function () {
    $config = config('postgres_runtime_governance');

    expect($config)->toBeArray()
        ->and($config['metadata']['sprint'])->toBe('DBPERF-2')
        ->and($config['metadata']['status'])->toBe('implemented')
        ->and($config['metadata']['production_cutover'])->toBeFalse();
});

it('foundation postgres-runtime-check command returns GO or WATCH, never FAIL, in a clean environment', function () {
    $exit = Artisan::call('foundation:postgres-runtime-check');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/Decision: (GO|WATCH)/');
});

it('json output includes postgres runtime status', function () {
    Artisan::call('foundation:postgres-runtime-check', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toHaveKeys([
        'summary', 'checks', 'flags', 'app_cutover_detection', 'recommendations', 'db_driver',
    ])
        ->and($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('json output includes db stats when requested', function () {
    Artisan::call('foundation:postgres-runtime-check', ['--include-db-stats' => true, '--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['db_stats_requested'])->toBeTrue();
});

it('PgBouncer cutover flag is disabled by default', function () {
    $flags = config('feature_flags.flags');
    expect($flags['foundation.db.pg_bouncer_cutover_enabled']['default'])->toBeFalse();
});

it('runtime apply flag is disabled by default', function () {
    $flags = config('feature_flags.flags');
    expect($flags['foundation.db.postgres_runtime_apply_enabled']['default'])->toBeFalse();
});

it('app cutover flag/env is false by default and detection reports direct PostgreSQL', function () {
    $report = app(PostgresRuntimeGovernanceService::class)->collect();

    expect($report['app_cutover_detection']['potential_cutover'])->toBeFalse();
});

it('command does not print DB_PASSWORD, APP_KEY, or .env contents', function () {
    Artisan::call('foundation:postgres-runtime-check', ['--include-db-stats' => true, '--include-pgbouncer-probe' => true, '--json' => true]);
    $json = Artisan::output();

    expect($json)->not->toContain('DB_PASSWORD')
        ->not->toContain('DB_USERNAME')
        ->not->toContain('APP_KEY=')
        ->not->toMatch('/\d{16}/');
});

it('command handles non-pgsql connection safely', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('This assertion targets a non-pgsql connection driver behavior.');
    }

    $report = app(PostgresRuntimeGovernanceService::class)->collect(includeDbStats: true);

    expect($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('command reads safe pgsql runtime settings when pgsql is available', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires a pgsql connection.');
    }

    $report = app(PostgresRuntimeGovernanceService::class)->collect(includeDbStats: true);

    expect($report['db_stats'])->not->toBeNull()
        ->and($report['db_stats']['settings'])->toHaveKey('max_connections')
        ->and($report['db_stats']['connection_stats'])->toHaveKey('active');
});

it('PgBouncer not installed is non-blocking WATCH when cutover disabled', function () {
    $report = app(PostgresRuntimeGovernanceService::class)->collect(includePgBouncerProbe: true);

    $probeCheck = collect($report['checks'])->firstWhere('check_id', 'PGRUNTIME-PGBOUNCER-PROBE');

    expect($probeCheck['status'])->toBeIn(['passed', 'warning'])
        ->and($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('PgBouncer probe failure is FAIL when cutover flag is enabled', function () {
    $flags = config('feature_flags.flags');
    $flags['foundation.db.pg_bouncer_cutover_enabled']['default'] = true;
    config(['feature_flags.flags' => $flags]);

    $report = app(PostgresRuntimeGovernanceService::class)->collect(includePgBouncerProbe: true);

    $probeCheck = collect($report['checks'])->firstWhere('check_id', 'PGRUNTIME-PGBOUNCER-PROBE');

    if (($probeCheck['status'] ?? null) === 'failed') {
        expect($report['summary']['decision'])->toBe('FAIL');
    } else {
        // Environment happens to have a real PgBouncer installed/listening — probe passes instead.
        expect($probeCheck['status'])->toBe('passed');
    }
});

it('config denies alter_system, restart, and routing changes', function () {
    $denied = config('postgres_runtime_governance.denied_actions');

    expect($denied)->toContain(
        'alter_system_set',
        'edit_postgresql_conf',
        'restart_postgresql',
        'route_app_to_pgbouncer',
        'start_long_running_queue_worker',
        'heavy_load_test',
        'destructive_schema_change',
    );
});

/*
 * CICD-FIX-6 Phase A2 — the runtime settings probe must not poison its caller.
 *
 * Every audited name is read with `SHOW <name>`, so every name must be a real
 * PostgreSQL configuration parameter. `version` is not one — it is a function —
 * so `SHOW version` raised SQLSTATE 42704.
 *
 * Outside a transaction that is harmless: autocommit means the failed statement
 * fails alone, which is why deploys and CLI runs never surfaced it. Inside a
 * transaction PostgreSQL aborts the WHOLE transaction and rejects every later
 * statement with SQLSTATE 25P02 until rollback, and catching the PDO exception
 * in PHP does not undo that. Every Pest feature test runs inside a
 * RefreshDatabase transaction, so one bad probe silently broke every later
 * query — which is how the vps release-evidence capture (the only profile that
 * passes --include-db-stats) lost the REQUIRED foundation-governance-summary
 * artifact and decided FAIL.
 */

it('never probes a name that is not a real postgres configuration parameter', function () {
    $settings = (array) config('postgres_runtime_governance.postgres_runtime_audit.settings');

    expect($settings)->toContain('server_version');

    // `toContain` is variadic — extra arguments are additional needles, not a
    // failure message — so the reason goes in an explicit assertion.
    expect(in_array('version', $settings, true))->toBeFalse(
        '`SHOW version` is invalid (version is a function, not a parameter) and aborts a PostgreSQL transaction'
    );
});

it('leaves an enclosing postgres transaction usable after reading runtime settings', function () {
    $connection = DB::connection();

    if ($connection->getDriverName() !== 'pgsql') {
        // The contract under test is PostgreSQL-specific: only PostgreSQL
        // aborts an entire transaction on a statement error. On another driver
        // there is nothing to prove here, and the name contract is pinned by
        // the driver-independent test above.
        expect($connection->getDriverName())->not->toBe('pgsql');

        return;
    }

    // RefreshDatabase already holds the transaction this probe must not break.
    expect($connection->transactionLevel())->toBeGreaterThan(0);

    $report = app(PostgresRuntimeGovernanceService::class)->collect(includeDbStats: true);

    // The probe must actually have read the server version, not silently
    // degrade to null the way the invalid parameter name did.
    expect($report['db_stats']['settings']['server_version'] ?? null)
        ->not->toBeNull('server_version was not read from PostgreSQL');

    // The real assertion: the surrounding transaction is still usable. If the
    // probe had aborted it, this raises SQLSTATE 25P02 and the test fails.
    expect($connection->select('select 1 as ok'))->not->toBeEmpty();
});

it('recommendations include rollback note and restart classification', function () {
    $report = app(PostgresRuntimeGovernanceService::class)->collect();

    expect($report['recommendations'])->not->toBeEmpty();

    foreach ($report['recommendations'] as $recommendation) {
        expect($recommendation)->toHaveKeys([
            'setting', 'current_value', 'recommendation', 'classification',
            'restart_required', 'risk', 'rollback_note', 'next_action',
        ])
            ->and($recommendation['rollback_note'])->not->toBeEmpty();
    }
});

it('foundation governance summary includes POSTGRES_RUNTIME and combined stays GO', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('postgres_runtime')
        ->and($summary['postgres_runtime']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['summary']['postgres_runtime_decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['summary']['combined_decision'])->toBe('GO');
});

it('release evidence capture includes postgres-runtime-check artifact for ci profile', function () {
    $ciDir = 'storage/framework/testing/dbperf2-ci-evidence';
    config(['release_evidence.profiles.ci.directory' => $ciDir]);
    File::deleteDirectory(base_path($ciDir));

    $capture = app(ReleaseEvidenceService::class)->capture('ci');
    $filenames = array_column($capture['captured'] ?? [], 'artifact');

    expect($filenames)->toContain('postgres-runtime-check.json');

    File::deleteDirectory(base_path($ciDir));
});

it('release evidence check expects postgres-runtime-check artifact after DBPERF-2', function () {
    $required = config('release_evidence.profiles.ci.required_artifacts');
    $requiredVps = config('release_evidence.profiles.vps.required_artifacts');

    expect($required)->toContain('postgres-runtime-check.json')
        ->and($requiredVps)->toContain('postgres-runtime-check.json');
});

it('release safety includes postgres runtime evidence gate', function () {
    $gates = config('release_safety.required_pre_deploy_gates');

    expect($gates)->toContain('foundation:postgres-runtime-check');
});

it('CI workflow contains postgres runtime check', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    expect($workflow)->toContain('foundation:postgres-runtime-check')
        ->and($workflow)->toContain('postgres-runtime-check.json');
});

it('deploy script contains postgres runtime gate', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('foundation:postgres-runtime-check')
        ->and($script)->toContain('--include-pgbouncer-probe');
});

it('foundation governance config registers DBPERF-2 ci evidence gate', function () {
    $gates = config('foundation_governance.ci_evidence_gates.gates');

    expect($gates)->toHaveKey('DBPERF-2')
        ->and($gates['DBPERF-2']['artifacts'])->toContain('storage/ci-evidence/postgres-runtime-check.json');
});
