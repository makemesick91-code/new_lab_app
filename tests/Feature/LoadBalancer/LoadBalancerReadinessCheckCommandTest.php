<?php

use App\Support\LoadBalancer\LoadBalancerReadinessService;

uses()->group('LoadBalancer', 'Lb1');

it('passes on default single VPS pilot configuration', function () {
    $this->artisan('lb:readiness-check')
        ->assertExitCode(0);

    $result = app(LoadBalancerReadinessService::class)->check();

    expect($result['status'])->toBe('single_vps_ready')
        ->and($result['decision'])->toBe('GO');
});

it('produces valid json output with expected keys', function () {
    $this->artisan('lb:readiness-check', ['--json' => true])
        ->assertExitCode(0);

    $result = app(LoadBalancerReadinessService::class)->check();

    expect($result)->toHaveKeys([
        'status', 'decision', 'app_env', 'app_debug_safe', 'lb_pilot_enabled',
        'health_endpoint_enabled', 'health_endpoint_path', 'trusted_proxies_configured',
        'expect_forwarded_headers', 'expect_https', 'app_url_scheme', 'session_driver',
        'session_secure_cookie', 'cache_store', 'queue_connection', 'object_storage_enabled',
        'runtime_stateless_status', 'warnings', 'recommendations',
    ]);
});

it('reports a warning when forwarded headers are expected but trusted proxies are empty', function () {
    config(['load_balancer.expect_forwarded_headers' => true, 'load_balancer.trusted_proxies' => []]);

    $result = app(LoadBalancerReadinessService::class)->check();

    expect($result['status'])->toBe('warning')
        ->and($result['warnings'])->toContain('Forwarded headers are expected but LB_TRUSTED_PROXIES is empty — X-Forwarded-* will be ignored until trusted proxies are configured.');

    $this->artisan('lb:readiness-check', ['--strict' => true])
        ->assertExitCode(1);

    $this->artisan('lb:readiness-check')
        ->assertExitCode(0);
});

it('reports an HTTPS/secure-cookie recommendation without forcing any change', function () {
    config(['load_balancer.expect_https' => true, 'session.secure' => false]);

    $result = app(LoadBalancerReadinessService::class)->check();

    expect($result['warnings'])->not->toBeEmpty()
        ->and(config('session.secure'))->toBeFalse();
});

it('becomes lb_pilot_ready when trusted proxies are configured and forwarded headers are expected', function () {
    config([
        'load_balancer.trusted_proxies' => ['10.0.0.1'],
        'load_balancer.expect_forwarded_headers' => true,
        'load_balancer.expect_https' => false,
        'load_balancer.expect_secure_cookies' => false,
    ]);

    $result = app(LoadBalancerReadinessService::class)->check();

    expect($result['status'])->toBe('lb_pilot_ready')
        ->and($result['decision'])->toBe('GO');
});

it('simulates forwarded header resolution in-process when trusted proxies are configured', function () {
    config(['load_balancer.trusted_proxies' => ['10.0.0.1']]);

    $this->artisan('lb:readiness-check', ['--header-smoke' => true])
        ->assertExitCode(0);

    $smoke = app(LoadBalancerReadinessService::class)->headerSmoke();

    expect($smoke['status'])->toBe('simulated')
        ->and($smoke['resolved_scheme'])->toBe('https')
        ->and($smoke['resolved_host'])->toBe('daengtisiams.local');
});

it('skips header smoke with a reason when trusted proxies are empty', function () {
    config(['load_balancer.trusted_proxies' => []]);

    $smoke = app(LoadBalancerReadinessService::class)->headerSmoke();

    expect($smoke['status'])->toBe('skipped')
        ->and($smoke['reason'])->not->toBeEmpty();
});

it('does not expose secret values in the readiness output', function () {
    config(['app.debug' => false]);

    $result = app(LoadBalancerReadinessService::class)->check();

    expect(json_encode($result))
        ->not->toContain('APP_KEY')
        ->not->toContain('OBJECT_STORAGE_SECRET_ACCESS_KEY');
});
