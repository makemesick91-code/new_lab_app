<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\LoadBalancerGovernanceService;

uses()->group('Architecture', 'Lb1', 'Governance', 'FoundationGovernance');

it('foundation summary includes LB_GOVERNANCE section with the LB-R rules', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('lb_governance')
        ->and($summary['lb_governance']['decision'])->toBe('GO');

    $ruleIds = collect($summary['lb_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe([
        'LB-R001', 'LB-R002', 'LB-R003', 'LB-R004', 'LB-R005',
        'LB-R006', 'LB-R007', 'LB-R008', 'LB-R009', 'LB-R010',
    ]);
});

it('foundation summary still includes STORAGE_GOVERNANCE and STATELESS_GOVERNANCE unchanged', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('storage_governance')
        ->and($summary)->toHaveKey('stateless_governance');

    $storageRuleIds = collect($summary['storage_governance']['rules'])->pluck('id')->all();
    $statelessRuleIds = collect($summary['stateless_governance']['rules'])->pluck('id')->all();

    expect($storageRuleIds)->toBe(['STORAGE-R001', 'STORAGE-R002', 'STORAGE-R003', 'STORAGE-R004', 'STORAGE-R005'])
        ->and($statelessRuleIds)->toBe([
            'STATELESS-R001', 'STATELESS-R002', 'STATELESS-R003', 'STATELESS-R004',
            'STATELESS-R005', 'STATELESS-R006', 'STATELESS-R007', 'STATELESS-R008',
        ]);
});

it('foundation-governance-summary combined decision stays GO', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['combined']['decision'])->toBe('GO');
});

it('lb governance watches when forwarded headers are expected without trusted proxies', function () {
    config(['load_balancer.expect_forwarded_headers' => true, 'load_balancer.trusted_proxies' => []]);

    $result = app(LoadBalancerGovernanceService::class)->collect();

    expect($result['decision'])->toBe('GO')
        ->and($result['readiness_status'])->toBe('warning')
        ->and($result['warnings'])->not->toBeEmpty();
});

it('roadmap marks LB-1 completed (next recommended sprint is now ENT-1 after ENT-0 reconciliation)', function () {
    $report = app(FoundationRoadmapService::class)->collect();
    $lb1 = collect($report['approved_sequence'])->firstWhere('id', 'LB-1');

    expect($lb1['status'])->toBe('completed')
        ->and($report['next_recommended_sprint'])->toBe('ENT-8');
});

it('.env.example ships LB readiness keys without real secret values', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('LB_PILOT_ENABLED=true')
        ->and($env)->toContain('LB_TRUSTED_PROXIES=')
        ->and($env)->toContain('LB_HEALTH_ENDPOINT_PATH=/health/lb')
        ->and($env)->toContain('LB_STRICT=false');
});

it('load balancer pilot readiness and governance docs exist', function () {
    expect(file_exists(base_path('docs/architecture/load-balancer-pilot-readiness.md')))->toBeTrue()
        ->and(file_exists(base_path('docs/architecture/load-balancer-governance-rules.md')))->toBeTrue();
});
