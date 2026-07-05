<?php

use App\Models\Foundation\IdempotencyKey;
use App\Services\Foundation\IdempotencyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses()->group('Foundation', 'Idempotency', 'Queue1');

it('sys_idempotency_keys table exists (additive migration)', function () {
    expect(Schema::hasTable('sys_idempotency_keys'))->toBeTrue();
});

it('idempotency hashes raw key and does not store raw key', function () {
    $service = app(IdempotencyService::class);
    $raw = 'raw-super-secret-idempotency-key-12345';

    $result = $service->reserve('queue_job', $raw);
    $record = $result['record'];

    expect($record->key_hash)->toBe(hash('sha256', $raw))
        ->and($record->key_hash)->not->toBe($raw);

    $columns = Schema::getColumnListing('sys_idempotency_keys');
    expect($columns)->not->toContain('raw_key')->not->toContain('key');

    $row = IdempotencyKey::query()->find($record->id);
    expect(json_encode($row->toArray()))->not->toContain($raw);
});

it('idempotency reserve is transactional and conflict safe on duplicate reservation', function () {
    $service = app(IdempotencyService::class);
    $raw = 'duplicate-reservation-key';

    $first = $service->reserve('queue_job', $raw);
    $second = $service->reserve('queue_job', $raw);

    expect($first['created'])->toBeTrue()
        ->and($second['created'])->toBeFalse()
        ->and($second['record']->id)->toBe($first['record']->id);

    expect(IdempotencyKey::query()->where('key_hash', hash('sha256', $raw))->count())->toBe(1);
});

it('idempotency complete and fail transition status correctly', function () {
    $service = app(IdempotencyService::class);
    $raw = 'complete-fail-transition-key';

    $service->reserve('queue_job', $raw);
    $completed = $service->complete('queue_job', $raw, 'response-fingerprint');

    expect($completed->status)->toBe(IdempotencyKey::STATUS_COMPLETED)
        ->and($completed->completed_at)->not->toBeNull();

    $raw2 = 'fail-transition-key';
    $service->reserve('queue_job', $raw2);
    $failed = $service->fail('queue_job', $raw2, 'boom');

    expect($failed->status)->toBe(IdempotencyKey::STATUS_FAILED)
        ->and($failed->failed_at)->not->toBeNull();
});

it('idempotency metadata rejects pii-shaped keys', function () {
    $service = app(IdempotencyService::class);
    $result = $service->reserve('queue_job', 'metadata-safety-key', ['ktp' => '1234567890123456', 'note' => 'safe']);

    expect($result['record']->metadata)->not->toHaveKey('ktp')
        ->and($result['record']->metadata)->toHaveKey('note');
});

it('idempotency audit returns GO with zero or safe records', function () {
    $exit = Artisan::call('foundation:idempotency-audit');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Decision: GO');
});

it('idempotency audit json reports counts by status and scope without raw keys', function () {
    app(IdempotencyService::class)->reserve('queue_job', 'audit-visibility-key');

    Artisan::call('foundation:idempotency-audit', ['--json' => true]);
    $json = Artisan::output();
    $report = json_decode($json, true);

    expect($report)->toHaveKeys(['by_status', 'by_scope', 'total_records'])
        ->and($json)->not->toContain('audit-visibility-key');
});

it('governance config bans raw idempotency key storage', function () {
    expect(config('queue_governance.idempotency.raw_key_storage_allowed'))->toBeFalse()
        ->and(config('queue_governance.idempotency.key_hashing_required'))->toBeTrue();
});
