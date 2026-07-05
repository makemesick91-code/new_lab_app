<?php

use App\Support\Observability\CentralizedObservabilityReadinessService;

uses()->group('Observability', 'Obs2');

it('passes on default single VPS pilot configuration with logging/error tracking disabled', function () {
    $this->artisan('obs:pipeline-readiness-check')->assertExitCode(0);

    $result = app(CentralizedObservabilityReadinessService::class)->check();

    expect($result['decision'])->toBe('GO')
        ->and($result['central_logging_enabled'])->toBeFalse()
        ->and($result['error_tracking_enabled'])->toBeFalse()
        ->and($result['status'])->toBe('external_logging_not_enabled');
});

it('produces valid json output with expected keys', function () {
    $this->artisan('obs:pipeline-readiness-check', ['--json' => true])->assertExitCode(0);

    $result = app(CentralizedObservabilityReadinessService::class)->check();

    expect($result)->toHaveKeys([
        'status', 'decision', 'app_env', 'app_debug_safe', 'log_channel', 'log_stack_configured',
        'request_id_enabled', 'log_context_enabled',
        'central_logging_enabled', 'central_logging_driver', 'central_logging_endpoint_configured',
        'central_logging_api_key_configured_as_boolean_only', 'central_logging_send_pii',
        'error_tracking_enabled', 'error_tracking_driver', 'error_tracking_dsn_configured_as_boolean_only',
        'error_tracking_sample_rate', 'error_tracking_send_pii', 'warnings', 'recommendations',
    ]);
});

it('strict mode fails when central logging is enabled but driver/endpoint is missing', function () {
    config([
        'observability_pipeline.central_logging.enabled' => true,
        'observability_pipeline.central_logging.driver' => 'none',
        'observability_pipeline.central_logging.endpoint' => '',
    ]);

    $result = app(CentralizedObservabilityReadinessService::class)->check();
    expect($result['warnings'])->not->toBeEmpty()
        ->and($result['status'])->toBe('warning');

    $this->artisan('obs:pipeline-readiness-check', ['--strict' => true])->assertExitCode(1);
    $this->artisan('obs:pipeline-readiness-check')->assertExitCode(0);
});

it('strict mode fails when error tracking is enabled but driver/dsn is missing', function () {
    config([
        'observability_pipeline.error_tracking.enabled' => true,
        'observability_pipeline.error_tracking.driver' => 'none',
        'observability_pipeline.error_tracking.dsn' => '',
    ]);

    $result = app(CentralizedObservabilityReadinessService::class)->check();
    expect($result['status'])->toBe('warning');

    $this->artisan('obs:pipeline-readiness-check', ['--fail-on-warning' => true])->assertExitCode(1);
});

it('fails immediately when send-pii is enabled, regardless of --strict', function () {
    config(['observability_pipeline.central_logging.send_pii' => true]);

    $result = app(CentralizedObservabilityReadinessService::class)->check();

    expect($result['status'])->toBe('fail')
        ->and($result['decision'])->toBe('NO_GO');

    $this->artisan('obs:pipeline-readiness-check')->assertExitCode(1);
});

it('reports dsn and api key configuration as booleans only, never the raw value', function () {
    config([
        'observability_pipeline.central_logging.api_key' => 'fake-secret-abc123',
        'observability_pipeline.error_tracking.dsn' => 'https://fake-dsn.example.test/1',
    ]);

    $result = app(CentralizedObservabilityReadinessService::class)->check();

    expect($result['central_logging_api_key_configured_as_boolean_only'])->toBeTrue()
        ->and($result['error_tracking_dsn_configured_as_boolean_only'])->toBeTrue()
        ->and(json_encode($result))
        ->not->toContain('fake-secret-abc123')
        ->not->toContain('fake-dsn.example.test');
});

it('log smoke writes a safe synthetic line and reports written status', function () {
    $this->artisan('obs:pipeline-readiness-check', ['--log-smoke' => true])->assertExitCode(0);

    $smoke = app(CentralizedObservabilityReadinessService::class)->logSmoke();

    expect($smoke['status'])->toBe('written');
});

it('log smoke safely skips when disabled', function () {
    config(['observability_pipeline.log_smoke_enabled' => false]);

    $smoke = app(CentralizedObservabilityReadinessService::class)->logSmoke();

    expect($smoke['status'])->toBe('skipped');
});

it('error smoke is skipped by default and never throws unhandled or sends external traffic', function () {
    $smoke = app(CentralizedObservabilityReadinessService::class)->errorSmoke();

    expect($smoke['status'])->toBe('skipped');

    $this->artisan('obs:pipeline-readiness-check', ['--error-smoke' => true])->assertExitCode(0);
});

it('error smoke simulates safely without an unhandled exception when explicitly enabled', function () {
    config(['observability_pipeline.error_smoke_enabled' => true]);

    $smoke = app(CentralizedObservabilityReadinessService::class)->errorSmoke();

    expect($smoke['status'])->toBe('simulated');
});

it('confirms request id dependency from OBS-1 is reflected in readiness', function () {
    $result = app(CentralizedObservabilityReadinessService::class)->check();

    expect($result['request_id_enabled'])->toBeTrue()
        ->and($result['log_context_enabled'])->toBeTrue();

    config(['observability.request_id.enabled' => false]);

    $result = app(CentralizedObservabilityReadinessService::class)->check();
    expect($result['request_id_enabled'])->toBeFalse()
        ->and($result['warnings'])->not->toBeEmpty();
});

it('does not expose secret values in the readiness output', function () {
    $result = app(CentralizedObservabilityReadinessService::class)->check();

    expect(json_encode($result))
        ->not->toContain('APP_KEY')
        ->not->toContain('DB_PASSWORD')
        ->not->toContain('REDIS_PASSWORD');
});
