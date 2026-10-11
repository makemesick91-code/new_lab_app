<?php

/*
|--------------------------------------------------------------------------
| RME invoice document — printed terms
|--------------------------------------------------------------------------
|
| REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1.
|
| The RME payment invoice follows the clinic's own printed "INVOICE" form. That
| form carries two blocks of text the application had no record of: the refund
| terms and the bank account a patient may transfer to. Both were explicitly
| APPROVED BY THE OWNER on 2026-10-12, verbatim from the reference document,
| for every branch.
|
| They live here — source-controlled, and deliberately NOT read from the
| environment — because they are payment instructions printed on a financial
| document. Changing an account number or a refund rule must therefore go
| through a reviewed commit, never through a host edit nobody can see.
|
| Rules for this file:
|   - Never add an account or a refund rule that the owner has not approved.
|   - An empty `items` list / a null `account_number` prints NOTHING for that
|     block (the template never invents a fallback).
|   - This file describes what is PRINTED. It does not implement refunds and
|     does not change any billing, payment or receivable behaviour.
|
*/

return [

    'approval' => [
        'approved_by' => 'Owner',
        'approved_on' => '2026-10-12',
        'source' => 'Owner-supplied reference document "INVOICE .pdf" (Cabang Landak Baru form)',
    ],

    'document_title' => 'INVOICE',

    'closing' => [
        'Terima Kasih Sudah Berkunjung,',
        'Semoga Sehat Selalu',
    ],

    'refund_terms' => [
        'heading' => 'Ketentuan Refund',
        'items' => [
            [
                'label' => 'Kawat Gigi',
                'text' => 'Refund bisa diajukan dan diproses jika permintaan refund dari pasien sebelum 2x24 jam setelah akad dengan dokter.',
            ],
            [
                'label' => null,
                'text' => 'Dana refund yang melalui proses pengajuan akan dikembalikan di akhir bulan pengajuan refund.',
            ],
            [
                'label' => null,
                'text' => 'Untuk pengajuan refund jenis perawatan lain, dapat diajukan dengan syarat dan ketentuan berlaku sesuai aturan klinik.',
            ],
        ],
    ],

    'bank_transfer' => [
        'heading' => 'Kirim ke Rekening',
        'account_number' => '0664-01-000849-56-7',
        'bank_name' => 'Bank BRI',
        'account_name' => 'Yayasan Daengtisia Peduli Indonesia',
    ],

];
