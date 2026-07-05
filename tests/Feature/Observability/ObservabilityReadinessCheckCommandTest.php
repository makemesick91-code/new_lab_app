<?php

use App\Support\Observability\ObservabilityReadinessService;

uses()->group('Observability', 'Obs1');

it('passes on default single VPS pilot configuration', function () {
    $this->artisan('obs:readiness-check')->assertExitCode(0);

    $result = app(ObservabilityReadinessService::class)->check();

    expect($result['decision'])->toBe('GO');
});

it('produces valid json output with expected keys', function () {
    $this->artisan('obs:readiness-check', ['--json' => true])->assertExitCode(0);

    $result = app(ObservabilityReadinessService::class)->check();

    expect($result)->toHaveKeys([
        'status', 'decision', 'app_env', 'app_debug_safe', 'log_channel', 'log_stack_configured',
        'observability_enabled', 'request_id_enabled', 'request_id_header', 'correlation_id_header',
        'response_header', 'trust_inbound_request_id', 'trust_inbound_correlation_id', 'max_id_length',
        'log_context_enabled', 'pii_masking_enabled', 'middleware_detected', 'warnings', 'recommendations',
    ]);
});

it('fails only under --strict when a warning condition exists, passes otherwise', function () {
    config(['observability.request_id.enabled' => false]);

    $result = app(ObservabilityReadinessService::class)->check();
    expect($result['warnings'])->not->toBeEmpty()
        ->and($result['status'])->toBe('warning');

    $this->artisan('obs:readiness-check', ['--strict' => true])->assertExitCode(1);
    $this->artisan('obs:readiness-check')->assertExitCode(0);
});

it('fails when observability is disabled while the persistent strict config flag is on', function () {
    config(['observability.enabled' => false, 'observability.strict' => true]);

    $this->artisan('obs:readiness-check')->assertExitCode(1);
});

it('header smoke safely simulates and confirms invalid inbound ids are rejected', function () {
    $this->artisan('obs:readiness-check', ['--header-smoke' => true])->assertExitCode(0);

    $smoke = app(ObservabilityReadinessService::class)->headerSmoke();

    expect($smoke['status'])->toBe('simulated')
        ->and($smoke['invalid_inbound_rejected'])->toBeTrue()
        ->and($smoke['response_header_present'])->toBeTrue();
});

it('header smoke skips with a clear reason when observability is disabled', function () {
    config(['observability.enabled' => false]);

    $smoke = app(ObservabilityReadinessService::class)->headerSmoke();

    expect($smoke['status'])->toBe('skipped')
        ->and($smoke['reason'])->not->toBeEmpty();
});

it('does not expose secret values in the readiness output', function () {
    $result = app(ObservabilityReadinessService::class)->check();

    expect(json_encode($result))
        ->not->toContain('APP_KEY')
        ->not->toContain('DB_PASSWORD')
        ->not->toContain('REDIS_PASSWORD');
});
