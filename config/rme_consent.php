<?php

/*
|--------------------------------------------------------------------------
| RME consent forms — FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01
|--------------------------------------------------------------------------
|
| Canonical registry of the consent forms a patient may be asked to sign
| before an RME payment is accepted.
|
| The wording below is transcribed from the clinic's own printed form,
| "PERSETUJUAN TINDAKAN MEDIS", which is the product authority for this
| document. It is NOT to be rephrased, softened, extended or trimmed by
| anyone editing this file: only a new product decision may change the
| substance of a consent clause. Digital formatting normalisation
| (whitespace, punctuation, line wrapping) is the only latitude here.
|
| Versioning: every signed consent stores a snapshot of the exact template
| content it was signed against (RmeVisitConsent::content_snapshot), so
| editing this file NEVER retro-changes a consent a patient already signed.
| When the substance changes, bump `version` so new consents are
| distinguishable from historical ones.
|
| DMS memo M4.4, PTM plan PR3 (rule 241 §6) — `candidate_templates` holds the
| NEXT version of a form (v2026.2: clause 8 = clinical documentation, clause 9 =
| publication/promotion, two separate YA/TIDAK answers). It is never a
| selectable form of its own: RmeVisitConsentService signs a NEW consent on it
| only while the risky flag `rme.consent_template_v2026_2` is on AND its
| `wording_status` is exactly `approved` with no draft marker left in its text;
| otherwise new consents keep the current template exactly as before. The
| wording is a product decision (rule 100 §4) still pending from the owner, so
| it ships as a marked DRAFT. Historical consents never change (their snapshot).
|
*/

$registry = [

    /*
    | The form offered by default when the operator opens "Pilih Form Consent".
    */
    'default_template' => 'PERSETUJUAN_TINDAKAN_MEDIS',

    /*
    | Where the signature PNGs live: the clinical evidence disk, through
    | App\Support\Storage\ClinicalEvidenceStorage (rule 121 §1, OD-5-9 closed by
    | DMS-RME-COMPREHENSIVE-CLOSURE-1). A signed consent carries an identity
    | number and a handwritten signature, so no public URL may ever exist for it;
    | files are served only through the policy-gated consent signature route.
    |
    | `legacy_signature_disk` names where signatures were written BEFORE that
    | change. `rme:consent-signatures-migrate` copies from it (key for key,
    | SHA-256 verified, never deleting).
    |
    | `legacy_read_fallback` — release 2, owner decision 2026-11-17 (rule 237
    | §14.5a, a temporary exception to rule 121 §5): while true, a READER whose
    | object is not yet on the clinical evidence disk reads it, read-only, from
    | `legacy_signature_disk`. It stays true until the copy is applied and
    | verified; turning it off is a separate owner-approved change. Never an
    | environment override (no env read here).
    */
    'legacy_signature_disk' => 'local',
    'legacy_read_fallback' => true,
    'signature_directory' => 'rme-consents',

    /*
    | Relationship of the person signing to the patient, taken verbatim from
    | the printed form: "Saya sendiri/ istri/ suami/ anak/ ayah/ ibu".
    | `self` prefills the patient's own identity; every other value describes
    | a family member signing on the patient's behalf, whose identity is
    | therefore captured separately from the patient's.
    */
    'relationships' => [
        'self' => 'Saya sendiri',
        'istri' => 'Istri',
        'suami' => 'Suami',
        'anak' => 'Anak',
        'ayah' => 'Ayah',
        'ibu' => 'Ibu',
    ],

    /*
    | The printed form asks for gender as "( P / L )". These are the labels
    | used when rendering the canonical patient gender values.
    */
    'gender_labels' => [
        'Female' => 'P',
        'Male' => 'L',
    ],

    'templates' => [

        'PERSETUJUAN_TINDAKAN_MEDIS' => [
            'code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
            'version' => '2026.1',
            'title' => 'PERSETUJUAN TINDAKAN MEDIS',

            /*
            | Location printed above the signature block. The printed form
            | reads "Makassar,.......", and the clinic is a Makassar practice,
            | so this is the product default. The signing DATE is never
            | hardcoded — it comes from the clinical clock at signing time.
            */
            'location' => 'Makassar',

            'consent_statement' => 'Dengan ini menyatakan Persetujuan untuk diberikan tindakan medis berupa',

            'clauses_intro' => 'Adapun hal-hal yang harus/wajib disetujui dalam surat persetujuan ini sebagai berikut:',

            /*
            | The eight consent clauses, verbatim from the printed form.
            */
            'clauses' => [
                1 => 'Saya telah diberikan penjelasan dan setuju oleh Dokter gigi mengenai tindakan medis yang diperlukan, biaya, prosedur tindakan serta risiko yang mungkin akan timbul sebagai akibat dari tindakan medis tersebut.',
                2 => 'Saya setuju bahwa Dokter gigi yang melakukan tindakan medis terhadap saya sesuai dengan kompetensi dan standar medis yang berlaku.',
                3 => 'Saya berhak menolak dan/ menghentikan tindakan medis dengan memahami segala risiko dan bertanggung jawab atas segala konsekuensi yang terjadi.',
                4 => 'Saya tidak akan menyalahgunakan informasi dari Dokter gigi yang menangani saya untuk mempengaruhi opini atau keputusan Dokter gigi lain.',
                5 => 'Saya tidak akan membandingkan tindakan medis saya untuk menyalahkan pihak tertentu dan/ mengajukan keluhan yang tidak berdasar.',
                6 => 'Saya tidak akan menyalahkan Dokter gigi yang menangani tindakan medis ini/klinik secara sepihak tanpa proses evaluasi medis dari kedua belah pihak, jika tindakan medis tidak sesuai harapan atau terjadi komplikasi.',
                7 => 'Apabila terjadi perselisihan antara Dokter gigi/klinik dengan pasien maka akan diselesaikan secara musyawarah dan mufakat.',
                8 => 'Saya memberikan persetujuan kepada klinik gigi daengtisia secara sadar dan tanpa paksaan dari pihak manapun untuk dokumentasi medis dan mempublikasikan foto/video perawatan saya (YA/TIDAK) dengan syarat tidak menampilkan informasi pribadi.',
            ],

            /*
            | Clause 8 is the documentation/publication consent. It is the ONLY
            | clause with its own recorded YES/NO answer, it has NO default, and
            | answering TIDAK must never block treatment or payment.
            */
            'documentation_clause' => 8,
            'documentation_labels' => [
                true => 'YA',
                false => 'TIDAK',
            ],

            'declaration' => 'Demikian persetujuan ini saya buat dengan penuh kesadaran dan tanpa paksaan apapun.',

            'signature_labels' => [
                'doctor' => 'Dokter',
                'consenter' => 'Yang membuat persetujuan',
            ],
        ],

    ],

    /*
    | Rule 241 §6 — the visible marker of wording that is NOT approved. Any text
    | of a candidate that still carries it refuses activation, even when its
    | `wording_status` says `approved` (fail closed).
    */
    'draft_marker' => '[DRAF — menunggu persetujuan redaksi]',

];

