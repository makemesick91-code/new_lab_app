<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OBS-2 — Centralized Logging & Error Tracking Readiness
    |--------------------------------------------------------------------------
    |
    | Foundation-only: this config never installs or enables an external
    | monitoring vendor, never forces a Laravel logging channel change, and
    | never sends log/error data anywhere. It only feeds the read-only
    | readiness service/command and the governance summary so the app is
    | ready for a future centralized log collector / error tracker without
    | introducing PII/secret leakage now. Central logging and error tracking
    | are OFF by default and remain opt-in.
    |
    */

    'enabled' => (bool) env('OBS_PIPELINE_ENABLED', true),

    'central_logging' => [
        'enabled' => (bool) env('CENTRAL_LOGGING_ENABLED', false),
        'driver' => env('CENTRAL_LOGGING_DRIVER', 'none'),
        'endpoint' => env('CENTRAL_LOGGING_ENDPOINT', ''),
        'api_key' => env('CENTRAL_LOGGING_API_KEY', ''),
        'timeout_ms' => (int) env('CENTRAL_LOGGING_TIMEOUT_MS', 1500),
        'expect_request_id' => (bool) env('CENTRAL_LOGGING_EXPECT_REQUEST_ID', true),

        // Must stay false — centralized logging must never export PII/secrets.
        'send_pii' => (bool) env('CENTRAL_LOGGING_SEND_PII', false),
        'strict' => (bool) env('CENTRAL_LOGGING_STRICT', false),
    ],

    'error_tracking' => [
        'enabled' => (bool) env('ERROR_TRACKING_ENABLED', false),
        'driver' => env('ERROR_TRACKING_DRIVER', 'none'),
        'dsn' => env('ERROR_TRACKING_DSN', ''),
        'environment' => env('ERROR_TRACKING_ENVIRONMENT', 'pilot'),
        'sample_rate' => (float) env('ERROR_TRACKING_SAMPLE_RATE', 0.0),

        // Must stay false — error events must never export PII/secrets.
        'send_pii' => (bool) env('ERROR_TRACKING_SEND_PII', false),
        'expect_request_id' => (bool) env('ERROR_TRACKING_EXPECT_REQUEST_ID', true),
        'strict' => (bool) env('ERROR_TRACKING_STRICT', false),
    ],

    'healthcheck_enabled' => (bool) env('OBS_PIPELINE_HEALTHCHECK_ENABLED', true),
    'log_smoke_enabled' => (bool) env('OBS_PIPELINE_LOG_SMOKE_ENABLED', true),

    // Default false — synthetic error smoke is opt-in only.
    'error_smoke_enabled' => (bool) env('OBS_PIPELINE_ERROR_SMOKE_ENABLED', false),

    // When true (default), a detected PII-send risk always fails readiness,
    // independent of --strict, because sending PII externally is never safe.
    'fail_on_pii_risk' => (bool) env('OBS_PIPELINE_FAIL_ON_PII_RISK', true),

];
