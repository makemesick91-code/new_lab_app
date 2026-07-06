<?php

use Illuminate\Support\Facades\Artisan;

uses()->group('Console', 'LoadTestBaseline', 'EnterpriseFoundation');

it('runs the guarded baseline runner in the testing environment', function () {
    expect(Artisan::call('loadtest:baseline-run', ['--dry-run' => true]))->toBe(0);
});

it('emits a non-sensitive dry-run evidence pack with the required shape', function () {
    Artisan::call('loadtest:baseline-run', ['--dry-run' => true, '--json' => true]);
    $output = Artisan::output();

    $pack = json_decode($output, true);

    expect($pack)->toBeArray()
        ->and($pack['sprint'])->toBe('ENT-13')
        ->and($pack['mode'])->toBe('dry_run')
        ->and($pack['branch_count'])->toBe(5)
        ->and($pack['latency_targets_ms']['p50'])->toBe(200)
        ->and($pack['latency_targets_ms']['p95'])->toBe(300)
        ->and($pack['privacy']['privacy_safe'])->toBeTrue()
        ->and($pack['results'])->not->toBeEmpty()
        ->and(preg_match('/\d{13,}/', $output))->toBe(0);

    foreach ($pack['results'] as $result) {
        expect($result)->toHaveKeys(['scenario', 'domain', 'status']);
    }
});

it('measures the scenarios read-only without writing rows', function () {
    expect(Artisan::call('loadtest:baseline-run', ['--json' => true]))->toBe(0);
    $pack = json_decode(Artisan::output(), true);

    expect($pack['mode'])->toBe('measured')
        ->and($pack['results'])->not->toBeEmpty();
});

it('aborts when the environment is not an allowed non-production environment', function () {
    config(['load_test_baseline.allowed_environments' => ['stress']]);

    expect(Artisan::call('loadtest:baseline-run'))->toBe(1);
});
