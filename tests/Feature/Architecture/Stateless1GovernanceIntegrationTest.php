<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\StatelessGovernanceService;

uses()->group('Architecture', 'Stateless1', 'Governance', 'FoundationGovernance');

it('foundation summary includes STATELESS_GOVERNANCE section with the STATELESS-R rules', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('stateless_governance')
        ->and($summary['stateless_governance']['decision'])->toBe('GO');

    $ruleIds = collect($summary['stateless_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe([
        'STATELESS-R001', 'STATELESS-R002', 'STATELESS-R003', 'STATELESS-R004',
        'STATELESS-R005', 'STATELESS-R006', 'STATELESS-R007', 'STATELESS-R008',
    ]);
});

it('foundation summary still includes STORAGE_GOVERNANCE with STORAGE-R rules unchanged', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('storage_governance');

    $ruleIds = collect($summary['storage_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe(['STORAGE-R001', 'STORAGE-R002', 'STORAGE-R003', 'STORAGE-R004', 'STORAGE-R005']);
});

it('foundation-governance-summary combined decision stays GO', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['combined']['decision'])->toBe('GO');
});

it('stateless governance watches when a risky driver is active', function () {
    config(['session.driver' => 'file', 'cache.default' => 'file', 'queue.default' => 'sync']);

    $result = app(StatelessGovernanceService::class)->collect();

    expect($result['decision'])->toBe('GO')
        ->and($result['readiness_status'])->toBe('warning')
        ->and($result['horizontal_scale_warnings'])->not->toBeEmpty();
});

it('roadmap marks STATELESS-1 completed (next recommended sprint is now ENT-1 after ENT-0 reconciliation)', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $stateless1 = collect($report['approved_sequence'])->firstWhere('id', 'STATELESS-1');

    expect($stateless1['status'])->toBe('completed')
        ->and($report['next_recommended_sprint'])->toBe('ENT-1');
});

it('.env.example ships stateless readiness keys without real secret values', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('STATELESS_READINESS_ENABLED=true')
        ->and($env)->toContain('STATELESS_ALLOWED_LOCAL_WRITE_PATHS=storage,bootstrap/cache')
        ->and($env)->toContain('STATELESS_STRICT=false');
});

it('runtime statelessness and deploy portability governance docs exist', function () {
    expect(file_exists(base_path('docs/architecture/runtime-statelessness-readiness.md')))->toBeTrue()
        ->and(file_exists(base_path('docs/architecture/deploy-portability-governance-rules.md')))->toBeTrue();
});
