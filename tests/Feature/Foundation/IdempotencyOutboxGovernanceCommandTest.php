<?php

use App\Services\Foundation\IdempotencyOutboxGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation');

it('passes with GO on the repo default configuration', function () {
    $report = app(IdempotencyOutboxGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('idempotency_outbox_ready')
        ->and($report['idempotency_table_exists'])->toBeTrue()
        ->and($report['outbox_table_exists'])->toBeTrue()
        ->and($report['external_dispatch_enabled'])->toBeFalse()
        ->and($report['queue_retry_decision'])->toBe('GO');

    expect(Artisan::call('foundation:idempotency-outbox-check'))->toBe(0)
        ->and(Artisan::call('foundation:idempotency-outbox-check', ['--strict' => true]))->toBe(0);
});

it('emits a machine-readable JSON report', function () {
    Artisan::call('foundation:idempotency-outbox-check', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['sprint'])->toBe('ENT-6')
        ->and($report)->toHaveKeys([
            'decision', 'readiness_status', 'idempotency_table_exists',
            'outbox_table_exists', 'external_dispatch_enabled', 'checks',
            'summary', 'rules', 'commands',
        ])
        ->and($report['privacy']['privacy_safe'])->toBeTrue();
});

it('fails when raw idempotency key storage is allowed', function () {
    config(['queue_governance.idempotency.raw_key_storage_allowed' => true]);

    $report = app(IdempotencyOutboxGovernanceService::class)->collect();
    $check = collect($report['checks'])->firstWhere('check_id', 'ENT6-IO-IDEMPOTENCY-CONFIG');

    expect($report['decision'])->toBe('FAIL')
        ->and($check['status'])->toBe('failed')
        ->and(Artisan::call('foundation:idempotency-outbox-check'))->toBe(1);
});

it('fails when outbox external dispatch is enabled', function () {
    config(['queue_governance.outbox.external_dispatch_enabled' => true]);

    $report = app(IdempotencyOutboxGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and(collect($report['checks'])->firstWhere('check_id', 'ENT6-IO-EXTERNAL-DISPATCH-OFF')['status'])->toBe('failed');
});

it('fails when outbox configuration allows a denied category', function () {
    config(['queue_governance.outbox.allowed_event_categories' => ['patient.identity_pii']]);

    $report = app(IdempotencyOutboxGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and(collect($report['checks'])->firstWhere('check_id', 'ENT6-IO-OUTBOX-CONFIG')['status'])->toBe('failed');
});
