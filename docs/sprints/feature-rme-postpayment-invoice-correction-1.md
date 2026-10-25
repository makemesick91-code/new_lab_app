# FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — Koreksi tagihan RME setelah pembayaran (flag OFF)

Program `DAENGTISIAMS-RME-BILLING-CORRECTION` · Sprint 3/7 · rule **207** · ADR 0012.

> **Ruang lingkup GO sprint ini = `IMPLEMENTATION_GO / FLAG_OFF`.** Bukan aktivasi, bukan
> penerimaan operator nyata, bukan Full Feature GO. Sakelar
> `rme.invoice_postpayment_correction` tetap **OFF** di production. Aktivasi adalah
> keputusan Owner tersendiri (PP-10) dan **diblokir** selama pilot pra-bayar NO_GO
> (`docs/sprints/verification-rme-invoice-correction-prepayment-pilot-real-case-42-1.md`).

## 1. Baseline

| Butir | Nilai |
|---|---|
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `572a32a9` (Sprint B merge) |
| Runtime production sebelum sprint | `20303a5b` |
| Gerbang masuk | PP-1…PP-10 `OWNER_POLICY_APPROVED` (Sprint B) — implementasi diizinkan; aktivasi tidak |
| Sprint A | `FULL_PREPAYMENT_PILOT_GO = NO_GO`, `GO_TAG = NONE` — tidak diubah oleh sprint ini |

## 2. Yang dibangun (sesuai PP)

| PP | Implementasi |
|---|---|
| PP-1 | Flag kedua `rme.invoice_postpayment_correction` (default `false`, risk `critical`, env `FEATURE_RME_INVOICE_POSTPAYMENT_CORRECTION`), `RmeInvoicePostPaymentCorrectionFeatureGuard`. Submit/approve POST_PAYMENT butuh kedua flag. `RmeInvoiceCorrectionType::ENABLED` tetap `[PRE_PAYMENT]`. Allowlist SPN4 dan identitas 29/1 dipakai ulang tanpa perubahan. |
| PP-2 / PP-7 | Referensi persetujuan Owner **wajib** untuk POST_PAYMENT (layanan, di bawah lock) + CHECK `trx_rme_inv_corr_postpay_reference_chk`; format rule 202 (`RmeInvoiceCorrectionApprovalReference`). Opsional untuk PRE_PAYMENT. Maker≠checker per akun tetap; approve = apply (D1a). |
| PP-3 | Total baru < uang diterima ⇒ `trx_rme_overpayment_obligations` berstatus `OPEN` dengan jumlah dari server, event + audit, dalam transaksi yang sama. Tanpa refund, tanpa piutang negatif. Koreksi berikutnya ditolak selama ada kewajiban OPEN (layanan + partial unique index). |
| PP-4 | Total naik ⇒ selisih menjadi sisa tagihan biasa (PAID → PARTIAL), ditagih lewat pembayaran normal; kunjungan tidak dibuka kembali. |
| PP-5 | `trx_rme_receipt_snapshots`: satu snapshot kwitansi imutabel per pembayaran, diterbitkan di transaksi pembayaran (`RmePaymentService::refreshInvoiceStatus`). Kwitansi lama direkonstruksi (berlabel) sebelum koreksi pasca-bayar pertama memutasi tagihan. Kwitansi versi lama dicetak dari snapshot dengan cap "DIREVISI — LIHAT VERSI KE-N"; snapshot gagal integritas ditolak (409). |
| PP-6 | Tidak ada perubahan KPI kas (Σ pembayaran per `paid_at`); kewajiban OPEN dilaporkan terpisah. |
| PP-8 | (a) Tagihan dengan kandidat lab ditolak. (b) Koreksi tidak membuat kandidat lab. |
| PP-9 | Daftar + dokumen kewajiban read-only di oversight (`oversight.billing-corrections.obligations.*`, diaudit, 404 di luar cakupan cabang); status kewajiban di halaman tagihan kasir. Tidak ada route tulis. |
| PP-10 | Tidak diaktifkan. Probe readiness melaporkan `POSTPAYMENT_STATE` dan kewajiban OPEN. |

Status setelah penerapan selalu diturunkan oleh `RmeInvoiceLedger::statusAfterMoneyReceived()`
— helper yang sama dengan jalur pembayaran.

## 3. Skema (aditif)

1. `2026_10_25_200001` — kolom baru `trx_rme_invoice_corrections`: `invoice_status_at_request`,
   `paid_payment_count_at_request`, `outstanding_after`, `overpaid_after`, `invoice_status_after`,
   `owner_approval_reference` + CHECK ledger & CHECK referensi (PostgreSQL). `down()` menolak bila ada baris POST_PAYMENT.
2. `2026_10_25_200002` — `trx_rme_overpayment_obligations` (+ events, append-only); CHECK status/amount,
   partial unique satu OPEN per invoice. `down()` menolak bila ada baris.
3. `2026_10_25_200003` — `trx_rme_receipt_snapshots` (UNIQUE `rme_payment_id`), CHECK basis & status. `down()` menolak bila ada baris.

Tanpa backfill, tanpa izin baru, tanpa seeder, tanpa perubahan role.

