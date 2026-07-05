<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;

uses()->group('Architecture', 'Observability', 'Governance', 'FoundationGovernance', 'Obs2');

it('foundation summary includes OBSERVABILITY_PIPELINE_GOVERNANCE section with OBS-R013..R024', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('observability_pipeline_governance')
        ->and($summary['observability_pipeline_governance']['decision'])->toBe('GO');

    $ruleIds = collect($summary['observability_pipeline_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe([
        'OBS-R013', 'OBS-R014', 'OBS-R015', 'OBS-R016', 'OBS-R017', 'OBS-R018',
        'OBS-R019', 'OBS-R020', 'OBS-R021', 'OBS-R022', 'OBS-R023', 'OBS-R024',
    ]);
});

it('foundation summary still includes OBS-1 (OBS-R001..R012) unchanged', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('observability_governance');

    $ruleIds = collect($summary['observability_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe([
        'OBS-R001', 'OBS-R002', 'OBS-R003', 'OBS-R004', 'OBS-R005', 'OBS-R006',
        'OBS-R007', 'OBS-R008', 'OBS-R009', 'OBS-R010', 'OBS-R011', 'OBS-R012',
    ]);
});

it('foundation summary still includes STORAGE/STATELESS/LB/REPLICA/CACHE_REDIS/old-cache sections unchanged', function () {
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

it('.env.example ships OBS-2 pipeline readiness keys with safe defaults and no live secret', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('OBS_PIPELINE_ENABLED=true')
        ->and($env)->toContain('CENTRAL_LOGGING_ENABLED=false')
        ->and($env)->toContain('CENTRAL_LOGGING_DRIVER=none')
        ->and($env)->toContain('CENTRAL_LOGGING_SEND_PII=false')
        ->and($env)->toContain('ERROR_TRACKING_ENABLED=false')
        ->and($env)->toContain('ERROR_TRACKING_SAMPLE_RATE=0.0')
        ->and($env)->toContain('ERROR_TRACKING_SEND_PII=false')
        ->and($env)->toContain('OBS_PIPELINE_FAIL_ON_PII_RISK=true');
});

it('observability pipeline readiness and governance docs exist', function () {
    expect(file_exists(base_path('docs/architecture/centralized-logging-error-tracking-readiness.md')))->toBeTrue()
        ->and(file_exists(base_path('docs/architecture/observability-pipeline-governance-rules.md')))->toBeTrue();
});
