<?php

use App\Services\Foundation\HealthCheckGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation');

it('passes with GO on the repo default configuration', function () {
    $report = app(HealthCheckGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('health_check_ready')
        ->and($report['health_check_enabled'])->toBeTrue()
        ->and($report['liveness_route_registered'])->toBeTrue()
        ->and($report['readiness_route_registered'])->toBeTrue()
        ->and($report['mutating_routes'])->toBe([])
        ->and($report['external_alert_channel_enabled'])->toBeFalse()
        ->and($report['lb_health_endpoint_registered'])->toBeTrue()
        ->and($report['queue_retry_decision'])->toBe('GO')
        ->and($report['idempotency_outbox_decision'])->toBe('GO')
        ->and($report['developer_console_decision'])->toBe('GO');

    expect(Artisan::call('foundation:health-check'))->toBe(0)
        ->and(Artisan::call('foundation:health-check', ['--strict' => true]))->toBe(0);
});

it('emits a machine-readable JSON report', function () {
    Artisan::call('foundation:health-check', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['sprint'])->toBe('ENT-8')
        ->and($report)->toHaveKeys([
            'decision', 'readiness_status', 'health_check_enabled',
            'liveness_route_registered', 'readiness_route_registered',
            'components', 'readiness_overall_status', 'alerting_status',
            'external_alert_channel_enabled', 'checks', 'summary', 'rules', 'commands',
        ])
        ->and($report['privacy']['privacy_safe'])->toBeTrue()
        ->and($report['components'])->toContain('database');
});

it('never leaks a forbidden literal or long digit run in the JSON report', function () {
    Artisan::call('foundation:health-check', ['--json' => true]);
    $raw = Artisan::output();

    foreach (config('release_evidence.forbidden_patterns', []) as $pattern) {
        expect(str_contains($raw, $pattern))->toBeFalse(
            "health-check JSON must not contain forbidden literal: {$pattern}"
        );
    }

    expect(preg_match('/\d{16}/', $raw))->toBe(0);
});

it('fails when an external paging/alerting channel is enabled', function () {
    config(['health_check.alerting.external_channel_enabled' => true]);

    $report = app(HealthCheckGovernanceService::class)->collect();
    $check = collect($report['checks'])->firstWhere('check_id', 'ENT8-HC-NO-EXTERNAL-VENDOR');

    expect($report['decision'])->toBe('FAIL')
        ->and($check['status'])->toBe('failed')
        ->and(Artisan::call('foundation:health-check'))->toBe(1);
});

it('fails when the database component is not declared critical', function () {
    config(['health_check.components.database.critical' => false]);

    $report = app(HealthCheckGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and(collect($report['checks'])->firstWhere('check_id', 'ENT8-HC-COMPONENTS')['status'])->toBe('failed');
});
