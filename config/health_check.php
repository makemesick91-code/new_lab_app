<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ENT-8 — Observability & Health Check Pack
    |--------------------------------------------------------------------------
    |
    | Foundation runtime feature built on OBS-1/OBS-2 and the LB-1 /health/lb
    | endpoint. It exposes minimal, non-sensitive liveness/readiness endpoints
    | plus a read-only readiness command that aggregates DB/cache/queue/storage
    | health and alerting-threshold readiness. It never renders PII, secrets,
    | DB host/credentials, driver internals, or stack traces, and it never sends
    | traffic to an external paging/alerting vendor.
    |
    */

    // Master switch for the ENT-8 health endpoints + command feature surface.
    'enabled' => (bool) env('HEALTH_CHECK_ENABLED', true),

    // Liveness endpoint: always cheap, confirms the PHP process/app booted.
    'liveness' => [
        'enabled' => (bool) env('HEALTH_CHECK_LIVENESS_ENABLED', true),
        'path' => env('HEALTH_CHECK_LIVENESS_PATH', '/health/live'),
    ],

    // Readiness endpoint: aggregates dependency health (component => ok|fail).
    // Kept minimal and non-sensitive; a short cache TTL avoids hammering the
    // dependencies when scraped by an uptime monitor or load balancer.
    'readiness' => [
        'enabled' => (bool) env('HEALTH_CHECK_READINESS_ENABLED', true),
        'path' => env('HEALTH_CHECK_READINESS_PATH', '/health/ready'),
        // Seconds to cache the aggregated readiness result (0 = no cache).
        'cache_ttl' => (int) env('HEALTH_CHECK_READINESS_CACHE_TTL', 5),
    ],

    // Which dependency components the readiness probe evaluates. Storage and
    // object_storage stay non-fatal on the endpoint (reported but a single
    // degraded auxiliary component does not fail the overall readiness unless
    // marked critical below).
    'components' => [
        'database' => ['enabled' => true, 'critical' => true],
        'cache' => ['enabled' => true, 'critical' => false],
        'queue' => ['enabled' => true, 'critical' => false],
        'storage' => ['enabled' => true, 'critical' => false],
        'object_storage' => ['enabled' => true, 'critical' => false],
    ],

    // Alerting-threshold readiness (ENT-8). These are *readiness thresholds*
    // only — no external channel is wired. A future MON-1/paging sprint may
    // consume them. Breaching a warn threshold => WATCH; a fail threshold =>
    // FAIL in the governance command (never on the public endpoints).
    'alerting' => [
        'enabled' => (bool) env('HEALTH_CHECK_ALERTING_ENABLED', true),
        // External paging/alerting channel stays OFF until a privacy-reviewed
        // sprint enables it (ENT-8 out_of_scope: external paging vendor).
        'external_channel_enabled' => (bool) env('HEALTH_CHECK_EXTERNAL_ALERT_ENABLED', false),
        'thresholds' => [
            'failed_jobs_warn' => (int) env('HEALTH_CHECK_FAILED_JOBS_WARN', 1),
            'failed_jobs_fail' => (int) env('HEALTH_CHECK_FAILED_JOBS_FAIL', 25),
            'pending_jobs_warn' => (int) env('HEALTH_CHECK_PENDING_JOBS_WARN', 500),
            'pending_jobs_fail' => (int) env('HEALTH_CHECK_PENDING_JOBS_FAIL', 5000),
            'storage_free_percent_warn' => (int) env('HEALTH_CHECK_STORAGE_FREE_PERCENT_WARN', 15),
            'storage_free_percent_fail' => (int) env('HEALTH_CHECK_STORAGE_FREE_PERCENT_FAIL', 5),
        ],
    ],

    // When true, warning-level readiness findings are treated as failures by
    // default in the governance command (mirrors LB-1 / observability configs).
    'strict' => (bool) env('HEALTH_CHECK_STRICT', false),

];
