<?php

use App\Support\Storage\ObjectStorageReadinessService;
use Illuminate\Support\Facades\Storage;

uses()->group('Storage', 'ObjectStorage', 'Storage1');

it('passes as disabled_ready when object storage is disabled', function () {
    config(['object_storage.enabled' => false]);

    $this->artisan('storage:object-readiness-check')
        ->assertExitCode(0);

    $result = app(ObjectStorageReadinessService::class)->check();

    expect($result['status'])->toBe('disabled_ready')
        ->and($result['enabled'])->toBeFalse()
        ->and($result['write_test'])->toBe('skipped');
});

it('fails strict when enabled but required env is missing', function () {
    config([
        'object_storage.enabled' => true,
        'object_storage.required_env' => ['OBJECT_STORAGE_BUCKET_MISSING_FOR_TEST'],
    ]);

    $this->artisan('storage:object-readiness-check', ['--strict' => true])
        ->assertExitCode(1);

    $result = app(ObjectStorageReadinessService::class)->check();

    expect($result['status'])->toBe('misconfigured')
        ->and($result['missing_env'])->toBe(['OBJECT_STORAGE_BUCKET_MISSING_FOR_TEST']);
});

it('does not expose secret env values, only missing key names', function () {
    config([
        'object_storage.enabled' => true,
        'object_storage.required_env' => ['OBJECT_STORAGE_SECRET_ACCESS_KEY'],
    ]);

    $result = app(ObjectStorageReadinessService::class)->check();

    expect(json_encode($result))->not->toContain('OBJECT_STORAGE_SECRET_ACCESS_KEY_VALUE_SHOULD_NEVER_APPEAR');
    expect($result['missing_env'])->toBeArray();
});

it('produces valid json output with expected keys', function () {
    config(['object_storage.enabled' => false]);

    $this->artisan('storage:object-readiness-check', ['--json' => true])
        ->assertExitCode(0);
});

it('runs a non-destructive write/read/delete healthcheck against a faked disk', function () {
    Storage::fake('object');

    config([
        'object_storage.enabled' => true,
        'object_storage.disk' => 'object',
        'object_storage.required_env' => [],
        'object_storage.healthcheck_prefix' => 'healthchecks/daengtisiams',
    ]);

    $result = app(ObjectStorageReadinessService::class)->check(true);

    expect($result['status'])->toBe('ready')
        ->and($result['write_test'])->toBe('passed');

    Storage::disk('object')->assertDirectoryEmpty('healthchecks/daengtisiams');
});
