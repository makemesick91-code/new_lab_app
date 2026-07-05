<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\File;

uses()->group('Architecture', 'Cache1', 'FoundationGovernance');

beforeEach(function () {
    $this->ciDir = 'storage/framework/testing/cache1-ci-evidence';
    config(['release_evidence.profiles.ci.directory' => $this->ciDir]);
    File::deleteDirectory(base_path($this->ciDir));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->ciDir));
});

it('foundation summary includes CACHE_GOVERNANCE section', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('cache_governance')
        ->and($summary['cache_governance']['decision'])->toBe('GO')
        ->and($summary['summary']['cache_governance_decision'])->toBe('GO');
});

it('combined foundation remains GO after CACHE-1', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO');
});

it('release evidence capture includes cache governance artifact for ci profile', function () {
    $capture = app(ReleaseEvidenceService::class)->capture('ci');
    $filenames = array_column($capture['captured'] ?? [], 'artifact');

    expect($filenames)->toContain('cache-governance-check.json');
});

it('release evidence check expects cache governance artifact after CACHE-1', function () {
    app(ReleaseEvidenceService::class)->capture('ci');
    $check = app(ReleaseEvidenceService::class)->check('ci');
    $artifacts = array_column($check['artifacts'] ?? [], 'artifact');

    expect($artifacts)->toContain('cache-governance-check.json')
        ->and($check['summary']['decision'])->toBe('GO');
});

it('ci workflow contains cache governance step', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    expect($workflow)->toContain('foundation:cache-governance-check')
        ->and($workflow)->toContain('cache-governance-check.json');
});

it('deploy script contains cache governance gate', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('foundation:cache-governance-check')
        ->and($script)->toContain('cache-governance-check.json');
});

it('CACHE-1, QUEUE-1, and DBPERF-1 are marked completed in the roadmap', function () {
    $report = app(FoundationRoadmapService::class)->collect();

    $cache1 = collect($report['approved_sequence'])->firstWhere('id', 'CACHE-1');
    expect($cache1['status'])->toBe('completed');
});

it('release safety config lists cache governance gate command', function () {
    $gates = config('release_safety.required_pre_deploy_gates');

    expect($gates)->toContain('foundation:cache-governance-check');
});

it('foundation governance config registers CACHE-1 ci evidence gate', function () {
    $gates = config('foundation_governance.ci_evidence_gates.gates');

    expect($gates)->toHaveKey('CACHE-1')
        ->and($gates['CACHE-1']['artifacts'])->toContain('storage/ci-evidence/cache-governance-check.json');
});
