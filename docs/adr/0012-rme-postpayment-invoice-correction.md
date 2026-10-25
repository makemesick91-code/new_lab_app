# ADR 0012 — Koreksi tagihan RME setelah pembayaran (PARTIAL dan PAID)

* Status: **ACCEPTED (keputusan Owner PP-1…PP-10 diratifikasi 2026-10-25)** — implementasi `NOT_IMPLEMENTED`, aktivasi diblokir (PP-10). Status asli: ~~PROPOSED — desain persiapan, BUKAN keputusan yang berlaku dan BUKAN kode~~
* Tanggal: 2026-10-25 (WITA)
* Tugas: `PREPARATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1` (program
  `DAENGTISIAMS-RME-BILLING-CORRECTION`, persiapan Sprint 3/7)
* Fitur target: `FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1`
* Baseline: produksi `20303a5b` (tag `fix-rme-payment-submission-idempotency-1-go`),
  rule terbaru 205
* Desain lengkap: `docs/architecture/rme-postpayment-invoice-correction-design.md`
* Usulan keputusan Owner: `docs/decisions/rme-postpayment-correction-owner-decision-proposals.md`

> **Ratifikasi 2026-10-25** (`RATIFICATION-RME-POSTPAYMENT-OWNER-DECISIONS-PP1-PP10-1`):
> Owner menyetujui PP-1…PP-10 sebagaimana teks kanoniknya
> (`docs/decisions/rme-postpayment-correction-owner-decision-proposals.md`). Keputusan
> di bawah kini adalah kebijakan yang berlaku untuk **implementasi**; ADR ini tetap
> **tidak mengaktifkan apa pun** — aktivasi menunggu closure Sprint 2 yang terverifikasi
> (PP-10), dan pada 2026-10-25 Sprint 2 tetap NO_GO.
>
> *Teks asli, disimpan sebagai sejarah:*
> ADR ini **tidak mengaktifkan apa pun** dan **tidak menyetujui kebijakan apa pun**.
> Setiap "keputusan" di bawah adalah USULAN desain yang baru boleh diimplementasikan
> setelah Owner mencatat keputusan PP-1 s.d. PP-10 di register. Selama itu, layanan
> koreksi tetap menolak setiap tagihan yang sudah memiliki pembayaran dengan
> `CORRECTION_TYPE_NOT_ENABLED`.

## Konteks

Koreksi tagihan RME saat ini hanya untuk tagihan **UNPAID tanpa pembayaran**
(rule 190–202, pilot SPN4, maker user 29, checker user 1, D1/D1a/D1c/D1e). Tipe
`POST_PAYMENT` sudah dideklarasikan di skema (CHECK `correction_type IN
('PRE_PAYMENT','POST_PAYMENT')`) tetapi `RmeInvoiceCorrectionType::ENABLED =
[PRE_PAYMENT]` dan `prePaymentIneligibility()` menolak tagihan berpembayaran.

Tiga fakta struktural yang mengikat desain ini (diverifikasi di sumber, lihat
desain §2):

1. **Total tagihan hanya berubah lewat koreksi**, dan baris pembayaran tidak pernah
   diubah (`RmePayment` menolak update/delete — rule 189). Karena setiap
   pembayaran dibatasi sisa tagihan, `Σ pembayaran ≤ grand_total` untuk setiap
   tagihan — produksi 2026-10-25: **0 tagihan kelebihan bayar** dari 7 tagihan
   positif PARTIAL/PAID.
2. **Kelebihan bayar hanya bisa diproduksi oleh koreksi turun setelah
   pembayaran.** Refund adalah *konsumen* kelebihan bayar (desain refund §1). Maka
   koreksi pasca-bayar harus lebih dulu ada — dan harus mencatat kewajiban yang ia
   timbulkan — sebelum refund dibangun.
3. **Kwitansi dirender dari baris live** (`RmePaymentController::receipt` →
   `resolveReceiptData`, hanya bila `PAID`). Dokumen tagihan sudah berversi (D7,
   v0/vN), tetapi kwitansi pembayaran yang sudah diserahkan ke pasien belum.

## Keputusan desain (USULAN)

1. **Satu mesin koreksi, dua tipe.** Koreksi pasca-bayar memakai tabel, state
   machine, maker≠checker, lock order, fingerprint, D1a (approve = apply), D10,
   D28 dan audit yang sama dengan koreksi pra-bayar. Tidak ada jalur paralel.
   Tipe ditentukan **server** dari ledger di bawah lock (`Σ pembayaran > 0` ⇒
   `POST_PAYMENT`), tidak pernah dari input.
