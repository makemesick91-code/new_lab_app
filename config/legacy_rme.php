<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LEGACY-RME-PDF-1A — Legacy RME PDF archive foundation
|--------------------------------------------------------------------------
|
| "Legacy RME" is an ARCHIVE of a patient's OLD medical-record documents that
| originate from historical paper/PDF sources. It is NOT a template and NOT a
| record produced by the live RME examination workflow.
|
| SAFETY INVARIANTS (enforced in code + tests, mirrored in the sprint rules):
|  - Disabled by default. The runtime switch is the feature flag
|    `rme.legacy_pdf_archive` (see config/feature_flags.php).
|  - A legacy RME never creates a clinic visit, invoice, payment, consent,
|    odontogram, lab candidate/order, or a SATUSEHAT submission, and never
|    contributes to visit/revenue KPI.
|  - The legacy RME date is chosen MANUALLY by the operator from what is
|    visible on the document. It is never derived from the upload time, the
|    file metadata, or OCR.
|  - The legacy RME date must be STRICTLY EARLIER than the patient's earliest
|    NATIVE RME date (the first medical record produced by this system).
|  - A published legacy record is immutable; corrections go through VOID plus
|    a fresh import.
|  - Full KTP/NIK is never rendered, exported, or written into an audit payload.
|
| SPRINT 1A SCOPE: schema, permissions, policies, repositories, the date-rule
| domain and its audit/config foundation ONLY. PDF conversion, upload runtime,
| review UI, publish runtime, canvas viewer, and patient-timeline integration
| are deliberately NOT implemented here.
|
*/

return [

    'sprint' => 'LEGACY-RME-PDF-1A',

    /*
    | Convenience mirror of the feature flag. Services MUST resolve the flag
    | through App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard (which reads
    | FeatureFlagService) — this key only carries the flag identifier so the
    | string is never duplicated across the module.
    */
    'feature_flag' => 'rme.legacy_pdf_archive',

    /*
    | Private storage target for future sprints. Nothing is written in 1A.
    | The disk must stay private — a legacy RME page is clinical evidence and
    | is never exposed through the public disk or a public URL.
    */
    'storage' => [
        'disk' => env('LEGACY_RME_DISK', 'local'),
        'path_prefix' => 'rme-legacy',
        'public_disk_forbidden' => true,
    ],

    /*
    | Date rules. `clinical_timezone` anchors "today" to the same wall clock the
    | rest of the RME workflow uses when it stamps trx_clinic_visits.visit_date
    | (ClinicVisitService writes Carbon::today() in the application timezone), so
    | the legacy date and the native cutoff are always compared in one frame.
    | Operators can realign it via env without a code change; changing it shifts
    | the "today" boundary by at most one calendar day.
    */
    'dates' => [
        'clinical_timezone' => env('LEGACY_RME_CLINICAL_TIMEZONE', env('APP_TIMEZONE', 'UTC')),

        // The legacy date must be strictly before the earliest native RME date.
        'require_strictly_before_native' => true,

        // The legacy date must be strictly before today (an archive is historical).
        'require_strictly_before_today' => true,

        // A legacy date equal to the patient's birth date is accepted; earlier is not.
        'allow_same_day_as_birth_date' => true,

        // Regular import mode refuses a patient that has no native RME to compare
        // against. A dedicated migration mode is out of scope for this sprint.
        'require_native_reference' => true,
    ],

    /*
    | Cutoff resolution — DOCUMENTATION OF INVARIANTS, NOT TOGGLES.
    |
    | These four properties are hard-coded in
    | PatientEarliestNativeRmeDateResolver / ClinicVisitRepository and are
    | deliberately NOT read from config: each one is a safety invariant, and
    | making it switchable would turn a clinical guarantee into a foot-gun.
    | In particular the cutoff scans EVERY branch, because a narrower scope can
    | only move the cutoff LATER and let a legacy document overlap a real
    | native record.
    */
    'cutoff_invariants' => [
        'scan_all_branches' => true,
        'exclude_cancelled_visits' => true,
        'require_medical_record' => true,
        'exclude_legacy_records' => true,
    ],

    /*
    | Upload envelope for the follow-up sprint. Declared here so the limits are
    | governed from day one; no upload endpoint exists in 1A.
    */
    'upload' => [
        'max_bytes' => (int) env('LEGACY_RME_MAX_BYTES', 20971520), // 20 MiB
        'allowed_mimes' => ['application/pdf'],
        'pdf_magic' => '%PDF-',
        'max_pages' => (int) env('LEGACY_RME_MAX_PAGES', 200),
    ],
];
