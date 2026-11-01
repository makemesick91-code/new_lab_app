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
| - control_service_category_codes — HARDENING (owner S6-Q3a, 2026-11-01): the
|   category RESERVED for the Kontrol service itself. A line in it is free ONLY on a
|   VALID Kontrol (server-derived, parent examined). No master-data treatment uses
|   it yet — production records the Kontrol service as Konsultasi Dokter — so this
|   admits nothing until an administrator creates a "Kontrol" treatment in it.
| - excluded_treatment_codes — treatments that are never a legitimate Rp0 under
|   this capability, on any visit, Kontrol included (S6-D6: the voucher).
|
| SUPERSEDED (owner S6-Q3, revised 2026-11-01): a Kontrol no longer makes EVERY
| free line eligible. On any visit a Rp0 line must be an approved free service;
| a treatment that is normally paid is billed at a positive price and paid
| normally, Kontrol or not.
|
| The capability switch itself is the `rme.zero_value_closure` feature flag.
*/

return [
    'consultation_category_codes' => ['consultation'],

    'control_service_category_codes' => ['control'],

    'excluded_treatment_codes' => ['TRT-FREESC'],
];
