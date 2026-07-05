<?php

use App\Models\Foundation\OutboxEvent;
use App\Services\Foundation\OutboxService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses()->group('Foundation', 'Outbox', 'Queue1');

it('sys_outbox_events table exists (additive migration)', function () {
    expect(Schema::hasTable('sys_outbox_events'))->toBeTrue();
});

it('outbox event requires a schema version and payload classification', function () {
    $service = app(OutboxService::class);

    $event = $service->createSafeEvent('foundation.internal_test', ['note' => 'safe'], 'foundation.internal_test');

    expect($event->event_version)->toBe('v1')
        ->and($event->payload_classification)->toBe('foundation.internal_test')
        ->and($event->status)->toBe(OutboxEvent::STATUS_PENDING);
});

it('outbox rejects a denied payload classification', function () {
    $service = app(OutboxService::class);

    expect(fn () => $service->createSafeEvent('rme.something', ['x' => 1], 'rme.medical_record_content'))
        ->toThrow(InvalidArgumentException::class);
});

it('outbox rejects an unknown payload classification not in the allowlist', function () {
    $service = app(OutboxService::class);

    expect(fn () => $service->createSafeEvent('test.event', ['x' => 1], 'not_a_real_category'))
        ->toThrow(InvalidArgumentException::class);
});

it('outbox rejects payload keys that look like pii or secrets', function () {
    $service = app(OutboxService::class);

    expect(fn () => $service->createSafeEvent('foundation.internal_test', ['ktp' => '1234567890123456'], 'foundation.internal_test'))
        ->toThrow(InvalidArgumentException::class);
});

it('outbox does not dispatch external events without the feature flag', function () {
    $service = app(OutboxService::class);
    $event = $service->createSafeEvent('foundation.internal_test', ['note' => 'safe'], 'foundation.internal_test');

    expect(fn () => $service->markDispatched($event->event_uuid))
        ->toThrow(InvalidArgumentException::class);

    expect(config('queue_governance.outbox.dispatch_enabled'))->toBeFalse()
        ->and(config('queue_governance.outbox.external_dispatch_enabled'))->toBeFalse();
});

it('outbox retry policy moves event to failed after max attempts', function () {
    $service = app(OutboxService::class);
    $event = $service->createSafeEvent('foundation.internal_test', ['note' => 'safe'], 'foundation.internal_test');
    $event->update(['max_attempts' => 1]);

    $service->markProcessing($event->event_uuid);
    $failed = $service->markFailed($event->event_uuid, 'E_TEST', 'synthetic failure');

    expect($failed->status)->toBe(OutboxEvent::STATUS_FAILED)
        ->and($failed->last_error_code)->toBe('E_TEST');
});

it('outbox audit returns GO with zero or safe records', function () {
    $exit = Artisan::call('foundation:outbox-audit');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Decision: GO');
});

it('outbox audit json reports counts by status and classification', function () {
    app(OutboxService::class)->createSafeEvent('foundation.internal_test', ['note' => 'safe'], 'foundation.internal_test');

    Artisan::call('foundation:outbox-audit', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toHaveKeys(['by_status', 'by_payload_classification', 'total_records'])
        ->and($report['dispatch_enabled'])->toBeFalse()
        ->and($report['external_dispatch_enabled'])->toBeFalse();
});
