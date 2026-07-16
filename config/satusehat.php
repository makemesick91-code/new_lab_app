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
    'timeout_seconds' => (int) env('SATUSEHAT_TIMEOUT_SECONDS', 15),
    'connect_timeout_seconds' => (int) env('SATUSEHAT_CONNECT_TIMEOUT_SECONDS', 5),
    'max_attempts' => (int) env('SATUSEHAT_MAX_ATTEMPTS', 5),

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
