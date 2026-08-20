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
*/

return [

    /*
    | The form offered by default when the operator opens "Pilih Form Consent".
    */
    'default_template' => 'PERSETUJUAN_TINDAKAN_MEDIS',

    /*
    | Where the signature PNGs live. This is a PRIVATE disk on purpose: a
    | signed consent carries an identity number and a handwritten signature,
    | so no public URL may ever exist for it. Files are served only through
    | the policy-gated consent signature route.
    */
    'signature_disk' => 'local',
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

];
