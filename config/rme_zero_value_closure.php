<?php

/*
|--------------------------------------------------------------------------
| FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217, Sprint 6/7) — "Selesai
| Tanpa Biaya" eligibility, source-controlled.
|--------------------------------------------------------------------------
|
| Owner decisions of 2026-11-01 (register entries S6-D1 and S6-D6). Deliberately
| NO environment read: what counts as a legitimately free service is policy, and
| a policy that a host value can widen is not a policy. Changing either list is a
| reviewed code change that needs a new owner decision.
|
| - consultation_category_codes — the treatment categories that make a line a
|   Konsultasi Dokter (master data `TRT-CONS` is in `consultation`). On any visit
|   that is not a server-derived Kontrol, EVERY line must be in one of these.
| - excluded_treatment_codes — treatments that are never a legitimate Rp0 under
|   this capability, on any visit, Kontrol included (S6-D6: the voucher).
|
| The capability switch itself is the `rme.zero_value_closure` feature flag.
*/

return [
    'consultation_category_codes' => ['consultation'],

    'excluded_treatment_codes' => ['TRT-FREESC'],
];
