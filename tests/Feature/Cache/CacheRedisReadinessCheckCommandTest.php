<?php

use App\Support\Cache\RedisSharedCacheReadinessService;

uses()->group('Cache', 'Redis', 'CacheRedis1');

it('passes on default single VPS pilot configuration', function () {
    $this->artisan('cache:redis-readiness-check')
        ->assertExitCode(0);

    $result = app(RedisSharedCacheReadinessService::class)->check();

    expect($result['status'])->toBe('redis_not_expected')
        ->and($result['decision'])->toBe('GO');
});

it('produces valid json output with expected keys', function () {
    $this->artisan('cache:redis-readiness-check', ['--json' => true])
        ->assertExitCode(0);

    $result = app(RedisSharedCacheReadinessService::class)->check();

    expect($result)->toHaveKeys([
        'status', 'decision', 'app_env', 'app_debug_safe', 'cache_store',
        'session_driver', 'queue_connection', 'redis_expected', 'redis_connection',
        'redis_client_available', 'redis_host_configured', 'redis_port_configured',
        'redis_password_configured_as_boolean_only', 'connect_test_status',
        'ttl_test_status', 'lock_test_status', 'healthcheck_prefix',
        'warnings', 'recommendations',
    ]);

    expect(json_encode($result))->not->toBeFalse();
});

it('fails strict mode when Redis is expected but the client/connection is not usable', function () {
    config(['cache_scale.redis.expected' => true, 'cache_scale.redis.connection' => 'nonexistent_connection']);

    $this->artisan('cache:redis-readiness-check', ['--strict' => true])
        ->assertExitCode(1);
});

it('skips connect test safely when Redis is not expected and not requested', function () {
    $result = app(RedisSharedCacheReadinessService::class)->check();

    expect($result['connect_test_status'])->toBeIn(['skipped', 'unavailable']);
});

it('skips ttl test safely when Redis is not expected and not requested', function () {
    $result = app(RedisSharedCacheReadinessService::class)->check();

    expect($result['ttl_test_status'])->toBeIn(['skipped', 'unavailable']);
});

it('skips lock test safely when Redis is not expected and not requested', function () {
    $result = app(RedisSharedCacheReadinessService::class)->check();

    expect($result['lock_test_status'])->toBeIn(['skipped', 'unavailable']);
});

it('reports only a boolean for redis password configured, never the value', function () {
    config(['database.redis.cache.password' => 'super-secret-value']);

    $result = app(RedisSharedCacheReadinessService::class)->check();

    expect($result['redis_password_configured_as_boolean_only'])->toBeTrue()
        ->and(json_encode($result))->not->toContain('super-secret-value');
});

it('warns when Redis is expected but cache store/session driver are not redis', function () {
    config(['cache_scale.redis.expected' => true]);

    $result = app(RedisSharedCacheReadinessService::class)->check();

    expect($result['warnings'])->toContain('Redis is expected but the cache store (CACHE_STORE) is not redis.')
        ->and($result['warnings'])->toContain('Redis is expected but the session driver (SESSION_DRIVER) is not redis.');
});

it('never issues a flushdb/flushall or wildcard delete command', function () {
    $reflection = new ReflectionClass(RedisSharedCacheReadinessService::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->not->toContain('flushdb')
        ->and($source)->not->toContain('flushall')
        ->and($source)->not->toContain('flushDb')
        ->and($source)->not->toContain('flushAll')
        ->and($source)->not->toContain("del('*")
        ->and($source)->not->toContain('keys(');
});

it('fail-on-warning exits non-zero when warnings are present', function () {
    config(['cache_scale.redis.expected' => true]);

    $this->artisan('cache:redis-readiness-check', ['--fail-on-warning' => true])
        ->assertExitCode(1);
});
