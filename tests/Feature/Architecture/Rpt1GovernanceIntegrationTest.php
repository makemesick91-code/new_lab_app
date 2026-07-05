<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\File;

uses()->group('Architecture', 'Rpt1', 'FoundationGovernance');

beforeEach(function () {
    $this->ciDir = 'storage/framework/testing/rpt1-ci-evidence';
    config(['release_evidence.profiles.ci.directory' => $this->ciDir]);
    File::deleteDirectory(base_path($this->ciDir));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->ciDir));
});

it('foundation summary includes REPORTING_SUMMARY section and combined remains GO', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('reporting_summary')
        ->and($summary['reporting_summary']['decision'])->toBe('GO')
        ->and($summary['summary']['reporting_summary_decision'])->toBe('GO')
        ->and($summary['summary']['combined_decision'])->toBe('GO');
});

it('release evidence capture and check include reporting summary artifacts', function () {
    $capture = app(ReleaseEvidenceService::class)->capture('ci');
    $captured = array_column($capture['captured'] ?? [], 'artifact');

    expect($captured)->toContain('reporting-summary-check.json')
        ->and($captured)->toContain('reporting-summary-refresh-dry-run.json');

    $check = app(ReleaseEvidenceService::class)->check('ci');
    $artifacts = array_column($check['artifacts'] ?? [], 'artifact');

    expect($artifacts)->toContain('reporting-summary-check.json')
        ->and($artifacts)->toContain('reporting-summary-refresh-dry-run.json')
        ->and($check['summary']['decision'])->toBe('GO');
});

it('release safety config lists reporting summary gates', function () {
    $gates = config('release_safety.required_pre_deploy_gates');

    expect($gates)->toContain('foundation:reporting-summary-check')
        ->and($gates)->toContain('foundation:reporting-summary-refresh --dry-run');
});

it('ci workflow and deploy script contain reporting summary evidence gates', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($workflow)->toContain('foundation:reporting-summary-check')
        ->and($workflow)->toContain('reporting-summary-check.json')
        ->and($workflow)->toContain('reporting-summary-refresh-dry-run.json')
        ->and($script)->toContain('foundation:reporting-summary-check --include-db-inventory')
        ->and($script)->toContain('reporting-summary-refresh-dry-run.json');
});

it('roadmap marks RPT-1 completed and locks STORAGE-1 as next', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $rpt1 = collect($report['approved_sequence'])->firstWhere('id', 'RPT-1');

    expect($rpt1['status'])->toBe('completed')
        ->and($rpt1['depends_on'])->toContain('DBPERF-2')
        ->and($report['next_recommended_sprint'])->toBe('STORAGE-1');
});

it('feature flags for RPT-1 exist with risky runtime toggles off', function () {
    $flags = config('feature_flags.flags');

    expect($flags)->toHaveKeys([
        'foundation.reporting.materialized_summary_readiness',
        'foundation.reporting.rpt_summary_governance',
        'foundation.reporting.summary_runtime_reads_enabled',
        'foundation.reporting.summary_auto_refresh_enabled',
    ])
        ->and($flags['foundation.reporting.summary_runtime_reads_enabled']['default'])->toBeFalse()
        ->and($flags['foundation.reporting.summary_auto_refresh_enabled']['default'])->toBeFalse();
});
