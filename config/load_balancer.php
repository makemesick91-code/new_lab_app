<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LB-1 — Load Balancer Pilot Readiness
    |--------------------------------------------------------------------------
    |
    | Foundation-only: this config never forces a trusted-proxy, HTTPS, or
    | secure-cookie change by itself. It only feeds the read-only readiness
    | command, the minimal /health/lb endpoint, and the governance summary so
    | the app's posture behind a future reverse proxy/load balancer can be
    | audited before real multi-node traffic is introduced.
    |
    */

    'pilot_enabled' => (bool) env('LB_PILOT_ENABLED', true),

    // Comma-separated trusted proxy IP/CIDR list. Empty = trust none (safe
    // single-VPS default; forwarded headers are not honored until set).
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('LB_TRUSTED_PROXIES', ''))
    ))),

    // Expectation flags — informational only, never force a behavior change.
    'expect_forwarded_headers' => (bool) env('LB_EXPECT_FORWARDED_HEADERS', false),
    'expect_https' => (bool) env('LB_EXPECT_HTTPS', false),
    'expect_secure_cookies' => (bool) env('LB_EXPECT_SECURE_COOKIES', false),

    'health_endpoint_enabled' => (bool) env('LB_HEALTH_ENDPOINT_ENABLED', true),
    'health_endpoint_path' => env('LB_HEALTH_ENDPOINT_PATH', '/health/lb'),

    // Deep check stays OFF by default; when on it only pings the DB connection
    // (no data returned) and never changes the minimal response payload shape.
    'health_deep_check' => (bool) env('LB_HEALTH_DEEP_CHECK', false),

    // In-process (no real network call) forwarded-header simulation for the
    // readiness command's --header-smoke option.
    'header_smoke_enabled' => (bool) env('LB_HEADER_SMOKE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default.
    'strict' => (bool) env('LB_STRICT', false),

];
