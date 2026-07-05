<?php

use App\Support\Runtime\RuntimePortabilityReadinessService;
use Illuminate\Support\Facades\Storage;

uses()->group('Runtime', 'RuntimePortability', 'Stateless', 'Stateless1');

it('passes on default single-node configuration', function () {
    $this->artisan('runtime:stateless-readiness-check')
        ->assertExitCode(0);

    $result = app(RuntimePortabilityReadinessService::class)->check();

    expect($result['status'])->not->toBe('fail')
        ->and($result['write_test_status'])->toBe('skipped');
});

it('produces valid json output with expected keys', function () {
    $this->artisan('runtime:stateless-readiness-check', ['--json' => true])
        ->assertExitCode(0);

    $result = app(RuntimePortabilityReadinessService::class)->check();

    expect($result)->toHaveKeys([
        'status', 'app_env', 'app_debug_safe', 'session_driver', 'cache_store',
        'queue_connection', 'filesystem_default_disk', 'object_storage_enabled',
        'local_write_paths_allowed', 'writable_paths_status',
        'horizontal_scale_warnings', 'recommendations', 'write_test_status',
    ]);
});

it('fails strict mode when a risky driver produces a warning', function () {
    config([
        'session.driver' => 'file',
        'cache.default' => 'file',
        'queue.default' => 'sync',
    ]);

    $result = app(RuntimePortabilityReadinessService::class)->check();
    expect($result['status'])->toBe('warning');

    $this->artisan('runtime:stateless-readiness-check', ['--strict' => true])
        ->assertExitCode(1);

    $this->artisan('runtime:stateless-readiness-check')
        ->assertExitCode(0);
});

it('runs a non-destructive write/read/delete healthcheck against a faked local disk', function () {
    Storage::fake('local');

    config([
        'runtime_portability.healthcheck_disk' => 'local',
        'runtime_portability.healthcheck_prefix' => 'healthchecks/stateless',
    ]);

    $result = app(RuntimePortabilityReadinessService::class)->check(true);

    expect($result['write_test_status'])->toBe('passed');

    Storage::disk('local')->assertDirectoryEmpty('healthchecks/stateless');
});

it('does not expose secret values in the readiness output', function () {
    config(['app.debug' => false]);

    $result = app(RuntimePortabilityReadinessService::class)->check();

    expect(json_encode($result))
        ->not->toContain('OBJECT_STORAGE_SECRET_ACCESS_KEY')
        ->not->toContain('APP_KEY');
});