## 4. Bukti pengujian

Semua angka dari JUnit (`--log-junit`), bukan ringkasan Pest: tanpa file lingkungan,
Pest menandai setiap tes sebagai *warning*, sehingga lulus/lewati dihitung dari JUnit.
Lokal = PHP 8.5 CLI; PG = kontainer `postgres:16.15` khusus tugas ini.

| Lingkup | Driver | Tes | Gagal | Dilewati | Asersi |
|---|---|---|---|---|---|
| Suite baru: Lifecycle / Access / Concurrency (post-payment) | SQLite | 54 / 15 / 8 | 0 | 8 (Concurrency: hanya PG) | 192 / 61 / — |
| Suite baru: Lifecycle / Access / Concurrency | **PG16** | 53 / 15 / 8 | 0 | 0 | 189 / 61 / 53 |
| `tests/Feature/RmeInvoiceCorrection` (seluruh direktori, versi akhir) | SQLite | 362 | 0 | 25 (semuanya suite race PG) | 1471 |
| RmeInvoiceCorrection + RmePaymentIdempotency + RmeBillingDispute + RmeManualPricing + PatientMerge | **PG16** | 740 | 0 | 0 | 3528 |
| Regresi luas: RME, RmeInvoiceCorrection, RmePaymentIdempotency, RmeBillingDispute, RmeManualPricing, RmeTariffAuthority, PatientMerge, Patient, ClinicOversight, Navigation, Ui, Pilot, Owner, Reporting, AccessControl, Invoice | SQLite (salinan terisolasi) | 3582 | 0 | 98 (semuanya suite race PG) | 13722 |
| 4 suite race PG yang tidak ada di baris PG di atas (refund Phase-0, tariff provisioning, registrasi kunjungan, daily branch) + `CriticalGateSuiteCoverageTest` | **PG16** | 32 | 0 | 0 | 423 |
| Feature flag (Foundation + Unit, `--filter=FeatureFlag`) | SQLite | 41 | 0 | 0 | 584 |

Catatan jujur:
- Run PG16 740 dilakukan sebelum satu tes terakhir ditambahkan (staleness kewajiban
  OPEN, §5.2); tes itu lulus di SQLite dan berjalan di gate kritis CI (PG).
- Run PG16 pertama (sebelum perbaikan) **gagal 36**: 34 karena daftar pembersihan
  suite race tidak memuat tabel baru (FK snapshot kwitansi → pembayaran), 1 karena
  pin jumlah route oversight (7 → 9), 1 karena fixture memasukkan baris POST_PAYMENT
  tanpa referensi (kini ditolak CHECK). Ketiganya diperbaiki; tidak ada tes yang
  dilemahkan.
- Migrasi: `migrate:rollback --step=3` lalu `migrate` bersih di PG16; 6 CHECK + 1
  partial unique index terbukti ada. Penolakan `down()` saat ada baris diverifikasi
  dengan membaca kode, tidak dieksekusi dengan data.
- `vendor/bin/pint --test` seluruh repo: **passed**. `git diff --check`: bersih.
- DEVFLOW (dipin ke `--base-sha=572a32a9`): `sprint:manifest-check` GO,
  `sprint:scope-audit` GO, `foundation:devflow-check --strict` GO,
  `foundation:shared-service-audit --strict` GO, `devflow:base-ref-check` GO.
- `foundation:feature-flags`: `rme.invoice_postpayment_correction enabled=false
  default=false risk=critical captured=yes`.
- Full Suite: **tidak dijalankan** (kebijakan global sementara masih ACTIVE). Bukan PASS.

## 5. Review keamanan & mutasi

### 5.1 Review keamanan independen (adversarial, subagen terpisah, atas diff yang belum di-commit)

Kesimpulan reviewer: inti uang sehat — tidak ada jalur yang menulis/mengubah/menghapus
baris pembayaran, flag OFF memblokir POST_PAYMENT, urutan lock dan staleness benar.
Temuan dan penyelesaiannya:

