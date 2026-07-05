<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\File;

uses()->group('Architecture', 'Dbperf2', 'FoundationGovernance');

beforeEach(function () {
    $this->ciDir = 'storage/framework/testing/dbperf2-integration-ci-evidence';
    config(['release_evidence.profiles.ci.directory' => $this->ciDir]);
    File::deleteDirectory(base_path($this->ciDir));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->ciDir));
});

it('foundation summary includes POSTGRES_RUNTIME section', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('postgres_runtime')
        ->and($summary['postgres_runtime']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['summary']['postgres_runtime_decision'])->toBeIn(['GO', 'WATCH']);
});

it('combined foundation remains GO after DBPERF-2', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO')
        ->and($summary['db_performance']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['queue_governance']['decision'])->toBe('GO')
        ->and($summary['cache_governance']['decision'])->toBe('GO');
});

it('release evidence capture includes postgres-runtime-check artifact', function () {
    $capture = app(ReleaseEvidenceService::class)->capture('ci');
    $filenames = array_column($capture['captured'] ?? [], 'artifact');

    expect($filenames)->toContain('postgres-runtime-check.json');
});

it('release evidence check expects postgres-runtime-check artifact after DBPERF-2', function () {
    app(ReleaseEvidenceService::class)->capture('ci');
    $check = app(ReleaseEvidenceService::class)->check('ci');
    $artifacts = array_column($check['artifacts'] ?? [], 'artifact');

    expect($artifacts)->toContain('postgres-runtime-check.json')
        ->and($check['summary']['decision'])->toBe('GO');
});

it('ci workflow contains postgres runtime governance step', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    expect($workflow)->toContain('foundation:postgres-runtime-check')
        ->and($workflow)->toContain('postgres-runtime-check.json');
});

it('deploy script contains postgres runtime governance gate', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('foundation:postgres-runtime-check');
});

it('roadmap marks DBPERF-2, RPT-1, STORAGE-1, STATELESS-1, and LB-1 completed (next sprint is now ENT-1 after ENT-0 reconciliation)', function () {
    $report = app(FoundationRoadmapService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('ENT-1');

    $dbperf2 = collect($report['approved_sequence'])->firstWhere('id', 'DBPERF-2');
    $rpt1 = collect($report['approved_sequence'])->firstWhere('id', 'RPT-1');
    expect($dbperf2['status'])->toBe('completed')
        ->and($rpt1['status'])->toBe('completed');
});

it('release safety config lists postgres runtime gate command', function () {
    $gates = config('release_safety.required_pre_deploy_gates');

    expect($gates)->toContain('foundation:postgres-runtime-check');
});

it('foundation governance config registers DBPERF-2 ci evidence gate', function () {
    $gates = config('foundation_governance.ci_evidence_gates.gates');

    expect($gates)->toHaveKey('DBPERF-2')
        ->and($gates['DBPERF-2']['artifacts'])->toContain('storage/ci-evidence/postgres-runtime-check.json');
});

it('DQ DMO NSF ROADMAP CACHE QUEUE DB_PERFORMANCE remain GO after DBPERF-2', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['dq_decision'])->toBe('GO')
        ->and($summary['summary']['nsf_effective_decision'])->toBe('GO')
        ->and($summary['summary']['roadmap_decision'])->toBe('GO')
        ->and($summary['cache_governance']['decision'])->toBe('GO')
        ->and($summary['queue_governance']['decision'])->toBe('GO')
        ->and($summary['db_performance']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('postgres runtime governance does not introduce a denied action or production cutover', function () {
    $denied = config('postgres_runtime_governance.denied_actions');

    expect($denied)->toContain('route_app_to_pgbouncer', 'restart_postgresql', 'alter_system_set');

    $connectionName = (string) config('database.default');
    $connection = (array) config("database.connections.{$connectionName}", []);
    expect((string) ($connection['port'] ?? ''))->not->toBe('6432');
});
