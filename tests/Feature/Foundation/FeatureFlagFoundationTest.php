<?php

use App\Services\Foundation\FeatureFlagService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FeatureFlag');

it('feature flag config exists', function () {
    $config = config('feature_flags');

    expect($config)->toBeArray()
        ->and($config['flags'])->not->toBeEmpty();
});

it('all flags have required metadata', function () {
    $service = app(FeatureFlagService::class);
    $flags = $service->all();

    foreach ($flags as $key => $flag) {
        foreach (['name', 'description', 'default', 'env_key', 'owner', 'risk_level', 'rollout_status', 'dependencies', 'rollback_action', 'enabled'] as $field) {
            expect(array_key_exists($field, $flag))->toBeTrue("flag {$key} missing {$field}");
        }
    }
});

it('risky future infra flags default false', function () {
    $risky = [
        'foundation.cache.redis_readiness',
        'foundation.cache.invalidation_governance',
        'foundation.queue.outbox_readiness',
        'foundation.queue.idempotency_required',
        'foundation.db.pg_bouncer_readiness',
        'foundation.reporting.materialized_summary_readiness',
        'foundation.storage.object_storage_readiness',
        'foundation.stateless_app_readiness',
        'foundation.load_balancer_pilot',
        'foundation.read_replica_readiness',
        'foundation.partitioning_design_only',
        'foundation.search_log_explorer_readiness',
        'foundation.national_distributed_architecture_plan',
    ];

    $service = app(FeatureFlagService::class);

    foreach ($risky as $key) {
        $flag = $service->get($key);
        expect($flag['default'])->toBeFalse("flag {$key} must default false")
            ->and($flag['enabled'])->toBeFalse("flag {$key} must not be enabled by default");
    }
});

it('governance safety flags are valid and implemented', function () {
    $service = app(FeatureFlagService::class);

    foreach ([
        'release.automated_smoke_required',
        'release.rollback_checklist_required',
        'release.feature_flag_required_for_risky_changes',
    ] as $key) {
        $flag = $service->get($key);
        expect($flag['rollout_status'])->toBe('implemented')
            ->and($flag['risk_level'])->toBe('low');
    }
});

it('no risky future infra flag is accidentally enabled', function () {
    $service = app(FeatureFlagService::class);

    expect($service->riskyEnabledFlags())->toBe([]);
});

it('registers the foundation feature flags command', function () {
    expect(Artisan::all())->toHaveKey('foundation:feature-flags');
});

it('command foundation:feature-flags returns GO', function () {
    $exitCode = Artisan::call('foundation:feature-flags');

    expect($exitCode)->toBe(0)
        ->and(app(FeatureFlagService::class)->validateGovernance()['summary']['decision'])->toBe('GO');
});

it('command foundation:feature-flags --json includes all flags', function () {
    $exitCode = Artisan::call('foundation:feature-flags', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['flags', 'governance'])
        ->and(count($payload['flags']))->toBe(count(config('feature_flags.flags')));
});

it('feature flag governance validates unsafe risky default as FAIL', function () {
    $flags = config('feature_flags.flags');
    $flags['foundation.cache.redis_readiness']['default'] = true;
    config(['feature_flags.flags' => $flags]);

    $service = app(FeatureFlagService::class);
    $governance = $service->validateGovernance();

    expect($governance['summary']['decision'])->toBe('FAIL');
});
