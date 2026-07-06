<?php

use App\Services\Foundation\DeveloperConsoleGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation');

it('passes with GO on the repo default configuration', function () {
    $report = app(DeveloperConsoleGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('developer_console_ready')
        ->and($report['console_enabled'])->toBeTrue()
        ->and($report['read_only'])->toBeTrue()
        ->and($report['route_registered'])->toBeTrue()
        ->and($report['mutating_routes'])->toBe([])
        ->and($report['audit_access_enabled'])->toBeTrue()
        ->and($report['masking_enabled'])->toBeTrue()
        ->and($report['queue_retry_decision'])->toBe('GO')
        ->and($report['idempotency_outbox_decision'])->toBe('GO');

    expect(Artisan::call('foundation:developer-console-check'))->toBe(0)
        ->and(Artisan::call('foundation:developer-console-check', ['--strict' => true]))->toBe(0);
});

it('emits a machine-readable JSON report', function () {
    Artisan::call('foundation:developer-console-check', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['sprint'])->toBe('ENT-7')
        ->and($report)->toHaveKeys([
            'decision', 'readiness_status', 'console_enabled', 'read_only',
            'permission', 'route_registered', 'audit_access_enabled',
            'masking_enabled', 'checks', 'summary', 'rules', 'commands',
        ])
        ->and($report['privacy']['privacy_safe'])->toBeTrue();
});

it('fails when the console is not declared read-only', function () {
    config(['developer_console.read_only' => false]);

    $report = app(DeveloperConsoleGovernanceService::class)->collect();
    $check = collect($report['checks'])->firstWhere('check_id', 'ENT7-DC-READ-ONLY-DECLARED');

    expect($report['decision'])->toBe('FAIL')
        ->and($check['status'])->toBe('failed')
        ->and(Artisan::call('foundation:developer-console-check'))->toBe(1);
});

it('fails when masking is disabled', function () {
    config(['developer_console.masking.enabled' => false]);

    $report = app(DeveloperConsoleGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and(collect($report['checks'])->firstWhere('check_id', 'ENT7-DC-MASKING-CONFIG')['status'])->toBe('failed');
});

it('fails when access auditing is disabled', function () {
    config(['developer_console.audit_access.enabled' => false]);

    $report = app(DeveloperConsoleGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and(collect($report['checks'])->firstWhere('check_id', 'ENT7-DC-AUDIT-ACCESS')['status'])->toBe('failed');
});

it('fails when the console permission is changed away from the locked name', function () {
    config(['developer_console.permission' => 'view reports']);

    $report = app(DeveloperConsoleGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and(collect($report['checks'])->firstWhere('check_id', 'ENT7-DC-PERMISSION-CONFIG')['status'])->toBe('failed');
});
