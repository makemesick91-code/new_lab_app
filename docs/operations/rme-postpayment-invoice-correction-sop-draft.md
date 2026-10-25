# DRAF SOP — Koreksi Tagihan RME Setelah Pembayaran (Pilot SPN4)

> **DRAF — TIDAK BERLAKU.** Fitur ini **belum dibangun dan belum diaktifkan**.
> *Pembaruan 2026-10-25:* keputusan Owner PP-1…PP-10 telah **disetujui**
> (`OWNER_POLICY_APPROVED`, ratifikasi). Penanda **[USULAN PP-x]** di bawah kini
> menunjuk keputusan yang disetujui; SOP tetap DRAF sampai fitur dirilis dan
> **diaktifkan** — aktivasi diblokir oleh PP-10 selama closure Sprint 2 belum
> terverifikasi. Teks di bawah tidak diubah.
> *Pembaruan 2026-10-25 (FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1):* fitur
> **sudah dibangun** dengan sakelar `rme.invoice_postpayment_correction` **OFF**
> (rule 207) dan **belum diaktifkan**. Catatan operasional dari implementasi:
> (1) formulir pengajuan wajib berisi referensi persetujuan Owner (6–120 karakter,
> tanpa 13 digit berurutan) — tanpa itu sistem menolak; (2) bila total baru lebih
> kecil dari yang sudah dibayar, sistem mencatat **kewajiban kelebihan bayar —
> belum dikembalikan**; kasir **tidak** mengembalikan uang di luar sistem; (3)
> kwitansi lama dicetak ulang dari salinan bekunya dengan cap **DIREVISI**; kwitansi
> dari sebelum rilis ini diberi label **rekonstruksi**; (4) koreksi tidak membuat
> kandidat lab — bila koreksi menurunkan total sehingga tagihan PARTIAL menjadi
> lunas, pekerjaan lab untuk kasus itu diajukan lewat jalur permintaan lab biasa;
> (5) tagihan yang sudah punya kandidat lab tidak dapat dikoreksi; (6) koreksi
> hanya untuk kunjungan yang **sudah selesai** — kunjungan Kontrol yang masih
> menunggu di kasir (tagihan dibayar sebagian) ditolak; (7) satu referensi
> persetujuan Owner hanya untuk **satu tagihan** — referensi yang sudah dipakai
> tagihan lain ditolak, dan angka identitas (NIK) tidak boleh dimasukkan meski
> dipisah tanda baca; (8) bila koreksi (bukan pembayaran) membuat tagihan lunas,
> belum ada kwitansi LUNAS — kwitansi terakhir tetap yang "dibayar sebagian"
> bercap DIREVISI (pertanyaan terbuka untuk Owner).
> Jangan dipakai di loket. Dokumen ini disiapkan oleh
> `PREPARATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1` supaya prosedur siap ketika
> Owner menyetujui usulan PP-1…PP-10
> (`docs/decisions/rme-postpayment-correction-owner-decision-proposals.md`) dan
> fitur `FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1` dirilis serta diaktifkan.
> Bagian yang bergantung pada usulan ditandai **[USULAN PP-x]**.
>
> Sampai fitur aktif, tagihan yang sudah dibayar (sebagian atau lunas) dan ternyata
> salah ditangani dengan prosedur yang berlaku hari ini: **laporan dispute tagihan
> (rule 203)** — pembayaran berikutnya ditahan, pasien tetap boleh pulang, tagihan
> tidak diubah sepihak, dan **tidak ada uang yang dikembalikan di luar sistem**.

## 1. Kapan prosedur ini (nanti) dipakai

Tagihan RME cabang Sunu (SPN4) yang **sudah memiliki pembayaran** ternyata salah:

* total terlalu besar (pasien membayar lebih dari seharusnya), atau
* total terlalu kecil (ada tindakan yang belum ditagih).

Tagihan yang **belum dibayar sama sekali** memakai SOP pra-bayar
(`docs/operations/rme-invoice-correction-pilot-sop-spn4.md`).

## 2. Yang TIDAK boleh dilakukan

1. Mengedit tagihan langsung, membuat tagihan kedua, atau membatalkan tagihan.
2. Mengubah atau menghapus pembayaran yang sudah dicatat.
3. **Mengembalikan uang tunai/transfer kepada pasien** — modul refund belum ada.
   Kelebihan bayar hanya **dicatat** sebagai kewajiban klinik. **[USULAN PP-3]**
4. Menagih selisih koreksi naik di cabang lain (OD-1a).
5. Mengoreksi tagihan dengan total asli Rp0 (D28) atau membuat total Rp0.
6. Mengoreksi tagihan yang sudah menghasilkan kandidat lab. **[USULAN PP-8]**
7. Mengajukan koreksi kedua ketika masih ada kewajiban kelebihan bayar yang terbuka.
   **[USULAN PP-3]**
8. Menyetujui koreksi sendiri — siapa pun, termasuk Super Admin.

## 3. Peran

| Peran | Akun | Tugas |
|---|---|---|
| Maker | user 29 (Admin Sunu) **[USULAN PP-1]** | mendeteksi, mengajukan koreksi, menyampaikan informasi ke pasien |
| Checker | user 1 **[USULAN PP-2]** | menerima persetujuan Owner langsung, menyetujui (= menerapkan) atau menolak |
| Owner | — | memberi persetujuan **per kasus** **[USULAN PP-2]** |
| Peninjau dispute cabang (rule 204) | sesuai penetapan | meninjau dispute; **tidak** mengajukan/menyetujui koreksi |

## 4. Langkah

1. **Deteksi.** Kasir/Front Office menemukan tagihan berpembayaran yang salah. Bila
   pasien masih akan membayar sisa: **ajukan dispute** (rule 203) agar pembayaran
   berikutnya ditahan.