2. **Saklar terpisah, mati secara default.** Flag baru (usulan
   `rme.invoice_postpayment_correction`, default OFF, risiko critical) di atas
   flag `rme.invoice_correction`. Mematikannya menghentikan koreksi pasca-bayar
   tanpa menyentuh pilot pra-bayar.
3. **Pembayaran tidak pernah diubah.** Koreksi mengganti item dan total, lalu
   status tagihan **diturunkan ulang dari ledger**: `0 < paid < total ⇒ PARTIAL`,
   `paid ≥ total ⇒ PAID`.
4. **Koreksi turun yang melampaui uang diterima mencatat kewajiban kelebihan
   bayar**, atomik dalam transaksi yang sama (`trx_rme_overpayment_obligations`,
   status `OPEN`), dengan jumlah yang diturunkan server: `max(0, paid − total_baru)`.
   **Koreksi tidak pernah mengeksekusi refund**, tidak membuat pembayaran negatif,
   tidak membuat piutang negatif, dan tidak menyelesaikan kewajiban itu.
5. **Koreksi naik menimbulkan sisa tagihan biasa** yang ditagih lewat jalur
   pembayaran yang ada di cabang tagihan (OD-1a), dengan intent dan versi baru
   (rule 191/205). Tidak ada penagihan otomatis.
6. **Fail-closed pada kewajiban terbuka.** Tagihan yang masih memiliki kewajiban
   kelebihan bayar `OPEN` tidak dapat dikoreksi lagi sampai kewajibannya diselesaikan
   oleh modul refund di masa depan (skenario K).
7. **Kwitansi yang sudah terbit dipertahankan** sebagai catatan uang yang diterima:
   snapshot kwitansi (Fase E) wajib ada sebelum aktivasi; kwitansi lama dicap
   "DIREVISI" dan tidak pernah dirender ulang dari baris live.
8. **D23 tetap:** tagihan yang sudah menghasilkan kandidat lab ditolak.
9. **D28 tetap:** tagihan dengan total ASLI Rp0 ditolak; total usulan Rp0 ditolak
   (D21g PENDING).
10. **Pilot tetap SPN4, identitas 29/1** — tidak diperluas oleh ADR ini.

## Alternatif yang dipertimbangkan

| Alternatif | Mengapa tidak dipilih |
|---|---|
| Mengedit tagihan langsung setelah dibayar | Melanggar D21 butir 6, D7 dan rule 189 (payment immutability); tidak ada maker/checker |
| Pembayaran negatif untuk "mengembalikan" selisih | Mengubah arti KPI kas (Σ pembayaran per `paid_at`) dan membuat refund tanpa settlement terpisah (D18) |
| Piutang negatif | Merusak `SUM` piutang oversight/dashboard dan aging (audit §12); kelebihan bayar adalah kewajiban, bukan piutang |
| Membangun refund lebih dulu | Saldo yang dapat direfund = 0 di semua tagihan; refund akan memproduksi saldo yang tidak ada di ledger (desain refund §1) |
| Menolak semua koreksi turun yang menimbulkan kelebihan bayar sampai refund ada | Aman tetapi membuat tagihan yang diketahui salah tetap salah dan mendorong pengembalian uang di luar sistem; tersedia sebagai opsi PP-3(B) |
| Void tagihan + terbitkan tagihan baru | Memutus rantai versi, mengaburkan atribusi pembayaran, dan `hasActiveInvoiceForVisit` menolak tagihan kedua |

## Konsekuensi

* Kewajiban kelebihan bayar menjadi entitas finansial nyata yang harus terlihat
  (oversight, laporan) dan baru dapat diselesaikan oleh refund (sprint berikutnya,
  D4/D5/D6/D18/D25 masih PENDING).
* Kwitansi pembayaran memerlukan snapshot (Fase E) — prasyarat keras aktivasi.
* Laporan "ditagih" (billed) untuk periode lampau dapat berubah karena item dan
  total berubah; laporan kas (Σ pembayaran) tidak berubah. Kebijakan restatement
  = D8/D9 (PP-6).
* Status tagihan dapat bergerak `PAID → PARTIAL` (koreksi naik) — kwitansi tidak
  lagi tersedia dari halaman live sampai tagihan lunas kembali.

## Status yang tidak berubah oleh ADR ini

Produksi tetap: koreksi pasca-bayar **NOT_IMPLEMENTED / NOT_ACTIVE**; refund,
reversal, kredit pasien, penyesuaian Rp0 **NOT_ACTIVE**; Sprint 2 Full Prepayment
Pilot **belum GO**; D21c, D21g, D22 **PENDING**.
