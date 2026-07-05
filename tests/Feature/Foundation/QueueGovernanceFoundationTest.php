<?php

use App\Services\Foundation\FeatureFlagService;
use App\Services\Foundation\QueueGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'QueueGovernance', 'Queue1');

it('queue_governance config exists with QUEUE-1 metadata', function () {
    $config = config('queue_governance');

    expect($config)->toBeArray()
        ->and($config['metadata']['sprint'])->toBe('QUEUE-1')
        ->and($config['metadata']['status'])->toBe('implemented');
});

it('foundation queue governance check returns GO', function () {
    $exit = Artisan::call('foundation:queue-governance-check');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Decision: GO');
});

it('json output includes queue runtime idempotency and outbox status', function () {
    Artisan::call('foundation:queue-governance-check', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['summary']['decision'])->toBe('GO')
        ->and($report)->toHaveKeys(['queue_runtime', 'idempotency', 'outbox', 'global_rules']);
});

it('long running worker is disabled by default', function () {
    expect(config('queue_governance.queue_runtime.long_running_worker_enabled'))->toBeFalse();

    $report = app(QueueGovernanceService::class)->collect();
    expect($report['long_running_worker_enabled'])->toBeFalse();
});

it('redis sqs and external broker runtime are not enabled for queue-1', function () {
    $denied = config('queue_governance.queue_runtime.denied_for_queue_1');

    expect($denied)->toContain('redis production runtime', 'sqs production runtime', 'external broker production runtime');

    $allowed = config('queue_governance.queue_runtime.allowed_connections_readiness');
    expect($allowed)->toBe(['sync', 'database']);
});

it('queue feature flags exist and risky flags default off', function () {
    $flags = app(FeatureFlagService::class);
    $governance = $flags->validateGovernance();

    expect($flags->metadata('foundation.queue.outbox_readiness')['default'])->toBeFalse()
        ->and($flags->metadata('foundation.queue.idempotency_required')['default'])->toBeFalse()
        ->and($flags->metadata('foundation.queue.worker_readiness')['default'])->toBeFalse()
        ->and($flags->metadata('foundation.queue.external_dispatch_enabled')['default'])->toBeFalse()
        ->and($governance['summary']['decision'])->toBe('GO');
});

it('worker probe never starts a long running worker and stays non-blocking', function () {
    Artisan::call('foundation:queue-governance-check', ['--include-worker-probe' => true, '--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['worker_probe_requested'])->toBeTrue()
        ->and($report['summary']['decision'])->toBeIn(['GO', 'WATCH']);
});

it('queue governance json output does not expose secrets', function () {
    putenv('DB_PASSWORD=super-secret-test-value');

    Artisan::call('foundation:queue-governance-check', ['--json' => true]);
    $json = Artisan::output();

    expect($json)->not->toContain('super-secret-test-value');
});