2. **Persetujuan Owner per kasus.** Isi formulir
   `docs/operations/rme-invoice-correction-owner-approval-template.md` (ditandai
   "pasca-bayar"): nomor tagihan, total lama, total yang benar, uang yang sudah
   diterima, alasan. Teks bebas tanpa NIK (tidak ada deret ≥13 digit).
3. **Pengajuan (Maker).** Buka tagihan → panel koreksi → isi item yang benar dan
   alasan (≥10 karakter) yang memuat referensi persetujuan Owner. Sistem menampilkan
   sebelum → sesudah: total, dibayar, sisa, kelebihan bayar. **[USULAN PP-2]**
4. **Persetujuan (Checker).** Checker memastikan persetujuan Owner **langsung dari
   Owner** (bukan dari teks maker), membandingkan item asli → usulan, lalu menyetujui.
   Persetujuan langsung menerapkan koreksi (D1a). Bila tagihan berubah sejak
   pengajuan, sistem menandai koreksi **Kedaluwarsa** — ajukan ulang.
5. **Hasil.**
   * Koreksi naik → status menjadi **Dibayar Sebagian**; sisa ditagih di Sunu lewat
     pembayaran biasa (muat ulang halaman pembayaran — form lama akan ditolak).
   * Koreksi turun di atas uang diterima → sisa tagihan berkurang.
   * Koreksi turun di bawah uang diterima → status **Lunas** + **Kewajiban Kelebihan
     Bayar** tercatat. **Jangan mengembalikan uang.** Beri tahu pasien bahwa
     kelebihan bayar tercatat dan akan diproses melalui prosedur pengembalian resmi
     ketika tersedia. **[USULAN PP-3]**
6. **Dispute.** Bila ada dispute aktif, selesaikan dengan hasil **Koreksi
   diterapkan** (diverifikasi sistem).
7. **Rekonsiliasi.** Jalankan pemeriksaan §7 (read-only) dan simpan hasil sebagai
   teks (bukan screenshot mentah).

## 5. Pesan untuk pasien (contoh)

* Koreksi naik: "Ada tindakan yang belum tertagih pada kunjungan Anda. Rincian
  tagihan yang telah diperbaiki adalah sebagai berikut … Sisa yang perlu dibayar:
  Rp…"
* Koreksi turun dengan kelebihan bayar: "Tagihan Anda telah diperbaiki. Pembayaran
  Anda sebesar Rp… melebihi total yang benar sebesar Rp…. Kelebihan Rp… tercatat
  sebagai kewajiban klinik dan akan dikembalikan melalui prosedur resmi."

## 6. Insiden

| Kejadian | Tindakan |
|---|---|
| Sistem menolak dengan `CORRECTION_TYPE_NOT_ENABLED` | Fitur belum aktif — gunakan dispute |
| `CORRECTION_STALE:*` | Tagihan berubah; ajukan ulang terhadap tagihan terkini |
| `CORRECTION_LAB_CANDIDATES_EXIST` | Eskalasi ke Owner (D23) — jangan dicoba ulang |
| `CORRECTION_ORIGINAL_TOTAL_ZERO` | Tagihan Rp0 — tidak dapat dikoreksi (D28) |
| `CORRECTION_OVERPAYMENT_OBLIGATION_OPEN` **[USULAN]** | Tunggu penyelesaian kewajiban |
| Pembayaran ditolak `INVOICE_CORRECTION_ACTIVE` | Normal (D10) — selesaikan koreksi dulu |
| Pasien meminta uang kembali saat itu juga | Jangan bayar dari kas; catat permintaan; eskalasi ke Owner |

## 7. Rekonsiliasi read-only (template)

Dijalankan oleh petugas IT di VPS, transaksi dipaksa read-only. Ganti `<INV>` dengan id
tagihan.

```bash
runuser -u postgres -- env PGOPTIONS="-c default_transaction_read_only=on" \
  psql -X -q -d asia_dental_lab_pilot -At -F ' | ' -v ON_ERROR_STOP=1 -v inv=<INV> <<'SQL'
show default_transaction_read_only;                       -- harus: on
select 'invoice', id, status, grand_total, correction_version
  from trx_rme_invoices where id = :inv;
select 'paid', coalesce(sum(amount),0), count(*) from trx_rme_payments where rme_invoice_id = :inv;
select 'items_sum', coalesce(sum(qty*unit_price - discount),0) from trx_rme_invoice_items where rme_invoice_id = :inv;
select 'balance', i.grand_total, p.paid,
       greatest(i.grand_total - p.paid, 0) as outstanding,
       greatest(p.paid - i.grand_total, 0) as overpaid
  from trx_rme_invoices i
  cross join (select coalesce(sum(amount),0) paid from trx_rme_payments where rme_invoice_id = :inv) p
 where i.id = :inv;
select 'corrections', id, correction_type, status, original_grand_total, proposed_grand_total,
       paid_total_at_request, invoice_version_at_request, applied_invoice_version
  from trx_rme_invoice_corrections where rme_invoice_id = :inv order by id;
select 'doc_versions', version_number, version_kind, source_correction_id
  from trx_rme_invoice_document_versions where rme_invoice_id = :inv order by version_number;
-- [SETELAH FITUR DIRILIS] kewajiban kelebihan bayar:
-- select 'obligations', id, status, amount, source_correction_id
--   from trx_rme_overpayment_obligations where rme_invoice_id = :inv;
SQL
```

Hasil yang benar: `items_sum = grand_total`; `outstanding` dan `overpaid` tidak
keduanya > 0; status `PARTIAL` bila 0 < dibayar < total, `PAID` bila dibayar ≥ total;
jumlah dan daftar pembayaran sama persis dengan sebelum koreksi; kewajiban = `overpaid`.
