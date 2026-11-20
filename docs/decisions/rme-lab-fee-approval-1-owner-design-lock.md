---
kb_status: current
kb_verified_against: 3a231c28
kb_updated: 2026-11-20
---

# Owner Design Lock — RME-LAB-FEE-APPROVAL-1

Kembali ke [00-INDEX](../00-INDEX.md). Register kronologis:
[rme-billing-correction-owner-decisions](rme-billing-correction-owner-decisions.md). Catatan sprint:
[rme-lab-fee-approval-1](../sprints/rme-lab-fee-approval-1.md).

| Atribut | Nilai |
|---|---|
| Keputusan | `OWNER_POLICY_APPROVED`, 2026-11-20 (WITA) |
| Penegakan teknis | **NOT IMPLEMENTED**: tidak ada kode, migrasi, flag, role database, maupun perubahan produksi |
| Gate 1 | **NO-GO**: tujuh blocker masih terbuka (lihat catatan sprint) |
| Klasifikasi | FOUNDATION-FIRST: FINANCIAL INTEGRITY & DATABASE SECURITY, dipetakan ke kategori FOUNDATION + SECURITY FIX (A-14) |
| Diverifikasi terhadap | `3a231c28` |
| Sumber | Instruksi Owner dalam sesi DEVFLOW 2026-11-20; tidak ada artefak bertanda tangan, dan tidak ada yang dibuat-buat |

**Otoritas (REV-05, A-21).** Keputusan di dokumen ini normatif: ia menentukan perilaku yang wajib. Kode menunjukkan
perilaku saat ini. Selisih keduanya adalah drift implementasi yang dilaporkan dengan bukti. Kode tidak pernah dibaca
sebagai persetujuan Owner, dan keputusan ini tidak diubah agar cocok dengan kode.

Spesifikasi teknis (seluruhnya PROPOSED / NOT IMPLEMENTED):
[state machine keuangan](../architecture/rme-lab-financial-state-machine.md),
[FULL_REVERSAL dan ICFR](../architecture/rme-billing-reversal-icfr.md),
[maker-checker](../architecture/maker-checker-authorization.md),
[least privilege PostgreSQL (R6)](../architecture/database-least-privilege-r6.md). Rule kanonik: 245–249.

## 1. Biaya LAB (DL-LAB)

1. Biaya LAB adalah baris item invoice RME berklasifikasi `LAB_FEE`, tertaut ke baris treatment ber-LAB pada invoice
   yang sama. Bukan invoice lab (`trx_invoices`) dan bukan invoice tambahan.
2. Approval biaya LAB terjadi **sebelum** invoice terbit (A-01, REV-02). Penerbitan memakai tepat satu approval APPROVED
   yang cocok persis, dan hanya sekali.
3. Maker ≠ checker (identitas akun). Checker ditunjuk dan berwenang di cabang, dan bukan kasir penerbit invoice (A-08).
4. Satu-satunya jalur masuk adalah penerbitan. Biaya LAB tidak boleh masuk lewat baris free-text, lewat koreksi invoice
   dalam bentuk apa pun meski ada approval (REV-01), atau lewat invoice tambahan maupun pengganti (REV-04). Koreksi juga
   tidak boleh mengganti treatment ke atau dari treatment ber-LAB (A-29).
5. Subtotal baris `LAB_FEE` wajib positif (A-24).

## 2. Integritas historis (DL-SNAP)

Klasifikasi dan atribut keuangan item disnapshot saat invoice terbit. Perubahan master treatment tidak berlaku surut
pada invoice yang sudah terbit.

## 3. Invarian

- **Inv1, tanpa pengecualian (A-22):** L > 0 → NN > 0. Biaya LAB positif dengan NN = 0 dilarang.
- **Inv2, aturan normal:** HAS_LAB ∧ NN = 0 → L = 0 ∧ status REVERSED.
- **Inv2, amandemen Owner A-02:** invoice dengan HAS_LAB ∧ NN = 0 ∧ L = 0 boleh terbit hanya lewat pengecualian
  penerbitan LAB yang disetujui Owner. Pengecualian itu harus:
  - terikat ke permintaan penerbitan yang persis;
  - sekali pakai, dengan bukti imutabel, dan fail-closed;
  - disetujui in-system oleh identitas di allowlist Owner;
  - berlaku paling lama 7 hari;
  - memakai kode alasan dari daftar tertutup Owner (A-25).

  **Jalur ini nonaktif** sampai alur operasional invoice LAB Rp0 diimplementasikan (A-23, DES-LAB-RP0).
- **Inv3 (A-04):** G = 0 ∧ correction_version > 0 → status REVERSED.
- Invoice Rp0 awal yang sah tanpa LAB tidak pernah ditolak oleh invarian ini.

