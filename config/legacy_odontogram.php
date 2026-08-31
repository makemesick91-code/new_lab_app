<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FIX-04b — Legacy Odontogram archive
|--------------------------------------------------------------------------
|
| "Legacy Odontogram" is an ARCHIVE of a patient's HISTORICAL paper odontogram
| chart, scanned to PDF. It is NOT a template, NOT a native odontogram, and NOT
| produced by the live examination workflow.
|
| SAFETY INVARIANTS (enforced in code + tests):
|  - MIGRATION (upload / process / review / publish / void) is disabled by
|    default. The runtime switch is the feature flag
|    `rme.legacy_odontogram_archive` (see config/feature_flags.php).
|  - That flag governs MIGRATION ONLY. Reading an ALREADY-PUBLISHED archive is
|    deliberately NOT flag-gated: published evidence is the patient's real
|    clinical history, and a rollback of the migration capability must never
|    remove a patient's history from the doctor treating them. Read is governed
|    by the record being PUBLISHED plus canonical authorization (read
|    permission, server-resolved branch scope, and for a doctor the treating
|    relationship), and by a private disk reachable only through policy-gated
|    streaming actions.
|  - The archive NEVER creates a ClinicVisit, Odontogram, MedicalRecord,
|    invoice, payment, treatment, prescription, LabOrder or SATUSEHAT record.
|  - A published record is immutable. Correction = VOID (with a reason) plus a
|    fresh import. There is no update and no hard delete.
|  - The owning branch is DERIVED from the branch-code segment of the patient's
|    Nomor RM. Unknown / invalid / ambiguous / inactive / non-RME fails closed.
|  - This capability owns its OWN tables and its OWN flag. It never reads or
|    writes Legacy RME staging rows, records, waves, quotas or admission state.
|
| PDF-ONLY, ON PURPOSE (v1). The intake accepts application/pdf and nothing
| else, verified from the file's real bytes rather than the client-declared
| MIME or the extension. The whole rendering pipeline is Poppler (pdfinfo +
| pdftoppm), which is already a deployed dependency; admitting JPEG/PNG/TIFF
| would need a second, unvalidated ingestion path (orientation, colour profile,
| multi-page TIFF) for no clinical gain, since a scanner can already emit PDF.
*/

return [
    'sprint' => 'FIX-04b',

    'feature_flag' => 'rme.legacy_odontogram_archive',

    'archive_invariants' => [
        'creates_no_clinic_visit' => true,
        'creates_no_native_odontogram' => true,
        'creates_no_medical_record' => true,
        'creates_no_billing_or_payment' => true,
        'creates_no_lab_or_satusehat_record' => true,
        'published_record_immutable' => true,
        'correction_is_void_plus_fresh_import' => true,
        'never_touches_legacy_rme_state' => true,
    ],

    'storage' => [
        'disk' => env('LEGACY_ODONTOGRAM_DISK', 'legacy_odontogram_private'),
        'path_prefix' => 'odontogram-legacy',
        'public_disk_forbidden' => true,
        'forbidden_disks' => ['public', 's3'],
    ],

    'dates' => [
        // Every date rule is evaluated against the CLINICAL calendar
        // (App\Support\Clinical\ClinicalClock, Asia/Makassar), never raw UTC.
        'require_strictly_before_native' => true,
        'require_strictly_before_today' => true,
        'allow_same_day_as_birth_date' => true,

        /*
         * REVISION-LEGACY-ODONTOGRAM-NATIVE-OPTIONAL-1 removed the former
         * `require_native_odontogram_reference` switch. A patient with no native
         * odontogram is a VALID state to archive against — a paper chart is
         * historical evidence and does not need this system to have examined the
         * patient first — so there is no longer a setting that could refuse one.
         *
         * `require_strictly_before_native` above is a DIFFERENT rule and is
         * deliberately still on: when a meaningful native odontogram does exist
         * it still bounds the archive, at the EARLIEST one, strictly.
         * Native OPTIONAL is not native cutoff REMOVED.
         */
    ],

    'branch_resolution_invariants' => [
        'branch_derived_from_medical_record_number' => true,
        'operator_cannot_override_branch' => true,
        'no_fallback_branch' => true,
        'unknown_or_inactive_branch_fails_closed' => true,
    ],

    'upload' => [
        'max_bytes' => (int) env('LEGACY_ODONTOGRAM_MAX_BYTES', 20971520), // 20 MiB
        'allowed_mimes' => ['application/pdf'],
        'pdf_magic' => '%PDF-',
        'max_pages' => (int) env('LEGACY_ODONTOGRAM_MAX_PAGES', 50),
    ],

    'processing' => [
        'dpi' => (int) env('LEGACY_ODONTOGRAM_DPI', 180),
        'queue' => env('LEGACY_ODONTOGRAM_QUEUE', 'legacy-odontogram-documents'),
        'tries' => (int) env('LEGACY_ODONTOGRAM_TRIES', 3),
        'backoff' => [30, 120, 300],
        'process_timeout' => (int) env('LEGACY_ODONTOGRAM_PROCESS_TIMEOUT', 180),
        'max_render_bytes' => (int) env('LEGACY_ODONTOGRAM_MAX_RENDER_BYTES', 209715200), // 200 MiB
    ],

    'void' => [
        'min_reason_length' => 10,
        'max_reason_length' => 500,
    ],
];
