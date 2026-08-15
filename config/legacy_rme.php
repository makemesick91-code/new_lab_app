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
|  - Legacy MIGRATION is disabled by default. The runtime switch is the feature
|    flag `rme.legacy_pdf_archive` (see config/feature_flags.php).
|  - LEGACY-RME-PDF-HISTORY-1A — that flag governs MIGRATION / INGESTION /
|    WRITE ONLY (upload, processing, retry, review, publish, void, branch
|    admission). It is an emergency stop for legacy MUTATIONS; it is NOT a
|    switch that hides already-PUBLISHED clinical evidence. With migration OFF
|    and no branch admitted, an already-published archive REMAINS READABLE to a
|    properly authorized clinical reader, because that evidence is the patient's
|    real medical history and the treating doctor needs it at the next visit.
|  - Published clinical read is therefore governed by the record's own state
|    (PUBLISHED, never staged/failed/cancelled/VOID) plus canonical
|    authorization — read permission, server-resolved branch scope, and for a
|    doctor the DoctorPatientScopeService treating relationship — and by the
|    private disk reachable only through policy-gated stream actions. Read
|    availability is NEVER public availability.
|  - Containing a READ incident uses the mechanisms that exist: revoke
|    `view_legacy_rme_archive` / `view_legacy_rme_imports`, and/or VOID the
|    record (its bytes stop streaming immediately, the row stays auditable).
|    There is no separate read kill switch and one must not be invented as a
|    side effect of the migration flag.
|  - A legacy RME never creates a clinic visit, invoice, payment, consent,
|    odontogram, lab candidate/order, or a SATUSEHAT submission, and never
|    contributes to visit/revenue KPI.
|  - The legacy RME date is chosen MANUALLY by the operator from what is
|    visible on the document. It is never derived from the upload time, the
|    file metadata, or OCR.
|  - A document may represent SEVERAL clinical dates. The representative date
|    is always the EARLIEST one; the LATEST one is the safety bound.
|  - WHEN the patient has a native RME, EVERY date the document represents must
|    be STRICTLY EARLIER than the patient's earliest NATIVE RME date (the first
|    medical record produced by this system). WHEN the patient has none, there
|    is no such bound — that is a valid migration case, and a native encounter
|    is never manufactured to create one.
|  - The archive's branch is DERIVED from the branch code in the patient's
|    Nomor RM and can never be chosen or overridden by the operator.
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

    'sprint' => 'LEGACY-RME-PDF-1C',

    /*
    |--------------------------------------------------------------------------
    | LEGACY-RME-PDF-1C — controlled publish and patient history
    |--------------------------------------------------------------------------
    |
    | DOCUMENTATION OF INVARIANTS, NOT TOGGLES. Every property below is enforced
    | in code (LegacyRmePublishService, LegacyRmePatientHistoryService, the
    | policies and the 1A transition map) and asserted by tests. They are listed
    | here so the publish contract has one readable home; making any of them
    | switchable would turn a clinical guarantee into a foot-gun.
    */
    'publish_invariants' => [
        // Publishing is only ever reachable through REVIEWED, so a human has
        // always looked at the rendered pages first.
        'requires_review' => true,

        // The 1A date rules are re-evaluated against a freshly resolved cutoff
        // at publish time; the upload-time snapshot is evidence, not authority.
        'revalidates_date_on_publish' => true,

        // One locked transaction plus UNIQUE(source_import_id) — a double click
        // or a concurrent operator can never produce a second record.
        'atomic_and_idempotent' => true,

        // Publishing promotes metadata; it moves no bytes. The private paths
        // written by 1B are already final, so a rolled-back publish can leave
        // no orphan file behind.
        'promotes_metadata_only' => true,

        // A published record is immutable: no edit, no hard delete, no
        // republish. The only correction is VOID plus a fresh import.
        'published_record_immutable' => true,

        // Only PUBLISHED records reach the patient's history. Staged, failed,
        // cancelled and VOIDed rows never appear there.
        'history_shows_published_only' => true,

        // A legacy record is an archive, never an encounter: publishing creates
        // no visit, medical record, invoice, payment, consent, odontogram, lab
        // candidate/order or SATUSEHAT candidate.
        'creates_no_downstream_transaction' => true,
    ],

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
        // LEGACY-RME-PDF-1B: a dedicated private disk rooted outside the
        // `local` disk root, so no framework-served route can address a legacy
        // page even with a signed URL. See config/filesystems.php.
        'disk' => env('LEGACY_RME_DISK', 'legacy_rme_private'),
        'path_prefix' => 'rme-legacy',
        'public_disk_forbidden' => true,

        // Disks that may never hold legacy archive material. Asserted at
        // runtime before the first byte is written.
        'forbidden_disks' => ['public', 's3'],
    ],

    /*
    | Date rules.
    |
    | LEGACY-RME-DATE-TZ-1 — `clinical_timezone` USED TO LIVE HERE AND IS GONE
    | ON PURPOSE. It was declared as
    |
    |     env('LEGACY_RME_CLINICAL_TIMEZONE', env('APP_TIMEZONE', 'UTC'))
    |
    | but `config/app.php` hard-codes 'UTC' and never reads APP_TIMEZONE, and
    | neither variable is set in production — so the clinical calendar silently
    | resolved to UTC and every "is this document historical yet?" decision was
    | made eight hours out of frame.
    |
    | The clinical calendar timezone is now declared exactly once, in
    | `config/clinical.php`, and is read exclusively through
    | App\Support\Clinical\ClinicalClock. DO NOT REINTRODUCE A SECOND KEY HERE:
    | two config files that can disagree about what day it is are the defect,
    | not the fix.
    |
    | The switches below are DATE-vs-DATE comparison rules and carry no timezone
    | semantics at all.
    */
    'dates' => [
        // The legacy date must be strictly before the earliest native RME date.
        'require_strictly_before_native' => true,

        // The legacy date must be strictly before today (an archive is historical).
        'require_strictly_before_today' => true,

        // A legacy date equal to the patient's birth date is accepted; earlier is not.
        'allow_same_day_as_birth_date' => true,
    ],

    /*
    | LEGACY-RME-PDF-FIX-ROLL2-1 — the native reference is a BOUND, never a
    | PREREQUISITE. DOCUMENTATION OF INVARIANTS, NOT TOGGLES.
    |
    | The removed `dates.require_native_reference` switch refused any patient
    | with no native RME. That was wrong for the case the archive exists to
    | serve: most patients carried over from the old system have no native RME
    | at all, and requiring one would have forced an operator to manufacture a
    | clinical encounter just to unlock an import. Both properties below are
    | therefore hard-coded — making either switchable would reintroduce exactly
    | the foot-gun this corrective removed.
    |
    | `LegacyRmeDateRuleService` records which of the two reference modes a
    | decision was made under (BEFORE_NATIVE_RME / NO_NATIVE_REFERENCE), so the
    | evidence trail keeps the distinction the old refusal used to destroy.
    */
    'reference_invariants' => [
        'no_native_rme_is_valid' => true,
        'never_manufacture_native_rme' => true,
    ],

    /*
    | LEGACY-RME-PDF-FIX-ROLL2-1 — a document is a date RANGE, not a date.
    | DOCUMENTATION OF INVARIANTS, NOT TOGGLES.
    |
    | One historical PDF often carries several clinical dates. The operator
    | declares the earliest and the latest; the system infers neither, because
    | it does not read dates out of a PDF (no OCR, no metadata parsing) and must
    | never pretend otherwise.
    |
    | The REPRESENTATIVE date is always the EARLIEST one, while the SAFETY bound
    | is checked against the LATEST one — validating only the representative
    | date would let a document whose later entries overlap the native RME era
    | slip through behind its oldest date.
    */
    'document_date_range_invariants' => [
        'representative_date_is_earliest' => true,
        'safety_bound_uses_latest' => true,
        'dates_are_operator_declared_never_ocr' => true,
    ],

    /*
    | LEGACY-RME-PDF-FIX-ROLL2-1 — the archive branch is DERIVED from the
    | patient's Nomor RM. DOCUMENTATION OF INVARIANTS, NOT TOGGLES.
    |
    | `DG-TKM1-2024-9985` → `TKM1` → Cabang Telkomas. `origin_branch_id` decides
    | row visibility (LegacyRmeImportRepository::scoped() filters on it), so it
    | is a security property and never operator input. There is no fallback: not
    | the acting user's branch, not BranchContext, not the first RME-enabled
    | branch, and never a value submitted with the request.
    */
    'branch_resolution_invariants' => [
        'branch_derived_from_medical_record_number' => true,
        'operator_cannot_override_branch' => true,
        'no_fallback_branch' => true,
        'unknown_or_inactive_branch_fails_closed' => true,
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

    /*
    |----------------------------------------------------------------------
    | LEGACY-RME-PDF-1B — processing envelope
    |----------------------------------------------------------------------
    |
    | Rasterization is ALWAYS performed by a queued job, never inside an HTTP
    | request, and always through the argument-array Process API (never an
    | interpolated shell string).
    |
    | The env names keep the 1A `LEGACY_RME_*` family rather than introducing a
    | second `LEGACY_RME_PDF_*` family for the same domain — one prefix, one
    | place to look.
    */
    'processing' => [
        // Poppler binaries. Absolute paths are allowed; the resolved value is
        // never taken from user input.
        'pdfinfo_binary' => env('LEGACY_RME_PDFINFO_BINARY', 'pdfinfo'),
        'pdftoppm_binary' => env('LEGACY_RME_PDFTOPPM_BINARY', 'pdftoppm'),

        // Render resolution for the full page image.
        'dpi' => (int) env('LEGACY_RME_DPI', 180),

        // Longest edge of the generated thumbnail, in pixels. Thumbnails are
        // produced by Poppler itself (`-scale-to`), so no PHP image extension
        // is required on any environment.
        'thumbnail_max_edge' => (int) env('LEGACY_RME_THUMBNAIL_MAX_EDGE', 320),

        // Per-process wall clock limit, in seconds.
        'process_timeout' => (int) env('LEGACY_RME_PROCESS_TIMEOUT', 180),

        // Rendered page guard rails. A page larger than this is refused rather
        // than allowed to exhaust the worker.
        'max_page_pixels' => (int) env('LEGACY_RME_MAX_PAGE_PIXELS', 40000000),
        'max_page_dimension_pt' => (int) env('LEGACY_RME_MAX_PAGE_DIMENSION_PT', 20000),

        // Total bytes the rendered pages of one document may occupy.
        'max_render_bytes' => (int) env('LEGACY_RME_MAX_RENDER_BYTES', 209715200), // 200 MiB

        // Dedicated queue so a long rasterization never blocks default work.
        'queue' => env('LEGACY_RME_QUEUE', 'legacy-rme-documents'),
        'tries' => (int) env('LEGACY_RME_TRIES', 3),
        'backoff' => [30, 120, 300],

        // Optional external malware scan. OFF by default; when the scanner is
        // disabled the pipeline reports "not scanned", never "scan passed".
        'malware_scan' => [
            'enabled' => (bool) env('LEGACY_RME_MALWARE_SCAN', false),
            'binary' => env('LEGACY_RME_MALWARE_SCAN_BINARY', 'clamscan'),
            'timeout' => (int) env('LEGACY_RME_MALWARE_SCAN_TIMEOUT', 120),
        ],
    ],
];