Definisi L, NN, G, dan HAS_LAB ada di [state machine keuangan](../architecture/rme-lab-financial-state-machine.md) §3.

## 4. FULL_REVERSAL (ODR-02, DL-REV)

- Reversal tagihan memakai mekanisme khusus dengan maker-checker. Setiap reversal membutuhkan approval Owner sampai
  Owner menetapkan ambang risiko (A-07).
- Status akhirnya REVERSED (terminal). Kewajiban tagihan aktif menjadi 0, sedangkan nilai historis tetap tersimpan.
- Reversal **tidak** mengubah:
  - pembayaran historis (D26);
  - saldo kredit pasien, karena tidak ada kredit yang dibuat (D6);
  - refund (tidak ada refund otomatis);
  - rekam klinis dan status visit (T3);
  - catatan domain lab.
- Invoice dengan G = 0 tidak bisa di-reverse (A-10).
- Model M1 adalah pilihan desain yang **memerlukan bukti rekonsiliasi** sebelum dinyatakan final.
- Antrean kasir menampilkan visit dengan invoice REVERSED sebagai "Dibatalkan" (read-only), tanpa mengubah status visit
  (A-09).

## 5. Guard reversal

- **T1–T10** disetujui; definisi teknisnya ada di spesifikasi reversal.
- **A-05:** reversal hanya boleh setelah pekerjaan LAB dan semua kewajiban LAB selesai atau dibatalkan secara sah,
  dengan bukti terverifikasi. Label status saja tidak cukup.
- **A-30:** invoice HAS_LAB tanpa tautan kandidat hanya bisa di-reverse dengan deklarasi maker dan checker yang ditunjuk
  bahwa tidak ada pekerjaan lab. Deklarasi itu dicatat sebagai bukti imutabel.
- **A-20:** reversal diblokir selama ada billing dispute aktif. Reversal bukan cara menyelesaikan dispute.
- **T5 = A-06:** reversal invoice yang sudah menerima pembayaran baru boleh setelah kelima syarat ini terpenuhi:
  1. writer obligation reversal sudah teruji;
  2. rule 215/216 sudah diamandemen dan diverifikasi;
  3. refund request dan settlement aktif untuk cabang tersebut;
  4. reviewer dan settler sudah ditunjuk;
  5. keputusan aktivasi Owner sudah tercatat.

## 6. ICFR (Opsi 2, DL-ICFR)

ICFR adalah kondisi pelaporan turunan, bukan status invoice. Kondisinya: status invoice REVERSED **dan** obligation yang
bersumber dari reversal itu berstatus OPEN. Setelah obligation SETTLED, invoice tetap REVERSED dan kondisi ICFR tidak
berlaku lagi.

## 7. Obligation, refund, dan settlement

- Setiap obligation punya tepat satu sumber: koreksi atau reversal (A-13).
- Rule 215/216 diamandemen agar obligation bersumber reversal pada invoice REVERSED bisa diproses, tanpa mengubah
  perilaku obligation bersumber koreksi pada invoice PAID (A-19).
- Amandemen ini dependensi P0 sampai diimplementasikan dan diverifikasi.

## 8. Transisi

- **Invoice:** UNPAID→PARTIAL, PARTIAL→PAID, UNPAID→PAID, UNPAID→NO_CHARGE, UNPAID→REVERSED, PARTIAL→REVERSED,
  PAID→REVERSED. REVERSED dan NO_CHARGE terminal. Transisi koreksi pasca-bayar rule 207 tetap berlaku (A-11).
- **Obligation:** OPEN → proses settlement yang disetujui → SETTLED, mengikuti semantik rule 215/216.

## 9. Database (DL-R6)

- Model empat role (`dms_owner`, `dms_migrator`, `dms_runtime`, `dms_readonly`) adalah baseline perencanaan, **bukan**
  izin mengubah role.
- Migrasi memakai `SET ROLE` eksplisit, menunggu verifikasi CI (A-17).
- Perubahan identitas backup ditunda (A-18).
- Hak produksi aktual: NOT VERIFIED.

## 10. Tetap berlaku

D6, D23, D26, D28 / rule 201, rule 166 (ODR-01), rule 194, rule 207, rule 215/216 (sampai diamandemen), rule 217
(S6-Q2), dan rule 232 (ODR-03).

## 11. Masih terbuka

- Isi daftar tertutup kode alasan pengecualian (A-25).
- Desain dan implementasi alur invoice LAB Rp0 (DES-LAB-RP0, A-23).
- Semantik terminal lab order legacy V1 (A-26).
- Bukti yang sah untuk invoice lab yang tidak ada atau berstatus VOID (A-27).
- Definisi "dibatalkan secara sah" (A-28).
- Bukti rekonsiliasi model M1.
- RA-LAB-1: `PENDING_OWNER_APPROVAL`. Tidak ada risiko yang diterima.
