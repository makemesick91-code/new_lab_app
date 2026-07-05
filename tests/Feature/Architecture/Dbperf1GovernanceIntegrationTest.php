<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\File;

uses()->group('Architecture', 'Dbperf1', 'FoundationGovernance');

beforeEach(function () {
    $this->ciDir = 'storage/framework/testing/dbperf1-integration-ci-evidence';
    config(['release_evidence.profiles.ci.directory' => $this->ciDir]);
    File::deleteDirectory(base_path($this->ciDir));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->ciDir));
});

it('foundation summary includes DB_PERFORMANCE section', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('db_performance')
        ->and($summary['db_performance']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['summary']['db_performance_decision'])->toBeIn(['GO', 'WATCH']);
});

it('combined foundation remains GO after DBPERF-1', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO')
        ->and($summary['queue_governance']['decision'])->toBe('GO')
        ->and($summary['cache_governance']['decision'])->toBe('GO');
});

it('release evidence capture includes db-performance-check artifact', function () {
    $capture = app(ReleaseEvidenceService::class)->capture('ci');
    $filenames = array_column($capture['captured'] ?? [], 'artifact');

    expect($filenames)->toContain('db-performance-check.json');
});

it('release evidence check expects db-performance-check artifact after DBPERF-1', function () {
    app(ReleaseEvidenceService::class)->capture('ci');
    $check = app(ReleaseEvidenceService::class)->check('ci');
    $artifacts = array_column($check['artifacts'] ?? [], 'artifact');

    expect($artifacts)->toContain('db-performance-check.json')
        ->and($check['summary']['decision'])->toBe('GO');
});

it('ci workflow contains DB performance governance step', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    expect($workflow)->toContain('foundation:db-performance-check')
        ->and($workflow)->toContain('db-performance-check.json');
});

it('deploy script contains DB performance governance gate', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('foundation:db-performance-check');
});

it('roadmap next sprint becomes DBPERF-2 after DBPERF-1 completion', function () {
    $report = app(FoundationRoadmapService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('DBPERF-2');

    $dbperf1 = collect($report['approved_sequence'])->firstWhere('id', 'DBPERF-1');
    expect($dbperf1['status'])->toBe('completed');
});

it('release safety config lists DB performance gate command', function () {
    $gates = config('release_safety.required_pre_deploy_gates');

    expect($gates)->toContain('foundation:db-performance-check');
});

it('foundation governance config registers DBPERF-1 ci evidence gate', function () {
    $gates = config('foundation_governance.ci_evidence_gates.gates');

    expect($gates)->toHaveKey('DBPERF-1')
        ->and($gates['DBPERF-1']['artifacts'])->toContain('storage/ci-evidence/db-performance-check.json');
});

it('DQ DMO NSF ROADMAP CACHE QUEUE remain GO after DBPERF-1', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['dq_decision'])->toBe('GO')
        ->and($summary['summary']['nsf_effective_decision'])->toBe('GO')
        ->and($summary['summary']['roadmap_decision'])->toBe('GO')
        ->and($summary['cache_governance']['decision'])->toBe('GO')
        ->and($summary['queue_governance']['decision'])->toBe('GO');
});

it('db performance governance does not introduce a denied action in migrations or docs', function () {
    $migrationPath = database_path('migrations/2026_07_05_200001_add_dbperf1_idempotency_status_expires_at_index.php');
    $contents = file_get_contents($migrationPath);

    // The only DROP present must be the safe, reversible down() migration
    // rollback (DROP INDEX CONCURRENTLY IF EXISTS) — never a production
    // drop-index-in-up(), PgBouncer, runtime tuning, or partitioning action.
    foreach (['PgBouncer', 'ALTER SYSTEM', 'PARTITION BY', 'DROP TABLE'] as $needle) {
        expect($contents)->not->toContain($needle);
    }
    expect($contents)->toContain('DROP INDEX CONCURRENTLY IF EXISTS idx_dbperf1_idempotency_status_expires_at');
});
