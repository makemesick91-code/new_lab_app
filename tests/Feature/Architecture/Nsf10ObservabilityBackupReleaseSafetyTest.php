<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Architecture\NsfApplicationRulesService;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Support\Facades\File;

uses()->group('Architecture', 'Nsf10', 'FoundationGovernance');

beforeEach(function () {
    $this->ciDir = 'storage/framework/testing/nsf10-summary-ci-evidence';
    config(['release_evidence.profiles.ci.directory' => $this->ciDir]);
    File::deleteDirectory(base_path($this->ciDir));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->ciDir));
});

it('foundation summary includes RELEASE_EVIDENCE and BACKUP_VERIFICATION sections', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKeys(['release_evidence', 'backup_verification'])
        ->and($summary['release_evidence'])->toHaveKeys(['decision', 'profile', 'summary'])
        ->and($summary['backup_verification'])->toHaveKeys(['decision', 'applicable'])
        ->and($summary['summary'])->toHaveKeys(['release_evidence_decision', 'backup_verification_decision']);
});

it('foundation summary reports the release safety profile', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect('ci');

    expect($summary['release_safety']['profile'])->toBe('ci')
        ->and($summary['summary']['release_safety_profile'])->toBe('ci');
});

it('foundation summary combined stays GO on local profile even though release safety is WATCH', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect('local');

    expect($summary['summary']['release_safety_decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['summary']['combined_decision'])->toBe('GO');
});

it('backup verification is marked not applicable outside the vps profile', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect('local');

    expect($summary['backup_verification']['applicable'])->toBeFalse()
        ->and($summary['backup_verification']['decision'])->toBe('NOT_APPLICABLE');
});

it('automated smoke remains GO', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['automated_smoke']['decision'])->toBe('GO');
});

it('feature flags remain GO with no risky flag enabled', function () {
    $governance = app(FeatureFlagService::class)->validateGovernance();

    expect($governance['summary']['decision'])->toBe('GO')
        ->and($governance['risky_enabled_flags'])->toBe([]);
});

it('roadmap next recommended sprint is QUEUE-1 after CACHE-1 completion', function () {
    $report = app(FoundationRoadmapService::class)->collect();

    expect($report['next_recommended_sprint'])->toBe('QUEUE-1');

    $cache1 = collect($report['approved_sequence'])->firstWhere('id', 'CACHE-1');
    expect($cache1['status'])->toBe('completed');
});

it('NSF-R009 observability evidence is represented safely via nsf governance check', function () {
    $report = app(NsfApplicationRulesService::class)->collect([
        'include_observability' => true,
    ]);

    $r009 = collect($report['rules'])->firstWhere('rule_id', 'NSF-R009');

    expect($r009)->not->toBeNull()
        ->and($report['observability'])->toHaveKeys([
            'slow_query_audit_command_available',
            'runtime_query_observability_command_available',
            'pg_stat_expected_on_vps',
        ]);

    $encoded = strtolower((string) json_encode($report));
    expect($encoded)->not->toContain('password')
        ->not->toContain('db_password');
});

it('ci workflow contains the nsf-10 release evidence gate with artifact upload', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    expect($workflow)->toContain('nsf10_release_evidence_gate')
        ->toContain('release:evidence-capture')
        ->toContain('release:evidence-check')
        ->toContain('nsf-10-release-evidence');
});

it('deploy script contains backup verify and release evidence capture for the vps profile', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('foundation:backup-verify')
        ->toContain('release:evidence-capture --profile=vps')
        ->toContain('release:evidence-check --profile=vps')
        ->toContain('foundation:release-safety-check --profile=vps');
});

it('combined foundation stays GO and DQ DMO NSF ROADMAP remain GO after NSF-10', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO')
        ->and($summary['summary']['dq_decision'])->toBe('GO')
        ->and($summary['summary']['dmo_decision'])->toBe('GO')
        ->and($summary['summary']['nsf_effective_decision'])->toBe('GO')
        ->and($summary['summary']['roadmap_decision'])->toBe('GO');
});

it('nsf governance check rule registry has grown safely beyond NSF-R021', function () {
    $rules = config('nsf.rules', []);

    expect(count($rules))->toBeGreaterThanOrEqual(23);

    $ids = collect($rules)->pluck('rule_id');
    expect($ids->unique()->count())->toBe($ids->count());
});
