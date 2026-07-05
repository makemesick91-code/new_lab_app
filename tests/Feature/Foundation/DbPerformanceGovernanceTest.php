<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Foundation\DbPerformanceGovernanceService;
use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses()->group('Foundation', 'DbPerformance');

it('db_performance_governance config exists with DBPERF-1 metadata', function () {
    $config = config('db_performance_governance');

    expect($config)->toBeArray()
        ->and($config['metadata']['sprint'])->toBe('DBPERF-1')
        ->and($config['metadata']['status'])->toBe('implemented');
});

it('foundation db-performance-check command returns GO or WATCH, never FAIL, in a clean environment', function () {
    $exit = Artisan::call('foundation:db-performance-check');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/Decision: (GO|WATCH)/');
});

it('json output includes db performance status and candidate lists', function () {
    Artisan::call('foundation:db-performance-check', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toHaveKeys([
        'summary', 'checks', 'applied_index_candidates', 'deferred_index_candidates',
        'global_rules', 'target_query_families', 'db_driver',
    ])
        ->and($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('config denies destructive actions', function () {
    $denied = config('db_performance_governance.denied_actions');

    expect($denied)->toContain(
        'drop_index',
        'reindex_production',
        'vacuum_full_production',
        'partition_production_tables',
        'enable_pgbouncer',
        'alter_postgresql_runtime_settings',
        'redirect_reads_to_replica',
    );
});

it('config requires query plan evidence and rollback notes for indexes', function () {
    $rules = config('db_performance_governance.global_rules');

    expect($rules['query_plan_evidence_required'])->toBeTrue()
        ->and($rules['index_reason_required'])->toBeTrue()
        ->and($rules['rollback_note_required'])->toBeTrue()
        ->and($rules['additive_index_only'])->toBeTrue()
        ->and($rules['no_drop_index_in_dbperf_1'])->toBeTrue();
});

it('every applied candidate has a migration name, reason, and rollback note', function () {
    $candidates = collect(config('db_performance_governance.index_candidate_policy'))
        ->where('decision', 'add_index_now');

    expect($candidates)->not->toBeEmpty();

    foreach ($candidates as $candidate) {
        expect($candidate['migration_name'])->not->toBeEmpty()
            ->and($candidate['reason'])->not->toBeEmpty()
            ->and($candidate['rollback_note'])->not->toBeEmpty()
            ->and($candidate['evidence_required'])->toBeTrue();

        $path = database_path('migrations/'.$candidate['migration_name'].'.php');
        expect(is_file($path))->toBeTrue("Migration file missing for candidate: {$candidate['table']}");
    }
});

it('no PII or secrets appear in the generated db performance artifact', function () {
    Artisan::call('foundation:db-performance-check', ['--include-db-stats' => true, '--json' => true]);
    $json = Artisan::output();

    expect($json)->not->toContain('DB_PASSWORD')
        ->not->toContain('DB_USERNAME')
        ->not->toContain('APP_KEY=')
        ->not->toMatch('/\d{16}/');
});

it('command handles non-pgsql connection safely without failing', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('This assertion targets a non-pgsql connection driver behavior.');
    }

    $report = app(DbPerformanceGovernanceService::class)->collect(includeDbStats: true);

    expect($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('command can read PostgreSQL metadata when pgsql is available', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires a pgsql connection.');
    }

    $report = app(DbPerformanceGovernanceService::class)->collect(includeDbStats: true);

    expect($report['db_stats'])->not->toBeNull()
        ->and($report['db_stats']['index_count'])->toBeGreaterThan(0);
});

it('foundation governance summary includes DB_PERFORMANCE and combined stays GO', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('db_performance')
        ->and($summary['db_performance']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['summary']['db_performance_decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['summary']['combined_decision'])->toBe('GO');
});

it('release evidence capture includes db-performance-check artifact for ci profile', function () {
    $ciDir = 'storage/framework/testing/dbperf1-ci-evidence';
    config(['release_evidence.profiles.ci.directory' => $ciDir]);
    File::deleteDirectory(base_path($ciDir));

    $capture = app(ReleaseEvidenceService::class)->capture('ci');
    $filenames = array_column($capture['captured'] ?? [], 'artifact');

    expect($filenames)->toContain('db-performance-check.json');

    File::deleteDirectory(base_path($ciDir));
});

it('release evidence check expects db-performance-check after DBPERF-1', function () {
    $required = config('release_evidence.profiles.ci.required_artifacts');
    $requiredVps = config('release_evidence.profiles.vps.required_artifacts');

    expect($required)->toContain('db-performance-check.json')
        ->and($requiredVps)->toContain('db-performance-check.json');
});

it('release safety includes DB performance evidence gate', function () {
    $gates = config('release_safety.required_pre_deploy_gates');

    expect($gates)->toContain('foundation:db-performance-check');
});

it('CI workflow contains DB performance check', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    expect($workflow)->toContain('foundation:db-performance-check')
        ->and($workflow)->toContain('db-performance-check.json');
});

it('deploy script contains DB performance gate', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('foundation:db-performance-check')
        ->and($script)->toContain('--include-db-stats');
});

it('foundation governance config registers DBPERF-1 ci evidence gate', function () {
    $gates = config('foundation_governance.ci_evidence_gates.gates');

    expect($gates)->toHaveKey('DBPERF-1')
        ->and($gates['DBPERF-1']['artifacts'])->toContain('storage/ci-evidence/db-performance-check.json');
});
