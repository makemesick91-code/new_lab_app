<?php

/**
 * SATUSEHAT-1 — Readiness Foundation & Controlled Submission Filter.
 *
 * Runtime source of truth for the SATUSEHAT integration. In SATUSEHAT-1 the
 * integration is a READINESS foundation only:
 *   - The runtime gateway binding defaults to DisabledSatusehatGateway.
 *   - No OAuth token request, no IHS lookup, no FHIR POST/PUT/PATCH/DELETE.
 *   - Nothing here opens a network connection while `enabled` is false.
 *
 * The real external HTTP adapter is introduced in SATUSEHAT-2 after sandbox
 * credentials + official FHIR profile validation. Until then `enabled` stays
 * false and the app must boot normally with every URL/credential blank.
 *
 * Governance registry markers live in config/feature_flags.php:
 *   - satusehat.integration_readiness (this foundation)
 *   - satusehat.external_submission_enabled (SATUSEHAT-2 cutover; stays off)
 */

use App\Modules\Satusehat\Gateways\DisabledSatusehatGateway;

return [

    // Master runtime switch. MUST stay false in SATUSEHAT-1 (and on the VPS
    // pilot). When false, the container binds DisabledSatusehatGateway and no
    // external call is ever attempted. Fail-closed: an incomplete/misconfigured
    // enabled setup still resolves to the disabled gateway (see the provider).
    'enabled' => (bool) env('SATUSEHAT_ENABLED', false),

    // Selected environment. Sandbox and production are ALWAYS kept separate:
    // every candidate/identifier/mapping/batch row is stamped with its
    // environment and queries never mix them.
    'environment' => env('SATUSEHAT_ENV', 'sandbox'),
    'allowed_environments' => ['sandbox', 'production'],

    // --- Future adapter connection config (blank + inert in SATUSEHAT-1) ---
    // These are read ONLY by the future HttpSatusehatGateway (SATUSEHAT-2).
    // They are intentionally blank here; never fill fake credentials on the VPS.
    'oauth_base_url' => env('SATUSEHAT_OAUTH_BASE_URL', ''),
    'fhir_base_url' => env('SATUSEHAT_FHIR_BASE_URL', ''),
    'client_id' => env('SATUSEHAT_CLIENT_ID', ''),
    'client_secret' => env('SATUSEHAT_CLIENT_SECRET', ''),
    'organization_id' => env('SATUSEHAT_ORGANIZATION_ID', ''),
    // Location the encounters happen at (a sandbox Location resource id). Kept
    // separate from organization_id; both are provisioned from the environment.
    'location_id' => env('SATUSEHAT_LOCATION_ID', ''),
    'timeout_seconds' => (int) env('SATUSEHAT_TIMEOUT_SECONDS', 15),
    'connect_timeout_seconds' => (int) env('SATUSEHAT_CONNECT_TIMEOUT_SECONDS', 5),
    'max_attempts' => (int) env('SATUSEHAT_MAX_ATTEMPTS', 5),

    // ---------------------------------------------------------------------
    // SATUSEHAT-2 — Sandbox API adapter runtime governance.
    //
    // Two independent switches gate any outbound request. BOTH must be true,
    // AND the environment must be sandbox, AND the resolved host must be on the
    // sandbox allowlist. Production is fail-closed in SATUSEHAT-2 regardless of
    // env — the adapter refuses to send to a production host.
    // ---------------------------------------------------------------------

    // Emergency kill switch. Separate from `enabled` so the whole outbound path
    // can be stopped WITHOUT a code deploy (flip the env + config:cache rebuild).
    // Every job re-reads this before each outbound request.
    'send_enabled' => (bool) env('SATUSEHAT_SEND_ENABLED', false),

    // Hard sandbox lock. When true (default) the adapter is only ever allowed to
    // talk to the sandbox environment; a production env/host is rejected. This
    // stays true for the whole SATUSEHAT-2 sprint — production cutover is a
    // separate, explicitly-approved sprint.
    'sandbox_only' => (bool) env('SATUSEHAT_SANDBOX_ONLY', true),

    // Per-environment host allowlist. The gateway resolves the configured base
    // URL host and asserts it is on this list for the ACTIVE environment before
    // any request. An arbitrary/unlisted host (SSRF) is refused. Production
    // hosts are declared for future config only and never reachable while
    // `sandbox_only` is true / environment is not production.
    'allowed_hosts' => [
        'sandbox' => [
            'api-satusehat-stg.dto.kemkes.go.id',
        ],
        'production' => [
            'api-satusehat.kemkes.go.id',
        ],
    ],

    // OAuth token cache/refresh. The token is cached (never persisted to the DB)
    // keyed by environment + a non-reversible credential fingerprint, and
    // refreshed `token_refresh_buffer_seconds` before its real expiry.
    'token_refresh_buffer_seconds' => (int) env('SATUSEHAT_TOKEN_REFRESH_BUFFER_SECONDS', 60),
    'token_lock_seconds' => (int) env('SATUSEHAT_TOKEN_LOCK_SECONDS', 10),

    // Bounded retry with backoff + jitter. `retry_after_cap_seconds` caps how
    // long a 429 Retry-After can defer a job.
    'retry' => [
        'base_delay_seconds' => (int) env('SATUSEHAT_RETRY_BASE_DELAY_SECONDS', 5),
        'max_delay_seconds' => (int) env('SATUSEHAT_RETRY_MAX_DELAY_SECONDS', 300),
        'retry_after_cap_seconds' => (int) env('SATUSEHAT_RETRY_AFTER_CAP_SECONDS', 600),
    ],

    // Circuit breaker (cache-backed). After `threshold` consecutive hard
    // failures the breaker opens for `cooldown_seconds`; a single half-open
    // probe closes it on success.
    'circuit_breaker' => [
        'threshold' => (int) env('SATUSEHAT_CIRCUIT_BREAKER_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('SATUSEHAT_CIRCUIT_BREAKER_COOLDOWN_SECONDS', 300),
    ],

    // Response body guardrail — a response larger than this is rejected before
    // JSON decoding (defence against oversized/hostile responses).
    'max_response_bytes' => (int) env('SATUSEHAT_MAX_RESPONSE_BYTES', 1048576),

    // Queue the outbound jobs run on (governance: a dedicated queue name keeps
    // SATUSEHAT work isolated from clinical/notification queues).
    'queue' => env('SATUSEHAT_QUEUE', 'satusehat'),

    // ---------------------------------------------------------------------
    // SATUSEHAT-3 — Production activation guard (permanent).
    //
    // Production submission is a SEPARATE, explicitly-gated future sprint. The
    // production adapter can only ever activate when EVERY guard condition in
    // SatusehatProductionActivationGuard passes; in SATUSEHAT-3 they all fail
    // by design and tests assert production cannot activate. None of these may
    // be set on the VPS pilot.
    // ---------------------------------------------------------------------
    'production_enabled' => (bool) env('SATUSEHAT_PRODUCTION_ENABLED', false),
    'production_approved' => (bool) env('SATUSEHAT_PRODUCTION_APPROVED', false),
    'production_approval_reference' => env('SATUSEHAT_PRODUCTION_APPROVAL_REFERENCE', ''),
    // Set to true ONLY after a real, evidenced sandbox round-trip (the
    // SATUSEHAT-2 GO gate). Mock/hermetic tests never count as sandbox proof.
    'sandbox_verified' => (bool) env('SATUSEHAT_SANDBOX_VERIFIED', false),

    // Default runtime gateway. Overridden to a real adapter ONLY in SATUSEHAT-2,
    // and only when both `enabled` is true and the config validates.
    'default_gateway' => DisabledSatusehatGateway::class,

    // --- Candidate eligibility (readiness input, not a send trigger) ---
    'candidate' => [
        // A visit is eligible for a readiness candidate only when it is
        // completed AND its medical record is final. Cancelled visits are
        // never eligible.
        'eligible_visit_statuses' => ['completed'],
        'require_final_medical_record' => true,
        // Post-commit auto-generation on visit completion / MR finalization.
        // Idempotent + guarded + no external effect. Can be disabled without
        // affecting any clinical/billing behavior.
        'auto_generate' => (bool) env('SATUSEHAT_CANDIDATE_AUTO_GENERATE', true),
        // Bounded backfill guardrails — never full-table unbounded scan.
        'backfill_default_batch_size' => 200,
        'backfill_max_batch_size' => 1000,
    ],

    // --- Local timezone for UTC normalization (clinic operates in WITA) ---
    'clinic_timezone' => env('SATUSEHAT_CLINIC_TIMEZONE', 'Asia/Makassar'),

    // --- Privacy / masking ---
    // NIK/KTP is never rendered in full anywhere in the SATUSEHAT surface.
    // Sensitive eligibility/preview snapshots are stored with encrypted casts.
    'mask_nik' => true,
];
