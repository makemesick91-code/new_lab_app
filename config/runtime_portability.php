<?php

return [

    /*
    |--------------------------------------------------------------------------
    | STATELESS-1 — Runtime Statelessness & Deploy Portability Readiness
    |--------------------------------------------------------------------------
    |
    | Foundation-only: this config never changes any session/cache/queue/
    | filesystem driver. It only feeds the read-only readiness command and
    | governance summary so the current runtime posture can be audited before
    | a future multi-instance/load-balanced deploy.
    |
    */

    'enabled' => (bool) env('STATELESS_READINESS_ENABLED', true),

    // Expectation flags — informational only, never force a driver change.
    'expect_shared_sessions' => (bool) env('STATELESS_EXPECT_SHARED_SESSIONS', false),
    'expect_shared_cache' => (bool) env('STATELESS_EXPECT_SHARED_CACHE', false),
    'expect_async_queue' => (bool) env('STATELESS_EXPECT_ASYNC_QUEUE', false),
    'expect_object_storage' => (bool) env('STATELESS_EXPECT_OBJECT_STORAGE', false),

    // Only these paths may be written by the running application.
    'allowed_local_write_paths' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STATELESS_ALLOWED_LOCAL_WRITE_PATHS', 'storage,bootstrap/cache'))
    ))),

    // Disk + prefix used for the non-destructive write/read/delete healthcheck.
    'healthcheck_disk' => env('STATELESS_HEALTHCHECK_DISK', 'local'),
    'healthcheck_prefix' => env('STATELESS_HEALTHCHECK_PREFIX', 'healthchecks/stateless'),

    // When true, warning-level findings are treated as failures by default.
    'strict' => (bool) env('STATELESS_STRICT', false),

    // Drivers considered risky for horizontal scale (informational only).
    'risky_drivers' => [
        'session' => ['file'],
        'cache' => ['file'],
        'queue' => ['sync'],
    ],

    // Runtime paths that must stay writable for the app to function.
    'required_writable_paths' => [
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ],

];
