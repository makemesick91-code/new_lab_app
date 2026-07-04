<?php

/**
 * NSF-9 — Automated smoke configuration.
 *
 * Read-only, safe route/command inventory consumed by
 * App\Services\Foundation\AutomatedSmokeService via release:automated-smoke.
 *
 * RULES:
 *  - Smoke is read-only: it never creates/updates/deletes patient, inventory,
 *    payment, lab, or RME records.
 *  - No credentials are used; only unauthenticated-safe route checks.
 *  - 200/302 are healthy; 500/503 are failures.
 */
return [
    'sprint' => 'NSF-9',

    // Named routes that must exist (route:list must be able to resolve them).
    // These are inspected only — never crawled with credentials here.
    'expected_route_names' => [
        'login',
        'dashboard',
        'rme.visits.index',
        'inventory.dashboard',
    ],

    // Route names safe to HTTP-probe without authentication (expect 200 or a
    // redirect to login, never 500/503). Protected routes are NOT crawled.
    'safe_unauthenticated_probe_routes' => [
        'login',
    ],

    'required_writable_paths' => [
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ],

    'required_governance_commands' => [
        'architecture:foundation-roadmap-check',
        'foundation:feature-flags',
        'foundation:release-safety-check',
    ],

    // HTTP status codes considered healthy when --base-url is supplied.
    'healthy_http_statuses' => [200, 301, 302, 401, 403],
    'failing_http_statuses' => [500, 502, 503, 504],

    'http_timeout_seconds' => 5,
];
