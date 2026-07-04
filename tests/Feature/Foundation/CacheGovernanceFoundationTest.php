<?php

use App\Services\Foundation\CacheGovernanceService;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'CacheGovernance');

it('cache_governance config exists with CACHE-1 metadata', function () {
    $config = config('cache_governance');

    expect($config)->toBeArray()
        ->and($config['metadata']['sprint'])->toBe('CACHE-1')
        ->and($config['metadata']['status'])->toBe('implemented');
});

it('foundation cache governance check returns GO', function () {
    $exit = Artisan::call('foundation:cache-governance-check');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Decision: GO');
});

it('json output includes status checks categories and denied categories', function () {
    Artisan::call('foundation:cache-governance-check', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['summary']['decision'])->toBe('GO')
        ->and($report)->toHaveKeys(['checks', 'allowed_categories', 'denied_categories', 'global_rules', 'key_naming'])
        ->and($report['allowed_categories'])->not->toBeEmpty()
        ->and($report['denied_categories'])->not->toBeEmpty();
});

it('allowed categories have ttl scope store and invalidation metadata', function () {
    $report = app(CacheGovernanceService::class)->collect();

    foreach ($report['allowed_categories'] as $category) {
        expect($category['ttl_seconds'])->not->toBeNull()
            ->and($category['scope'])->not->toBeNull()
            ->and($category['allowed_store'])->not->toBeNull()
            ->and($category['requires_invalidation'])->toBeTrue()
            ->and($category['pii_allowed'])->toBeFalse();
    }
});

it('denied critical mutable categories are documented and not allowed', function () {
    $config = config('cache_governance');
    $allowedKeys = array_keys($config['allowed_cache_categories']);
    $deniedKeys = array_keys($config['denied_cache_categories']);

    expect($deniedKeys)->toContain(
        'inventory.current_stock_mutable',
        'cashier.payment_state',
        'rme.finalization_state',
        'branch_context.current_branch',
        'patient.identity_pii',
    );

    expect(array_intersect($allowedKeys, $deniedKeys))->toBeEmpty();
});

it('key policy bans pii raw identifiers', function () {
    $forbidden = config('cache_governance.key_naming.forbidden_raw_identifiers');

    expect($forbidden)->toContain('ktp', 'nik', 'patient_name', 'rm_number');
    expect(config('cache_governance.global_rules.no_pii_in_cache_keys'))->toBeTrue();
    expect(config('cache_governance.global_rules.no_secrets_in_cache'))->toBeTrue();
});

it('branch scoped categories require branch segment policy', function () {
    $allowed = config('cache_governance.allowed_cache_categories');

    foreach ($allowed as $key => $category) {
        if (($category['scope'] ?? '') === 'branch') {
            expect($category['branch_scope_required'])->toBeTrue("{$key} must require branch scope");
        }
    }
});

it('global categories are explicitly allowlisted', function () {
    $allowlist = config('cache_governance.global_key_allowlist');
    $allowed = config('cache_governance.allowed_cache_categories');

    foreach ($allowed as $key => $category) {
        if (($category['scope'] ?? '') === 'global') {
            expect($allowlist)->toContain($key);
        }
    }
});

it('redis readiness is disabled and readiness only by default', function () {
    $redis = config('cache_governance.redis_readiness');

    expect($redis['default_status'])->toBe('readiness_only')
        ->and($redis['production_default_enabled'])->toBeFalse();
});

it('redis probe is not required for normal GO', function () {
    $report = app(CacheGovernanceService::class)->collect(includeRedisProbe: false);

    expect($report['summary']['decision'])->toBe('GO')
        ->and($report['redis_probe_requested'])->toBeFalse();
});

it('redis probe failure is WATCH when redis runtime is not enabled', function () {
    config(['cache.default' => 'array']);

    $report = app(CacheGovernanceService::class)->collect(includeRedisProbe: true);

    if (($report['redis_probe']['decision'] ?? 'GO') !== 'GO') {
        expect($report['summary']['decision'])->toBe('WATCH');
    } else {
        expect($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
    }
})->skip(fn () => config('cache.default') === 'redis', 'Redis store enabled in environment');

it('redis probe failure is FAIL when redis runtime is enabled', function () {
    config(['cache.default' => 'redis']);

    $report = app(CacheGovernanceService::class)->collect(includeRedisProbe: true);

    if (($report['redis_probe']['decision'] ?? 'GO') !== 'GO') {
        expect($report['summary']['decision'])->toBe('FAIL');
    }
})->skip(fn () => config('cache.default') !== 'redis', 'Requires redis cache store for runtime-enabled probe failure test');

it('cache feature flags exist and risky flags default off', function () {
    $flags = app(FeatureFlagService::class);
    $governance = $flags->validateGovernance();

    expect($flags->metadata('foundation.cache.redis_readiness')['default'])->toBeFalse()
        ->and($flags->metadata('foundation.cache.invalidation_governance')['default'])->toBeFalse()
        ->and($governance['summary']['decision'])->toBe('GO');
});

it('cache governance json output does not expose redis password env keys', function () {
    putenv('REDIS_PASSWORD=super-secret-test-value');

    Artisan::call('foundation:cache-governance-check', ['--json' => true]);
    $json = Artisan::output();

    expect($json)->not->toContain('super-secret-test-value')
        ->and($json)->not->toContain('REDIS_PASSWORD=super-secret');
});
