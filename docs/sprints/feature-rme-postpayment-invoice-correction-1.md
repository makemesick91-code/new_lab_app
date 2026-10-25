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

| Butir | Bukti |
|---|---|
| PR | #524, head `42ec6db9`, tree `a3f45ee2` |
| CI kandidat | run `40208895793` **success** — Classifier, NSF-R012 Quality, CICD-CTRL Selective Module, **NSF-R011 Critical** (langkah *Run critical regression tests*: **6701 passed, 34925 asersi, `critical_test_exit_status=0`**, 0 skipped; suite post-payment ikut terpilih, termasuk race PG), NSF-9, NSF-10, Phase 3 Android. Varian Critical kedua *skipped* (satu varian per mode runner). **Full Suite: SKIPPED** — kebijakan global sementara ACTIVE; **bukan PASS**. |
| Merge | squash `b7ffa92b`, tree `a3f45ee2` == tree kandidat (identik), dipin `--match-head-commit 42ec6db9`; base tidak bergerak (`572a32a9`) |
| CI pasca-merge | run `40220613612` (push base, `b7ffa92b`) **success** — critical **6701 passed / 34925 asersi**; Selective Module, NSF-9, NSF-10, Android success; Full Suite SKIPPED (kebijakan sementara — bukan PASS) |
| Fingerprint finansial pra-deploy | 2026-10-25 18:18 UTC (02:18 WITA 26-10): invoices 8 / Σ 12.548.000 / md5 `cb122125…`; items 9 / md5 `4e06827f…`; payments 9 / Σ 10.548.000 / max id 50 / md5 `6562cc3b…`; corrections 1 / md5 `57f5adfe…`; doc versions 2; lab candidates 2; disputes 0; audit max 1632; tabel baru belum ada; pembayaran 1 jam terakhir 0; sesi 30 menit terakhir hanya tamu |
| Deploy | `scripts/deploy-vps-runner.sh start` **dijalankan di VPS**, `deploy-20261025-181913`: **`exit=0`**, **`DEPLOY OK: 20261025-181915`**, `DEPLOY_HEAD_TARGET_MATCH=YES (b7ffa92b…)`, `DEPLOY_SNAPSHOT_CLEANED=YES`; 3 migrasi DONE (batch 87); smoke 6/6 GO |
| Production pasca-deploy | HEAD `b7ffa92b`, tree `a3f45ee2` == merge |
| Fingerprint finansial pasca-deploy | 18:38 UTC: **identik** pada setiap baris finansial (diff kosong); audit max tetap 1632; tabel baru ada dengan 0 baris; 6 CHECK + partial unique index ada |
| Flag | `rme.invoice_postpayment_correction enabled=false default=false via=default captured=yes` (tidak ada override env); `rme.invoice_correction` tetap `enabled=true` (pilot pra-bayar, tidak disentuh) |
| Readiness | `POSTPAYMENT_STATE=NOT_ACTIVE FLAG_ENABLED=false`; kewajiban OPEN per cabang: kosong; Decision WATCH karena satu temuan yang sudah ada sebelumnya `PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT` (maker tidak online pukul 02:39 WITA) |
| Kesehatan | `https://daengtisia.online` `/login`, `/health/live`, `/health/ready`, `/health/lb` → 200 |
| Log & antrean | `laravel.log` byte-identik (1442282, 163 ERROR) sebelum/sesudah — 0 error baru; `queue:failed` kosong |
| Penerbitan snapshot kwitansi di production | **NOT_YET_OBSERVED** — belum ada pembayaran nyata sejak deploy; tidak ada transaksi yang dibuat untuk membuktikannya |

## 7. Keputusan

```
SPRINT_C_RESULT          = IMPLEMENTATION_GO / FLAG_OFF
CODE_DEPLOYED            = YES (b7ffa92b, 2026-10-26 02:19 WITA)
FEATURE_ENABLED          = NO
REAL_OPERATOR_ACCEPTANCE = NO
FULL_FEATURE_GO          = NO
ACTIVATION               = BLOCKED (PP-10; Sprint A FULL_PREPAYMENT_PILOT_GO = NO_GO)
GO_TAG                   = feature-rme-postpayment-invoice-correction-1-implementation-go
                           (annotated, objek e29eedd8 → b7ffa92b == production HEAD;
                           dibuat setelah CI pasca-merge sukses; tag BUKAN feature GO)
D21c / D21g / D22        = PENDING (tidak disentuh)
```

Pertanyaan Owner yang tercatat (tidak diputuskan oleh sprint ini):
1. Siapa yang mengoperasikan akun 29 dan 1 pada 2026-10-25 (`RED_FLAG_UNRESOLVED`, Sprint A) — wajib terjawab sebelum aktivasi bergantung pada pasangan itu.
2. Apakah ada formulir rule 202 pra-pengajuan untuk kasus #42.
3. Apakah kasus #42 salah input atau potongan harga.
4. Apakah referensi persetujuan Owner juga diwajibkan untuk koreksi pra-bayar.
5. Kwitansi LUNAS ketika koreksi (bukan pembayaran) melunasi tagihan PARTIAL (rule 207 §19c).
6. Pengembalian kelebihan bayar (refund) — kewajiban OPEN saat ini tidak punya jalur penyelesaian (Sprint 4/7 & 5/7).

## 8. Roadmap program (7 sprint)

| Sprint | Isi | Status |
|---|---|---|
| 1/7 | OPS-OQ-1 dispute + tahan pembayaran | GO |
| 1A | peninjau cabang + bayar Kontrol saja | GO |
| 2/7 | closure pilot koreksi pra-bayar SPN4 | **NO_GO — `OPERATIONAL_OR_EVIDENCE_BLOCKED`** (Sprint A, run 2026-10-25) |
| 3/7 | koreksi pasca-bayar | keputusan PP-1…PP-10 **disetujui** (Sprint B); **implementasi selesai & ter-deploy dengan flag OFF** (sprint ini); **aktivasi diblokir** PP-10 |
| 4/7 | refund — permintaan & persetujuan | belum dimulai; D4/D5/D18 PENDING |
| 5/7 | refund — settlement | belum dimulai; D25 PENDING |
| 6/7, 7/7 | — | belum didefinisikan di repositori |

Progres tercatat: **1/7 sprint program selesai (GO)**; Sprint 3/7 berstatus
*implementasi selesai, belum aktif* — tidak dihitung selesai sampai aktivasi
diputuskan dan diverifikasi.

## 9. Pembersihan (rule 201)

| Sumber daya milik tugas | Status |
|---|---|
| Kontainer PG16 `rme-postpay-c-pg16` (+ volume) | dihapus (`docker rm -f -v`) |
| Salinan regresi terisolasi, harness mutasi, berkas JUnit/log, SQL fingerprint, draf PR | dihapus per path; scratchpad kosong |
| Branch `feature/rme-postpayment-invoice-correction-1` | dihapus lokal & remote setelah merge |
| Worktree `rme-postpayment-correction-1` + branch evidence ini | dihapus setelah commit evidence ini di-merge |
| Proses test/CI/deploy milik tugas | tidak ada yang tersisa (tanpa proses php; runner deploy selesai `exit=0`) |
| Production | tidak ada file/secret/backup yang dibuat oleh tugas; `storage/runtime-home/` (untracked) bukan milik tugas dan tidak disentuh |

Commit evidence ini **dokumen saja** dan **tidak di-deploy** (runtime production tetap
`b7ffa92b`, sesuai aturan "jangan deploy bila hanya dokumen yang berubah").
