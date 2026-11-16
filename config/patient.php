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
    | `PatientRmNumberAllocator` on the clinic calendar. Nothing reads this file
    | any more; a leftover PATIENT_CODE_* environment value is inert. Existing
    | numbers in the old format are kept as they are.
    |
    */
];
