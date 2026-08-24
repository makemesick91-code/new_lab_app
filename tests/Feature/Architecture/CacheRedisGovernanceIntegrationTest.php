<?php

use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Foundation\CacheRedisGovernanceService;

uses()->group('Architecture', 'Cache', 'Redis', 'Governance', 'FoundationGovernance');

it('foundation summary includes CACHE_REDIS_GOVERNANCE section with the CACHE-R rules', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('cache_redis_governance')
        ->and($summary['cache_redis_governance']['decision'])->toBe('GO');

    $ruleIds = collect($summary['cache_redis_governance']['rules'])->pluck('id')->all();

    expect($ruleIds)->toBe([
        'CACHE-R001', 'CACHE-R002', 'CACHE-R003', 'CACHE-R004', 'CACHE-R005', 'CACHE-R006',
        'CACHE-R007', 'CACHE-R008', 'CACHE-R009', 'CACHE-R010', 'CACHE-R011', 'CACHE-R012',
    ]);
});

it('foundation summary still includes STORAGE/STATELESS/LB/REPLICA and the earlier CACHE_GOVERNANCE sections unchanged', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary)->toHaveKey('storage_governance')
        ->and($summary)->toHaveKey('stateless_governance')
        ->and($summary)->toHaveKey('lb_governance')
        ->and($summary)->toHaveKey('database_replica_governance')
        ->and($summary)->toHaveKey('cache_governance');

    $storageRuleIds = collect($summary['storage_governance']['rules'])->pluck('id')->all();
    $statelessRuleIds = collect($summary['stateless_governance']['rules'])->pluck('id')->all();
    $lbRuleIds = collect($summary['lb_governance']['rules'])->pluck('id')->all();
    $replicaRuleIds = collect($summary['database_replica_governance']['rules'])->pluck('id')->all();

    expect($storageRuleIds)->toBe(['STORAGE-R001', 'STORAGE-R002', 'STORAGE-R003', 'STORAGE-R004', 'STORAGE-R005', 'STORAGE-R006'])
        ->and($statelessRuleIds)->toBe([
            'STATELESS-R001', 'STATELESS-R002', 'STATELESS-R003', 'STATELESS-R004',
            'STATELESS-R005', 'STATELESS-R006', 'STATELESS-R007', 'STATELESS-R008',
        ])
        ->and($lbRuleIds)->toBe([
            'LB-R001', 'LB-R002', 'LB-R003', 'LB-R004', 'LB-R005',
            'LB-R006', 'LB-R007', 'LB-R008', 'LB-R009', 'LB-R010',
        ])
        ->and($replicaRuleIds)->toHaveCount(12);
});

it('foundation-governance-summary combined decision stays GO in single-node mode', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['combined']['decision'])->toBe('GO');
});

it('cache redis governance watches (readiness_status warning) when Redis is expected but not aligned', function () {
    config(['cache_scale.redis.expected' => true]);

    $result = app(CacheRedisGovernanceService::class)->collect();

    expect($result['decision'])->toBe('GO')
        ->and($result['readiness_status'])->toBe('warning')
        ->and($result['warnings'])->not->toBeEmpty();
});

it('.env.example ships CACHE_REDIS readiness keys without real secret values', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('CACHE_REDIS_READINESS_ENABLED=true')
        ->and($env)->toContain('CACHE_REDIS_EXPECTED=false')
        ->and($env)->toContain('CACHE_REDIS_HEALTHCHECK_PREFIX=healthchecks:cache')
        ->and($env)->toContain('REDIS_CACHE_DB=1')
        ->and($env)->toContain('REDIS_SESSION_DB=2')
        ->and($env)->not->toContain('REDIS_PASSWORD=secret');
});

it('redis shared cache/session readiness and governance docs exist', function () {
    expect(file_exists(base_path('docs/architecture/redis-shared-cache-session-readiness.md')))->toBeTrue()
        ->and(file_exists(base_path('docs/architecture/cache-redis-governance-rules.md')))->toBeTrue();
});