| Tingkat | Temuan | Penyelesaian |
|---|---|---|
| **HIGH** | FK baru `trx_rme_overpayment_obligations.patient_id` tidak terdaftar di `PatientOwnedRecordRegistry` ⇒ **setiap** merge pasien menolak (`OWNERSHIP_REGISTRY_INCOMPLETE`) dan `PatientMergeSafetyTest` (suite wajib) merah. | **FIXED** — didaftarkan (grup `invoices`), dipindah lewat query builder; jumlah/status/sumber/event tak berubah. Uji baru memanggil `reassign()` nyata dan `unregisteredPatientForeignKeys()`. |
| **MEDIUM** | Kunjungan Kontrol menunggu di `cashier_pending` selama tagihannya PARTIAL; koreksi yang melunasinya meninggalkan kunjungan menggantung (tagihan PAID menolak pembayaran). | **FIXED** (konservatif, tanpa keputusan Owner baru) — POST_PAYMENT hanya untuk kunjungan **COMPLETED** (`CORRECTION_VISIT_NOT_COMPLETED`; kunjungan hilang ⇒ ditolak) dan status kunjungan dibaca ulang saat approve (`STALE:VISIT_STATUS_CHANGED`). Koreksi tidak pernah menyelesaikan kunjungan. |
| LOW–MED | Snapshot kwitansi ditulis pada setiap pembayaran tanpa melihat flag; teks flag "nothing changes" tidak akurat; jendela deploy antara checkout dan `migrate` dapat membuat pembayaran 500 (rollback, uang tidak salah catat). | **FIXED (teks)** + **dicatat** — deskripsi flag & rule 207 §19b jujur; **tidak** ada bypass "lewati bila tabel tidak ada" (fail-open). Deploy diverifikasi tanpa aktivitas pembayaran (§6). |
| LOW | Filter PII referensi bisa dilewati NIK yang dipisah tanda baca. | **FIXED** — digit dihitung lintas `._:/-` dalam satu rangkaian; ≥13 ditolak. Residu: nomor HP ≤12 digit tidak tertangkap (rule 207 §10a). |
| LOW | "Case-specific" tidak ditegakkan. | **FIXED** — referensi yang sudah tercatat pada koreksi POST_PAYMENT tagihan **lain** ditolak (`..._REUSED`, case-insensitive); tagihan yang sama boleh memakai ulang. Residu: dua pengajuan bersamaan pada dua tagihan berbeda tidak diserialisasi oleh constraint DB (satu akun maker). |
| LOW | Label cetak ulang (nama metode bayar, kasir) dibaca live. | **FIXED** — dibekukan di bagian `receipt.labels`. Identitas pasien **sengaja** tetap live (data pribadi yang dapat dikoreksi; juga tidak dibekukan di versi dokumen D7). Rekonstruksi membekukan label & kop cabang saat rekonstruksi berjalan (berlabel rekonstruksi). |
| LOW | Approve tidak memeriksa ulang kunjungan; `clinicVisit?->status` meloloskan null. | **FIXED** — lihat MEDIUM. |
| LOW | Tagihan PARTIAL yang dilunasi koreksi tidak punya kwitansi LUNAS. | **DICATAT** sebagai pertanyaan Owner (keputusan produk), rule 207 §19c, SOP butir 8. |
| LOW | `down()` migrasi 1 membuang kolom turunan diam-diam. | **FIXED** — menolak selama ada baris yang mencatat hasil ledger/referensi. |

Hasil: **0 CRITICAL, 0 HIGH, 0 MEDIUM yang belum diselesaikan.**

### 5.2 Mutasi

27 mutan pada kode produksi (bukan tes), masing-masing dijalankan terhadap suite
Lifecycle + Access; pemulihan dengan salinan berkas + verifikasi sha256.

**Hasil akhir: 27 KILLED, 0 SURVIVED, 0 NOT-APPLIED.**

| Mutan | Hasil |
|---|---|
| M01 cek flag post-payment saat submit dihapus · M02 syarat referensi dihapus · M03 cek flag saat approve dihapus | KILLED |
| M04 `>=` → `>` di ledger · M05 kewajiban tidak dibuka · M17 jumlah kewajiban = sisa tagihan · M19 status tidak ditulis | KILLED |
| M06 rekonstruksi dilewati · M07 penerbitan snapshot kwitansi dihapus · M10 snapshot beku tidak dipakai · M11 cek integritas dihapus · M16 filter pembayaran snapshot dihapus | KILLED |
| M08 guard kewajiban OPEN saat submit · M09 guard lab · M12 staleness jumlah pembayaran · M13 penolakan recovery · M14 konsistensi ledger · M15 tipe selalu PRE_PAYMENT | KILLED |
| M18 guard digit dihapus · M26 digit tidak dihitung lintas pemisah · M22 guard pemakaian ulang referensi | KILLED |
| M21 guard kunjungan COMPLETED · M23 staleness status kunjungan · M25 entri registry merge dihapus | KILLED |
| M24 label metode bayar beku diabaikan · M27 label kasir beku diabaikan | KILLED |
| M20 staleness kewajiban OPEN saat approve | **SURVIVED → KILLED** setelah tes defence-in-depth ditambahkan (§4) |

Dua cacat pada harness ditemukan dan diperbaiki, tidak ditutupi:
1. M20 awalnya selamat karena tidak ada jalur layanan yang bisa membuka kewajiban
   selama koreksi aktif — pemeriksaannya defence-in-depth. Tes baru menyisipkan
   kewajiban langsung (pola "payment written past D10").
2. M27 mengubah template Blade; pemulihan dengan `copy2` mempertahankan mtime lama,
   sehingga Blade terus memakai view kompilasi mutan dan run berikutnya (termasuk
   run ulang M20) terkontaminasi. Harness kini `utime` + `view:clear` setelah setiap
   pemulihan; M20 dan M27 dijalankan ulang bersih (masing-masing 1 kegagalan yang
   tepat).

## 6. CI, merge, deploy, verifikasi

Diisi saat penutupan.

## 7. Keputusan

Diisi saat penutupan.

## 8. Roadmap program (7 sprint)

Diisi saat penutupan.

## 9. Pembersihan

Diisi saat penutupan.
