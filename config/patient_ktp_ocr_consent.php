<?php

/*
|--------------------------------------------------------------------------
| KTP OCR consent wording — pilot decision D7
|--------------------------------------------------------------------------
|
| PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1. The patient-facing consent
| text for the supervised KTP camera OCR pilot, approved verbatim by the
| owner on 2026-10-10 (protocol decision D7).
|
| This file is the ONLY copy of the wording the application shows. It is
| read by exactly one class, App\Modules\Patient\Support\KtpOcrConsent; the
| Blade consent panel renders it and the OCR parse endpoint accepts only a
| request carrying the CURRENT `version`, so an operator cannot attest to a
| wording other than the one on screen.
|
| Deliberately NOT read from the environment: changing the wording is a new
| owner approval AND a code change with a new `version` — never an env edit.
| Do not paraphrase, shorten or translate these paragraphs in a view.
|
*/

return [

    'version' => 'D7-2026-10-10',

    // Recorded decision, not an authorization of anything beyond the pilot.
    'decision' => 'D7',
    'approved_on' => '2026-10-10',
    'approved_by' => 'owner',

    'title' => 'PERSETUJUAN PEMINDAIAN KTP',

    'paragraphs' => [
        'Saya memberikan persetujuan kepada Klinik Gigi Daengtisia untuk mengambil foto KTP dan memproses informasi identitas saya menggunakan sistem DaengtisiaMS.',
        'Pemrosesan dilakukan untuk membantu pengisian dan verifikasi data pendaftaran pasien.',
        'Saya memahami bahwa hasil pembacaan otomatis akan diperiksa kembali oleh petugas klinik sebelum disimpan.',
        'Foto KTP dan informasi identitas saya akan dikelola sesuai kebijakan privasi dan perlindungan data pribadi yang berlaku di klinik.',
        'Persetujuan ini diberikan secara sukarela setelah saya menerima penjelasan mengenai tujuan penggunaan data.',
    ],

];
