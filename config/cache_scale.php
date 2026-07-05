<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CACHE-1 — Redis Shared Cache & Session Readiness
    |--------------------------------------------------------------------------
    |
    | Readiness/audit only. These flags never switch CACHE_STORE, SESSION_DRIVER,
    | or the queue connection by themselves. Default stays current-production
    | safe (cache=file/array, session=database) until a separate sprint
    | explicitly enables Redis with its own rollback plan.
    |
    */

    'redis' => [
        'readiness_enabled' => env('CACHE_REDIS_READINESS_ENABLED', true),
        'expected' => env('CACHE_REDIS_EXPECTED', false),
        'strict' => env('CACHE_REDIS_STRICT', false),
        'connection' => env('CACHE_REDIS_CONNECTION', 'cache'),
        'session_connection' => env('CACHE_REDIS_SESSION_CONNECTION', 'session'),
        'healthcheck_enabled' => env('CACHE_REDIS_HEALTHCHECK_ENABLED', true),
        'healthcheck_prefix' => env('CACHE_REDIS_HEALTHCHECK_PREFIX', 'healthchecks:cache'),
        'connect_timeout_seconds' => env('CACHE_REDIS_CONNECT_TIMEOUT_SECONDS', 2),
        'operation_timeout_ms' => env('CACHE_REDIS_OPERATION_TIMEOUT_MS', 1500),
        'ttl_seconds' => env('CACHE_REDIS_TTL_SECONDS', 30),
        'expect_cache_store' => env('CACHE_REDIS_EXPECT_CACHE_STORE', false),
        'expect_session_driver' => env('CACHE_REDIS_EXPECT_SESSION_DRIVER', false),
        'expect_locks' => env('CACHE_REDIS_EXPECT_LOCKS', false),
        'fail_on_local_cache' => env('CACHE_REDIS_FAIL_ON_LOCAL_CACHE', false),
        'fail_on_local_session' => env('CACHE_REDIS_FAIL_ON_LOCAL_SESSION', false),
        'allow_single_vps_warnings' => env('CACHE_REDIS_ALLOW_SINGLE_VPS_WARNINGS', true),
    ],
];
