<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OBS-1 — Request ID, Correlation ID & Observability Foundation
    |--------------------------------------------------------------------------
    |
    | Foundation-only: this config never forces a logging channel change, and
    | never sends data to an external vendor. It only feeds the request
    | correlation middleware, the read-only readiness command, and the
    | governance summary so the app is ready for future centralized
    | logging/APM/tracing without introducing PII/secret leakage now.
    |
    */

    'enabled' => (bool) env('OBSERVABILITY_ENABLED', true),

    'request_id' => [
        'enabled' => (bool) env('OBSERVABILITY_REQUEST_ID_ENABLED', true),
        'inbound_header' => env('OBSERVABILITY_REQUEST_ID_HEADER', 'X-Request-ID'),
        'response_header' => env('OBSERVABILITY_RESPONSE_HEADER', 'X-Request-ID'),

        // Client-supplied IDs are never trusted by default — a spoofed value
        // could otherwise be used to pollute/confuse correlated log search.
        'trust_inbound' => (bool) env('OBSERVABILITY_TRUST_INBOUND_REQUEST_ID', false),
    ],

    'correlation_id' => [
        'inbound_header' => env('OBSERVABILITY_CORRELATION_ID_HEADER', 'X-Correlation-ID'),
        'trust_inbound' => (bool) env('OBSERVABILITY_TRUST_INBOUND_CORRELATION_ID', false),
    ],

    // Shared bound applied to both request id and correlation id (inbound or
    // generated) before it is ever attached to a request/log/response.
    'max_id_length' => (int) env('OBSERVABILITY_MAX_ID_LENGTH', 80),

    'log_context' => [
        'enabled' => (bool) env('OBSERVABILITY_LOG_CONTEXT_ENABLED', true),
        'include_route_name' => (bool) env('OBSERVABILITY_LOG_ROUTE_NAME', true),
        'include_method' => (bool) env('OBSERVABILITY_LOG_METHOD', true),
        'include_path' => (bool) env('OBSERVABILITY_LOG_PATH', true),

        // Default false — user/branch identifiers add exposure and must not
        // be logged without an explicit review of the destination log sink.
        'include_user_id' => (bool) env('OBSERVABILITY_LOG_USER_ID', false),
        'include_branch_id' => (bool) env('OBSERVABILITY_LOG_BRANCH_ID', false),
    ],

    // Reserved for future redaction helpers; log context here never includes
    // full request payload, query string, cookies, session id, or PII.
    'mask_pii' => (bool) env('OBSERVABILITY_MASK_PII', true),

    'healthcheck_enabled' => (bool) env('OBSERVABILITY_HEALTHCHECK_ENABLED', true),

    // When true, warning-level findings are treated as failures by default.
    'strict' => (bool) env('OBSERVABILITY_STRICT', false),

];
