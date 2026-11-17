<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Patient Code (Medical Record Number) Generation — RETIRED
    |--------------------------------------------------------------------------
    |
    | Sprint 23 Phase 23.3 shipped a TEMPORARY pilot generator here
    | (`patient.code.*`, format {prefix}-{YYYYMM}-{sequence}) that ran when a
    | patient was created without a branch and number, on the UTC clock.
    |
    | DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2, PR-2) retired it
    | together with `PatientCodeGenerator`: every new patient now needs a branch
    | and receives its automatic Nomor RM DG-{KODE}-{TAHUN}-{NNNN} from
    | `PatientRmNumberAllocator` on the clinic calendar. Nothing reads the old
    | block any more; a leftover PATIENT_CODE_* environment value is inert.
    | Existing numbers in the old format are kept as they are.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Paper-card reason codes (rule 244 §4.3, PR-3b)
    |--------------------------------------------------------------------------
    |
    | Owner decision 2026-11-17, "PAPER-CARD REASON: store text, restricted":
    | registering a patient under an old paper-card Nomor RM takes one of these
    | structured reasons (code => Indonesian label, in display order) plus an
    | optional note of 10–500 characters, REQUIRED for LAINNYA. The code is what
    | the audit row keeps; the note is stored only in
    | trx_patient_rm_paper_card_registrations and read only by
    | `view_patient_rm_paper_card_reasons`. LAINNYA must stay in the list. A code
    | is never renamed or removed once used (stored rows keep it); add new ones.
    |
    */
    'paper_card_reasons' => [
        'KARTU_LAMA_PASIEN_LAMA' => 'Pasien lama membawa kartu berobat kertas',
        'MIGRASI_ARSIP_KERTAS' => 'Migrasi arsip rekam medis kertas',
        'LAINNYA' => 'Lainnya (wajib catatan)',
    ],
];
