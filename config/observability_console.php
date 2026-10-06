<?php

/*
|--------------------------------------------------------------------------
| FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — Observability Console telemetry
|--------------------------------------------------------------------------
|
| The single home for every observability threshold, retention window and
| exclusion. No number below may be repeated anywhere else in the codebase:
| the recorder, the console read models and the tests all read it from here.
|
| THE NON-NEGOTIABLE RULE. Telemetry never stores a raw password, token,
| cookie, session id, request body, KTP/NIK, clinical note, SOAP, RME payload
| or odontogram payload. It stores identifiers, route TEMPLATES, timings and
| sanitized exception summaries only. SQL is stored normalized (every literal
| replaced) and bindings are never stored at all.
|
| WRITES NEVER DELAY A RESPONSE. Events are persisted in the middleware's
| terminate() phase, which PHP-FPM runs after the response has been flushed to
| the client. Monitoring must not materially degrade production.
|
*/

return [

    // Master switch for request telemetry. When false the recorder does
    // nothing at all — no write, no query listener work, no cache counting.
    // The test suite turns it off by default (phpunit.xml) so existing query
    // budget tests measure the application, not the telemetry; the
    // observability suite turns it on explicitly.
    'enabled' => (bool) env('OBSERVABILITY_TELEMETRY_ENABLED', true),

    // Presence is derived from the last recorded request — there is no
    // client-side heartbeat. A user reading one page for three minutes is,
    // truthfully, IDLE: that is what the word means here.
    'presence' => [
        'online_seconds' => (int) env('OBSERVABILITY_ONLINE_SECONDS', 120),
        'idle_seconds' => (int) env('OBSERVABILITY_IDLE_SECONDS', 300),
        // How far back the Live Users page looks for users at all.
        'window_hours' => 24,
        // Bounded recent-activity list for the selected user.
        'recent_activity_limit' => 50,
    ],

    // Request latency categories, in milliseconds (inclusive lower bounds).
    'latency' => [
        'watch_ms' => (int) env('OBSERVABILITY_WATCH_MS', 500),
        'slow_ms' => (int) env('OBSERVABILITY_SLOW_MS', 1000),
        'very_slow_ms' => (int) env('OBSERVABILITY_VERY_SLOW_MS', 3000),
    ],

    // Slow-query capture, in milliseconds (inclusive).
    'slow_query' => [
        'threshold_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 500),
        'critical_ms' => (int) env('OBSERVABILITY_CRITICAL_QUERY_MS', 2000),
        // At most this many slow queries are kept for one request.
        'max_per_request' => 20,
        // Normalized SQL is truncated to this length before storage.
        'max_sql_length' => 2000,
        // A request issuing at least this many queries carries an
        // "possible N+1" HINT. A hint, never a diagnosis.
        'n_plus_one_hint_query_count' => 100,
    ],

    // HTTP statuses recorded as errors. An exception carried by a response
    // is normalized first: a web ValidationException (rendered as a 302
    // redirect back) counts as 422 and an AuthenticationException (302 to
    // login) counts as 401, so the console reports what actually happened
    // rather than the redirect that delivered it.
    'error_statuses' => [401, 403, 404, 405, 419, 422, 429, 500, 502, 503, 504],

    // Retention in hours. Every row carries the flags that decide its fate;
    // a row lives as long as its LONGEST applicable window.
    'retention' => [
        'access_hours' => (int) env('OBSERVABILITY_ACCESS_RETENTION_HOURS', 72),
        'error_hours' => (int) env('OBSERVABILITY_ERROR_RETENTION_HOURS', 24 * 30),
        'slow_request_hours' => (int) env('OBSERVABILITY_SLOW_RETENTION_HOURS', 24 * 14),
        'slow_query_hours' => (int) env('OBSERVABILITY_SLOW_QUERY_RETENTION_HOURS', 24 * 14),
        // Production's scheduler is registered but never fires, so pruning
        // runs as a lottery on recorded requests (the session-GC pattern)
        // and is ALSO available as `php artisan observability:prune`.
        'lottery' => [1, 200],
        // One lottery pass deletes at most this many rows per table — the
        // prune can never become the slow request it is meant to bound.
        'batch_size' => 1000,
    ],

    // Guest events (401/404 probes, bots) are capped per minute so a scanner
    // cannot flood the table. Authenticated activity is never sampled.
    'guest_events_per_minute' => (int) env('OBSERVABILITY_GUEST_EVENTS_PER_MINUTE', 60),

    // Requests that are never recorded at all: health probes and polling.
    // A path entry ending in "/" is a PREFIX; any other entry is EXACT (so
    // "up" never swallows "uploads/..."). Route names are matched exactly.
    'exclude' => [
        'path_prefixes' => ['up', 'health/', 'build/', 'favicon.ico', 'manifest.webmanifest', 'sw.js'],
        'route_names' => [
            'health.live', 'health.ready', 'health.lb',
            // 4-second polling of a legacy import's render status.
            'settings.rme.legacy-imports.status',
        ],
    ],

    // The working branch shown beside an event is OBSERVATIONAL, never an
    // authority: it is read (SELECT only) from the user's online context and
    // memoized briefly. BranchContext::forUser() is deliberately NOT used —
    // it can lazily mark an expired context inactive, and telemetry must
    // never write to a clinical-workflow table.
    'actor_context_cache_seconds' => 120,

    // Redis is probed ONLY when this deployment actually uses it for the
    // cache, session or queue. Anything else is reported as NOT IN USE —
    // never as healthy, never as down.
    'redis' => [
        'probe_when_unused' => (bool) env('OBSERVABILITY_PROBE_REDIS', false),
    ],

    'pagination' => 25,

    // Bounded trend windows for the Overview charts.
    'trend' => [
        'hours' => 24,
        // At most this many latency samples are read to compute the p95
        // trend; above it the trend is labelled as sampled.
        'max_latency_samples' => 20000,
    ],
];
