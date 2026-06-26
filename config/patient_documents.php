<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sprint 61.3 — Patient Scan Document Storage Governance
    |--------------------------------------------------------------------------
    |
    | Governance settings for scanned patient identity documents (KTP scans)
    | introduced in Sprint 61.1 / 61.1.1. These drive the audit + safe cleanup
    | Artisan commands. The values intentionally mirror the storage layout used
    | by App\Modules\Patient\Services\KtpScanService — keep them in sync if the
    | service ever changes its disk or directory constants.
    |
    | Privacy: the disk is private (storage/app/private). No public URL is ever
    | generated and no full KTP number is exposed by any governance tooling.
    |
    */

    // Private filesystem disk holding both final documents and temp scans.
    'disk' => env('PATIENT_DOCUMENT_DISK', 'local'),

    // Final, attached patient documents live under this root (per patient id).
    'private_root' => 'patient-documents',

    // Temporary, pre-attach scan uploads live under this root (per user id).
    'temp_root' => 'tmp/patient-ktp-scans',

    // Temp scans older than this are considered stale and prunable.
    'temp_ttl_hours' => (int) env('PATIENT_DOCUMENT_TEMP_TTL_HOURS', 24),

    // Orphan grace period (reserved for the deferred prune-orphans command).
    'orphan_grace_days' => (int) env('PATIENT_DOCUMENT_ORPHAN_GRACE_DAYS', 7),

    // A single scan file above this size is reported as unusually large.
    'max_document_bytes' => (int) env('PATIENT_DOCUMENT_MAX_BYTES', 6291456),

    // Document types this governance tooling understands.
    'allowed_document_types' => ['ktp'],

    // Mime types considered valid for a scanned document.
    'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],

];
