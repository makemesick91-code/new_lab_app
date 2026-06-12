<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Patient Code (Medical Record Number) Generation
    |--------------------------------------------------------------------------
    |
    | Sprint 23 Phase 23.3 — TEMPORARY pilot format, PENDING final business
    | approval from the clinic owner. The generated value is stored in
    | mst_patients.medical_record_number when a new patient is created
    | without an explicit code.
    |
    | Default format: {prefix}-{YYYYMM}-{sequence padded to `seq_length`}
    | Example:        RM-202606-000001
    |
    | Do NOT treat this as the locked business format. It is collision-safe
    | and configurable so the owner can confirm a final scheme later without
    | a code change (only config / env values).
    |
    */

    'code' => [
        // Whether to auto-generate a code when none is supplied on create.
        'auto_generate' => env('PATIENT_CODE_AUTO_GENERATE', true),

        // Static prefix segment.
        'prefix' => env('PATIENT_CODE_PREFIX', 'RM'),

        // Period segment date format (PHP date format). Empty string disables it.
        'period_format' => env('PATIENT_CODE_PERIOD_FORMAT', 'Ym'),

        // Zero-padding width of the running sequence segment.
        'seq_length' => (int) env('PATIENT_CODE_SEQ_LENGTH', 6),

        // Segment separator.
        'separator' => env('PATIENT_CODE_SEPARATOR', '-'),
    ],
];