/*
|--------------------------------------------------------------------------
| PERSETUJUAN TINDAKAN MEDIS v2026.2 — DRAFT, inactive (rule 241 §6)
|--------------------------------------------------------------------------
|
| Derived from 2026.1 so that everything except clauses 8 and 9 stays verbatim:
| clauses 1–7, title, statement, declaration, signature labels and location are
| the printed form's text, copied, never retyped. Clauses 8 and 9 below are a
| DRAFT adapted from the 2026.1 clause 8 and are NOT the clinic's wording: the
| owner sends the final text, which replaces them word for word together with
| `wording_status => 'approved'` in a reviewed change. Until then activation is
| refused even with the flag on, and the draft marker stays in the text so the
| draft can never be mistaken for approved wording wherever it is previewed.
*/
$ptm2026_1 = $registry['templates']['PERSETUJUAN_TINDAKAN_MEDIS'];

$registry['candidate_templates'] = [
    'PERSETUJUAN_TINDAKAN_MEDIS' => array_replace($ptm2026_1, [
        'version' => '2026.2',
        'wording_status' => 'draft',
        'clauses' => array_replace($ptm2026_1['clauses'], [
            8 => $registry['draft_marker'].' Saya memberikan persetujuan kepada klinik gigi daengtisia secara sadar dan tanpa paksaan dari pihak manapun untuk dokumentasi medis berupa foto/video perawatan saya sebagai bagian dari rekam medis (YA/TIDAK).',
            9 => $registry['draft_marker'].' Saya memberikan persetujuan kepada klinik gigi daengtisia secara sadar dan tanpa paksaan dari pihak manapun untuk mempublikasikan foto/video perawatan saya untuk keperluan edukasi/promosi (YA/TIDAK) dengan syarat tidak menampilkan informasi pribadi. Menolak publikasi tidak memengaruhi perawatan saya.',
        ]),
        // Two answers: clause 8 is stored in `documentation_consent`, clause 9
        // in `publication_consent`. No workflow reads clause 9 (rule 241 §6.5).
        'documentation_clause' => 8,
        'publication_clause' => 9,
    ]),
];

return $registry;
