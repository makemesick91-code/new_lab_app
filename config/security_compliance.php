<?php

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Patient\Services\PatientDataCompletenessService;
use App\Support\DeveloperConsole\SensitiveValueMasker;

return [

    /*
    |--------------------------------------------------------------------------
    | ENT-9 — Security & PII Compliance Hardening
    |--------------------------------------------------------------------------
    |
    | Read-only compliance contract for the enterprise security/PII hardening
    | pack. It declares the PII fields that must never render/export in full,
    | the masking helpers that must remain present, the view-scan patterns used
    | to detect an unmasked KTP/NIK display echo, the export-gating expectation,
    | and the audit + branch-isolation infrastructure that must stay in place.
    |
    | Nothing here mutates state. The governance service and the
    | `foundation:security-compliance-check` command consume this registry to
    | verify posture and never trust a request-supplied branch id.
    |
    */

    // Master switch for the ENT-9 security/PII compliance governance surface.
    'enabled' => (bool) env('SECURITY_COMPLIANCE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors LB-1 / health-check configs).
    'strict' => (bool) env('SECURITY_COMPLIANCE_STRICT', false),

    // Sensitive identifiers that must never be rendered/exported/logged in full
    // (server-side only). Used as documentation + scan anchors.
    'pii_fields' => ['ktp_number', 'nik_number'],

    // Masking helpers that must remain present. Removing/renaming any of these
    // (or disabling the developer-console masker) fails the governance check.
    'masking' => [
        'require_developer_console_masking' => true,
        'helpers' => [
            [
                'class' => PatientDataCompletenessService::class,
                'method' => 'maskKtp',
            ],
            [
                'class' => SensitiveValueMasker::class,
                'method' => 'mask',
            ],
        ],
    ],

    // Read-only Blade scan: detect a raw KTP/NIK *display* echo that is not
    // masked. Form inputs / value bindings (matched by an exclusion pattern)
    // are not display leaks and are intentionally ignored so the default repo
    // state stays GO. All regex literals live here — never in app code.
    'view_scan' => [
        'enabled' => true,
        'paths' => ['resources/views'],
        // A `{{ ... }}` echo of a raw PII accessor.
        'forbidden_echo_patterns' => [
            '/\{\{[^}]*(?:ktp_number|nik_number|->ktp\b|->nik\b)[^}]*\}\}/i',
        ],
        // Lines matching any of these are form inputs / value bindings / masked
        // helpers, not display leaks.
        'exclusion_patterns' => [
            '/old\s*\(/i',
            '/value\s*=\s*"\{\{/i',
            '/<input\b/i',
            '/<textarea\b/i',
            '/mask/i',
        ],
    ],

    // Every data-export route must carry an auth/permission gate in its fully
    // resolved middleware stack (group middleware included). The sidebar is
    // never a security boundary — enforcement is server-side.
    'export_gating' => [
        'enabled' => true,
        'export_name_fragments' => ['export'],
        'required_middleware_fragments' => ['permission:', 'can:', 'auth'],
    ],

    // Immutable audit trail + branch-isolation primitives that must remain.
    'audit' => [
        'table' => 'sys_audit_logs',
    ],
    'branch_isolation' => [
        'context_class' => BranchContext::class,
        // BranchContext::requireId() is the trusted source; a request-supplied
        // branch id must never be trusted for scoping.
        'never_trust_request_branch_id' => true,
    ],

];
