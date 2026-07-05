<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;

uses()->group('Architecture', 'Observability', 'Governance', 'FoundationGovernance', 'Obs1');

it('foundation summary includes OBSERVABILITY_GOVERNANCE section with the OBS-R rules', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('observability_governance')
        ->and($summary['observability_governance']['decision'])->toBe('GO');

    $ruleIds = collect($summary['observability_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe([
        'OBS-R001', 'OBS-R002', 'OBS-R003', 'OBS-R004', 'OBS-R005', 'OBS-R006',
        'OBS-R007', 'OBS-R008', 'OBS-R009', 'OBS-R010', 'OBS-R011', 'OBS-R012',
    ]);
});

it('foundation summary still includes STORAGE/STATELESS/LB/REPLICA/CACHE_REDIS sections unchanged', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('storage_governance')
        ->and($summary)->toHaveKey('stateless_governance')
        ->and($summary)->toHaveKey('lb_governance')
        ->and($summary)->toHaveKey('database_replica_governance')
        ->and($summary)->toHaveKey('cache_redis_governance')
        ->and($summary)->toHaveKey('cache_governance');

    expect(collect($summary['storage_governance']['rules'])->pluck('id')->all())->toHaveCount(5)
        ->and(collect($summary['stateless_governance']['rules'])->pluck('id')->all())->toHaveCount(8)
        ->and(collect($summary['lb_governance']['rules'])->pluck('id')->all())->toHaveCount(10)
        ->and(collect($summary['database_replica_governance']['rules'])->pluck('id')->all())->toHaveCount(12)
        ->and(collect($summary['cache_redis_governance']['rules'])->pluck('id')->all())->toHaveCount(12);
});

it('foundation-governance-summary combined decision stays GO in single-node mode', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['combined']['decision'])->toBe('GO');
});

it('.env.example ships OBSERVABILITY readiness keys with safe defaults', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('OBSERVABILITY_ENABLED=true')
        ->and($env)->toContain('OBSERVABILITY_REQUEST_ID_ENABLED=true')
        ->and($env)->toContain('OBSERVABILITY_TRUST_INBOUND_REQUEST_ID=false')
        ->and($env)->toContain('OBSERVABILITY_TRUST_INBOUND_CORRELATION_ID=false')
        ->and($env)->toContain('OBSERVABILITY_LOG_USER_ID=false')
        ->and($env)->toContain('OBSERVABILITY_LOG_BRANCH_ID=false');
});

it('observability readiness and governance docs exist', function () {
    expect(file_exists(base_path('docs/architecture/request-correlation-observability-readiness.md')))->toBeTrue()
        ->and(file_exists(base_path('docs/architecture/observability-governance-rules.md')))->toBeTrue();
});
