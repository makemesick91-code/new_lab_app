<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1
|--------------------------------------------------------------------------
|
| Mass upload is an ORCHESTRATION layer over the canonical single-item legacy
| import services. It owns NO clinical rule of its own: every date bound,
| branch decision, patient binding, capacity gate, single-active-document slot
| and publish/VOID authority continues to live in the single-item domain.
|
| Read that literally. If a value in this file looks like it decides clinical
| eligibility, it is a technical bound (bytes, entries, chunk sizes) and not a
| clinical one. The clinical knobs stay in config/legacy_rme.php and
| config/legacy_odontogram.php, and this file deliberately does not restate
| them — a second copy is a second source of truth, and the one that drifts is
| always the copy.
|
| There is NO daily business quota here, by decision. The historical 100/day
| migration cap was a business cap and is not reintroduced; the bounds below
| are purely technical backpressure so a large archive cannot exhaust the
| render workers or the disk.
*/

return [

    /*
    |----------------------------------------------------------------------
    | Package (ZIP) intake bounds — TECHNICAL ONLY
    |----------------------------------------------------------------------
    |
    | These are fail-closed ceilings evaluated BEFORE anything is extracted.
    | An archive that violates any of them is a PACKAGE-INTEGRITY failure and
    | the batch never begins item dispatch (§17), because a malformed or
    | hostile archive tells us nothing trustworthy about its items.
    */
    'package' => [
        // The uploaded ZIP itself.
        'max_bytes' => (int) env('LEGACY_MASS_UPLOAD_PACKAGE_MAX_BYTES', 524288000), // 500 MiB

        'allowed_mimes' => [
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream', // some browsers send this for .zip
        ],

        'allowed_extensions' => ['zip'],

        // Archive-bomb ceilings. total_uncompressed_max_bytes is checked by
        // summing the central-directory sizes BEFORE extraction, and again
        // cumulatively DURING extraction, because a crafted central directory
        // can understate the real payload.
        'max_entries' => (int) env('LEGACY_MASS_UPLOAD_MAX_ENTRIES', 1200),
        'total_uncompressed_max_bytes' => (int) env('LEGACY_MASS_UPLOAD_TOTAL_MAX_BYTES', 2147483648), // 2 GiB
        'max_compression_ratio' => (float) env('LEGACY_MASS_UPLOAD_MAX_RATIO', 120.0),

        // Per-clinical-document technical ceiling. This intentionally mirrors
        // the single-item upload ceiling; if they ever disagree, the canonical
        // single-item service still refuses at createFromUpload() and the item
        // becomes BLOCKED rather than silently oversized.
        'document_max_bytes' => (int) env('LEGACY_MASS_UPLOAD_DOCUMENT_MAX_BYTES', 20971520), // 20 MiB

        'document_allowed_mimes' => ['application/pdf'],
        'document_allowed_extensions' => ['pdf'],

        // The manifest entry must exist at the archive root under this exact
        // name. A fixed name removes "which CSV did you mean" ambiguity.
        'manifest_entry_name' => 'manifest.csv',
        'manifest_max_bytes' => (int) env('LEGACY_MASS_UPLOAD_MANIFEST_MAX_BYTES', 5242880), // 5 MiB
    ],

    /*
    |----------------------------------------------------------------------
    | Manifest contract
    |----------------------------------------------------------------------
    |
    | Headers are asserted exactly (order-insensitive, case-insensitive after
    | trimming and BOM stripping). An unexpected or missing header is a
    | PACKAGE failure, not a per-item failure, because we cannot know which
    | column meant what.
    |
    | NIK/KTP is deliberately absent and must never be added. The patient link
    | is the medical record number, resolved server-side.
    */
    'manifest' => [
        'legacy_rme' => [
            'required_headers' => [
                'medical_record_number',
                'file_name',
                'rme_date_earliest',
            ],
            'optional_headers' => [
                'rme_date_latest',
            ],
        ],

        'legacy_odontogram' => [
            'required_headers' => [
                'medical_record_number',
                'file_name',
                'document_date',
            ],
            'optional_headers' => [],
        ],

        // Columns that must NEVER appear. Accepting them would invite an
        // operator to put identity numbers in a spreadsheet that then lands in
        // a staging table and a downloadable report.
        'forbidden_headers' => [
            'nik',
            'ktp',
            'ktp_number',
            'identity_number',
            'no_ktp',
            'nomor_ktp',
            // branch_id is forbidden on purpose: branch is resolved
            // server-side from the patient and BranchContext. A manifest that
            // could name a branch is a branch-spoof surface.
            'branch_id',
            'origin_branch_id',
            'patient_id',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Bounded dispatch — TECHNICAL BACKPRESSURE, NOT A BUSINESS QUOTA
    |----------------------------------------------------------------------
    |
    | Confirming a 900-item batch must not put 900 multi-minute rasterization
    | jobs on the queue in one request. Items are created and dispatched in
    | chunks; each chunk is its own unit of work so a crash resumes rather than
    | restarts, and already-created items are never created twice (the item row
    | carries the created import id and is skipped on resume).
    */
    'dispatch' => [
        'chunk_size' => (int) env('LEGACY_MASS_UPLOAD_CHUNK_SIZE', 25),

        // Maximum items created per HTTP confirm request. Anything beyond this
        // remains CONFIRMED-but-undispatched and is picked up by the resume
        // path, so a very large archive degrades into several passes instead of
        // one request that times out half-way.
        'max_items_per_request' => (int) env('LEGACY_MASS_UPLOAD_MAX_ITEMS_PER_REQUEST', 100),

        // Queue-depth backpressure. When the target render queue already holds
        // at least this many jobs, dispatch pauses and the batch stays
        // DISPATCHING; the operator (or the resume path) continues later. This
        // reads the queue the CANONICAL job already uses — mass upload does not
        // introduce a queue of its own.
        'queue_depth_pause_threshold' => (int) env('LEGACY_MASS_UPLOAD_QUEUE_PAUSE', 400),
    ],

    /*
    |----------------------------------------------------------------------
    | Extraction workspace
    |----------------------------------------------------------------------
    |
    | Extraction happens inside a per-batch directory on a PRIVATE disk. The
    | uploaded ZIP and the extracted clinical documents are never written to a
    | public disk or the web root.
    |
    | Cleanup is deterministic: the workspace is removed on success, on
    | validation failure, on operator cancel and on exception. What is NOT
    | removed is anything the canonical single-item service has already taken
    | ownership of — once createFromUpload() has stored a document under its own
    | import uuid, that copy is canonical source evidence and belongs to the
    | single-item retention rules, not to us.
    */
    'workspace' => [
        'disk' => env('LEGACY_MASS_UPLOAD_DISK', 'legacy_mass_upload_private'),
        'path_prefix' => 'legacy-mass-upload',
        'public_disk_forbidden' => true,
        'forbidden_disks' => ['public', 's3'],

        // A workspace older than this with no live batch is orphaned and may be
        // swept. Retention of CANONICAL source documents is unaffected.
        'orphan_sweep_after_hours' => (int) env('LEGACY_MASS_UPLOAD_ORPHAN_HOURS', 48),
    ],

    /*
    |----------------------------------------------------------------------
    | Preflight report export
    |----------------------------------------------------------------------
    |
    | The downloadable report carries reason codes and safe messages only. It
    | must not carry patient names, identity numbers or clinical content — a
    | migration report tends to be emailed around, and the row that leaks is
    | always the one nobody meant to include.
    */
    'report' => [
        'filename_prefix' => 'legacy-mass-upload-preflight',
        'max_rows' => (int) env('LEGACY_MASS_UPLOAD_REPORT_MAX_ROWS', 5000),
    ],
];
