<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Architecture', 'Nsf9', 'FoundationGovernance');

it('foundation summary includes FEATURE_FLAGS RELEASE_SAFETY and AUTOMATED_SMOKE', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKeys(['feature_flags', 'release_safety', 'automated_smoke'])
        ->and($summary['feature_flags']['decision'])->toBe('GO')
        ->and($summary['release_safety']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['automated_smoke']['decision'])->toBe('GO')
        ->and($summary['summary']['feature_flags_decision'])->toBe('GO')
        ->and($summary['summary']['release_safety_decision'])->toBeIn(['GO', 'WATCH'])
        ->and($summary['summary']['automated_smoke_decision'])->toBe('GO');
});

it('combined foundation remains GO with NSF-9 chain wired in', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary']['combined_decision'])->toBe('GO')
        ->and($summary['combined']['decision'])->toBe('GO');
});

it('foundation summary command renders FEATURE_FLAGS RELEASE_SAFETY AUTOMATED_SMOKE sections', function () {
    Artisan::call('architecture:foundation-governance-summary');
    $output = Artisan::output();

    expect($output)->toContain('FEATURE_FLAGS:')
        ->toContain('RELEASE_SAFETY:')
        ->toContain('AUTOMATED_SMOKE:');
});

it('roadmap next recommended sprint follows the foundation expansion completion status', function () {
    $report = app(FoundationRoadmapService::class)->collect();

    expect($report['next_recommended_sprint'])->toBeIn(['NSF-9', 'NSF-10', 'CACHE-1', 'QUEUE-1', 'DBPERF-1', 'DBPERF-2', 'RPT-1', 'STORAGE-1', 'STATELESS-1', 'LB-1', 'REPLICA-1', 'CACHE-1-REDIS-READINESS', 'OBS-1', 'OBS-2', 'ROADMAP-1-CANONICALIZATION', 'ENT-0', 'ENT-1', 'ENT-2', 'ENT-3', 'ENT-4', 'ENT-5', 'ENT-6', 'ENT-7', 'MON-1']);
});

it('roadmap sequence order is preserved regardless of NSF-9 status', function () {
    $sequence = config('foundation_roadmap.approved_sequence');
    $ids = array_column($sequence, 'id');

    expect($ids[0])->toBe('NSF-9')
        ->and($ids[1])->toBe('NSF-10')
        ->and(end($ids))->toBe('RC-1');
});

it('deploy script contains release safety and automated smoke gates', function () {
    $script = file_get_contents(base_path('scripts/deploy-vps.sh'));

    expect($script)->toContain('foundation:feature-flags')
        ->toContain('foundation:release-safety-check')
        ->toContain('release:automated-smoke')
        ->toContain('architecture:foundation-roadmap-check');
});

it('ci workflow contains an nsf-9 release safety gate job', function () {
    $workflow = file_get_contents(base_path('.github/workflows/foundation-evidence-gates.yml'));

    expect($workflow)->toContain('release_safety_gate')
        ->toContain('foundation:feature-flags')
        ->toContain('foundation:release-safety-check')
        ->toContain('release:automated-smoke');
});

it('automated smoke script exists and is safe/read-only', function () {
    $path = base_path('scripts/release/automated-smoke.sh');

    expect(is_file($path))->toBeTrue();

    $script = file_get_contents($path);
    expect($script)->toContain('set -euo pipefail')
        ->toContain('release:automated-smoke');
});
