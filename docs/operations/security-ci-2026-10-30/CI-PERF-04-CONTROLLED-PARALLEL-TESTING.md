---
kb_status: snapshot
kb_verified_against: 4b838944543ed01694e695ef4c502ab2b6c72bb5
kb_updated: 2026-10-30
---

# CI-PERF-04 — Pengujian Paralel Terkendali di Critical Gate (2026-10-30)

**Keputusan: `CI_PERF_04 = IMPLEMENTATION_OR_EVALUATION_GO`, `CRITICAL_GATE_PARALLELISM = OFF`.** Mekanisme paralel dibangun,
diuji, dan dievaluasi, tetapi **tidak diaktifkan**. Kebijakan terkomit
[`critical-parallel-policy.json`](../../../.github/ci-policy/critical-parallel-policy.json) berstatus `OFF`, dan
owner belum memberikan persetujuan aktivasi. Setiap run `pull_request`, `push`, dan `schedule` tetap
menjalankan critical filter **secara serial dengan baris perintah yang persis sama** seperti sebelum
CI-PERF-04.

Kembali ke [00-INDEX](../../00-INDEX.md). Halaman terkait:
[CICD-PARALLEL-TESTING-BENCHMARK](../release-cicd-phase2-2026-10-29/CICD-PARALLEL-TESTING-BENCHMARK.md)
(CI-PERF-03), [CICD-EVIDENCE-ISOLATION-REPORT](../release-cicd-phase2-2026-10-29/CICD-EVIDENCE-ISOLATION-REPORT.md)
(CI-PERF-02), [FULL-SUITE-CONTROLLED-AUTHORITATIVE-1](FULL-SUITE-CONTROLLED-AUTHORITATIVE-1.md).

## Apa yang dibangun

| Komponen | Fungsi |
|---|---|
| `.github/ci-policy/critical-parallel-policy.json` | Satu-satunya saklar, terkomit. `OFF` = serial untuk semua event non-manual. Jumlah worker yang boleh: 1, 2, 4; maksimum 4 (runner GitHub terukur 4 vCPU). |
| `scripts/ci/resolve-critical-parallelism.sh` | Menentukan jumlah worker per run. Gagal ke arah **serial** untuk setiap ketidakpastian. Variabel repository `CI_CRITICAL_TEST_WORKERS` diabaikan selama `OFF`. |
| Input `workflow_dispatch` `critical_test_workers` (1/2/4) | Satu-satunya jalur paralel selama `OFF`: run **evaluasi** manual, berlabel `CRITICAL_PARALLELISM_EVALUATION_DISPATCH`, ditambah JUnit dan sampling sumber daya. Bukan gate merge. |
| `scripts/ci/prepare-parallel-test-databases.sh` | Menghapus `<DB_DATABASE>_test_1..N` sebelum run paralel (mitigasi SQLSTATE 53200 dari CI-PERF-03). Hanya nama itu, hanya `APP_ENV=testing`. |
| `scripts/ci/sample-critical-resources.sh` | Hanya di run evaluasi: CPU, memori, RSS PHP, koneksi dan lock-wait PostgreSQL tiap 2 detik, plus diff workspace untuk mendeteksi bukti yang bocor ke path kanonis. |
| `CriticalGateWarningContract::stripTerminalControlSequences()` | Parser kontrak warning membaca ringkasan ParaTest yang berwarna (lihat Temuan 1). |
| Kedua varian critical gate | Langkah CI-PERF-04 identik di varian GitHub-hosted dan self-hosted (aturan 91). Baris filter tidak diubah. Full Suite dan selective module gate tetap serial. |

## Temuan

### 1. Kontrak warning menolak run paralel yang hijau (NYATA, diperbaiki)

ParaTest mencetak baris ringkasan `Tests:` dengan kode warna ANSI yang dipaksa, walaupun output dialihkan
ke file. `NO_COLOR=1` dan `--colors=never` tidak berpengaruh (diuji). Hanya baris ringkasan yang berwarna;
baris per-test tidak. Akibatnya `ci:assert-critical-gate-warning-contract` tidak menemukan ringkasan dan
memutuskan **NO_GO untuk run paralel yang 100% hijau**. Ini kondisi #3 yang belum terverifikasi di
CI-PERF-03, dan ternyata benar-benar rusak.

Perbaikan: parser menghapus urutan kontrol ECMA-48 (CSI, OSC, Fe) **di memori** sebelum membaca ringkasan.

- Log bukti di disk tidak pernah ditulis ulang (dibuktikan dengan hash sebelum/sesudah di test).
- Hanya byte format yang hilang; angka dan kata tetap, jadi kegagalan atau warning yang berwarna tetap
  dihitung (`1 failed, 2 warnings` berwarna → keduanya terbaca).
- Log serial tidak memuat ANSI sama sekali (diukur: 0 byte escape), jadi gate serial tidak berubah.

### 2. Kegagalan di salah satu worker merambat ke gate

Test sengaja gagal disisipkan sementara di satu file (lalu dihapus). Run 2 worker: exit code **1**,
ringkasan `1 failed, 11 passed`, kontrak warning **NO_GO** dengan `observed_failure_count=1`.
Di CI, run `failprobe-w4` (40994289508, SHA penanda `4e441b4d` dengan satu test sengaja gagal, tidak pernah
di-merge): ringkasan `1 failed, 7052 passed (39307 assertions)`, `critical_test_exit_status=1`, langkah test
**failure**, job dan run **failure**. Gate merah lewat jalur exit code, sebelum kontrak warning dikonsultasikan
(prioritas ketat dipertahankan).

### 3. Simulasi lokal pertama saya ternyata serial (cacat alat ukur, bukan workflow)

Simulasi lokal pertama mengevaluasi keluaran resolver dengan `eval`. Baris `key=value` yang nilainya berisi
spasi (`--parallel --processes=4 ...`) diperlakukan bash sebagai prefiks perintah, sehingga argumen kosong dan
run berjalan serial. Run itu dihentikan dan tidak dipakai. Workflow tidak terkena: GitHub membaca
`$GITHUB_OUTPUT` sebagai `key=sisa baris`, dan langkah test membaca nilainya lewat environment.

## Hasil lokal (i7-7700 4c/8t, PostgreSQL 16 container, PHP 8.5 lokal)

| Mode | Wall (s) | Hasil | Kontrak warning | CPU rata-rata / puncak | RSS PHP puncak | Koneksi PG aktif puncak | Path workspace baru |
|---|---:|---|---|---:|---:|---:|---:|
| 4 worker | 1.079 | 7.047 lulus, 5 skip, 0 gagal | GO | 51,7% / 81% | 2.275 MB | 5 | 0 |
| 1 worker (serial) | 2.912 | 7.047 lulus, 5 skip, 0 gagal | GO | 19,4% / 66% | 1.461 MB | 2 | 1 (laporan ini, ditulis manual selama run — bukan dari test) |

Kesetaraan cakupan lokal (JUnit, identitas = file + nama test): **identik** — 7.052 identitas di serial dan di 4 worker, 0 hilang, 0 tambahan. Percepatan lokal 4 worker: **2,70×** (2.912 s → 1.079 s).

Lima skip lokal adalah test yang bergantung pada lingkungan host; di CI kelimanya berjalan.

## Evaluasi di CI (jalur evaluasi, bukan gate merge)

Semua run di bawah adalah `workflow_dispatch` evaluasi di runner GitHub-hosted `ubuntu-latest`
(4 vCPU, 15,98 GB) dan berlabel `CRITICAL_PARALLELISM_EVALUATION_DISPATCH`. SHA `84f1e1d2` = kandidat
interim; `db590e2c` = commit penanda evaluasi (commit kosong) di atas kepala PR final `0a79ba2b`, tree identik,
sehingga cek-nya tidak pernah menempel pada SHA kepala PR (aturan 212 §11).

| Label | Run ID | SHA | Worker | Langkah test (s) | Workflow (s) | Hasil | Kontrak | CPU rata-rata / puncak | Memori puncak | RSS PHP puncak | PG aktif puncak | Sampel lock-wait | Path workspace baru | CPU runner |
|---|---|---|---:|---:|---:|---|---|---:|---:|---:|---:|---:|---:|---|
| w1-r1 | 40994273022 | 84f1e1d2 | 1 (serial) | 2.778 | 3.135 | 7.052 lulus | GO | 26,1% / 40% | 2.596 MB | 1.484 MB | 2 | 71/1.342 | 0 | EPYC 7763 |
| w2-r1 | 40994269265 | 84f1e1d2 | 2 | 1.454 | 1.824 | 7.052 lulus | GO | 51,0% / 69% | 3.055 MB | 1.963 MB | 3 | 72/697 | 0 | EPYC 7763 |
| w2-r2 | 40994282799 | 84f1e1d2 | 2 | 1.179 | 1.463 | 7.052 lulus | GO | 47,7% / 72% | 3.125 MB | 1.974 MB | 3 | 70/573 | 0 | Xeon 6973P-C |
| w4-r1 | 40994263679 | 84f1e1d2 | 4 | 1.113 | 1.429 | 7.052 lulus | GO | 82,2% / 100% | 3.356 MB | 2.326 MB | 5 | 63/524 | 0 | EPYC 7763 |
| w4-r2 | 40994279097 | 84f1e1d2 | 4 | 973 | 1.349 | 7.052 lulus | GO | 76,0% / 99% | 3.368 MB | 2.344 MB | 5 | 65/465 | 0 | EPYC 9V74 |
| w4-r3 | 40994286008 | 84f1e1d2 | 4 | 1.116 | 1.480 | 7.052 lulus | GO | 82,1% / 100% | 3.383 MB | 2.317 MB | 5 | 60/526 | 0 | EPYC 7763 |
| final-w4 | 40995512594 | db590e2c | 4 | 1.110 | 1.392 | 7.057 lulus | GO | 82,4% / 100% | 3.356 MB | 2.338 MB | 5 | 57/522 | 0 | EPYC 7763 |
| failprobe-w4 | 40994289508 | 4e441b4d | 4 | 940 | 1.171 | **1 gagal**, 7.052 lulus | (tidak dicapai) | 80,7% / 100% | 3.363 MB | 2.325 MB | 5 | 59/448 | 0 | Xeon 8370C |

Semua run paralel: `dropped_preexisting=0` (runner baru), 0 skip, 0 flaky, 0 path bukti baru di workspace.

**Percepatan langkah test di CI (runner sama kelasnya):**

| Perbandingan | Serial | Paralel | Percepatan |
|---|---:|---:|---:|
| 4 worker vs serial evaluasi (median w4 di SHA yang sama = 1.113 s) | 2.778 s | 1.113 s | **2,50×** |
| 4 worker vs median serial historis pasca CI-PERF-01..03 (6 sampel) | 2.601,5 s | 1.113 s | 2,34× |
| 2 worker vs serial evaluasi (rata-rata 1.316,5 s) | 2.778 s | 1.316,5 s | 2,11× |
| Wall workflow 4 worker vs serial evaluasi (median 1.410,5 s) | 3.135 s | 1.410,5 s | 2,22× |

Run serial evaluasi sedikit lebih lambat dari median historis karena run evaluasi menambah JUnit dan sampler.
Sampel lock-wait PostgreSQL juga muncul pada run serial (71/1.342), jadi itu berasal dari suite konkurensi yang
memang sengaja mengunci baris, bukan dari paralelisme; tidak ada SQLSTATE 53200 dan tidak ada deadlock.

**Kesetaraan cakupan di CI (JUnit, identitas = file + nama test):** serial `w1-r1` dibandingkan dengan kelima run
paralel di SHA yang sama → **identik**, 7.052 identitas, 0 hilang, 0 tambahan. `final-w4` memuat 7.057 identitas:
semua 7.052 identitas serial ada, ditambah tepat 5 test regresi hasil review independen yang ditambahkan setelah
`84f1e1d2` (`'0004'`, dua angka overflow 20 digit, hyperlink OSC, escape CSI rusak), semuanya di
`CriticalGateControlledParallelismContractTest`. Pada tree final yang sama, run `pull_request` resmi 40995491758 (kepala `0a79ba2b`, jalur `OFF`, serial)
melaporkan `Tests: 7057 passed (39323 assertions)` dengan langkah test 2.600 s — jumlah yang sama dengan run
paralel `final-w4`.

## Gate aktivasi

| Syarat | Status | Dasar |
|---|---|---|
| `SHARD_ISOLATION` | PASS | ParaTest membagi per file; 6 run paralel (2 dan 4 worker) + 1 lokal hijau, 0 flaky; CI-PERF-03 9/9. |
| `EVIDENCE_ISOLATION` | PASS | `workspace_paths_created_by_tests=0` di 8 run evaluasi; kontrak warning GO di semua run hijau; JUnit dan log bukti tunggal di path kanonis. |
| `DATABASE_ISOLATION` | PASS | Database per worker `<DB>_test_N`, dihapus sebelum run; 0 SQLSTATE 53200; lock-wait setara dengan serial. |
| `TEST_COVERAGE_EQUIVALENCE` | PASS | Identitas JUnit identik serial vs 5 run paralel (7.052); run final hanya +5 test baru yang diketahui. |
| `FAILURE_PROPAGATION` | PASS | Lokal (2 worker) dan CI (`failprobe-w4` 40994289508): exit 1, run failure. |
| `REPEATED_STABILITY` | PASS | 4 run 4 worker + 2 run 2 worker, di 4 jenis CPU runner berbeda, semua hijau. |
| `RESOURCE_BUDGET` | PASS | Memori puncak 3,4 GB dari 16 GB; RSS PHP 2,3 GB; CPU jenuh di 4 worker (alasan batas maks 4); PG aktif puncak 5. |
| `ROLLBACK_READY` | YES | Status istirahat adalah `OFF`; jalur serial tidak berubah. Rollback aktivasi = kembalikan `status` ke `OFF` lewat PR (satu baris). |
| `AUTHORITATIVE_CI` | PASS | Run `pull_request` 40995491758 pada kepala persis `0a79ba2b`: semua gate wajib hijau; critical gate serial (`critical_test_workers=1 mode=sequential reason=CRITICAL_PARALLELISM_OFF`), kontrak warning GO. Full Suite dilewati sesuai kebijakan sementara. PR #562 di-squash-merge sebagai `4b838944` dengan tree identik dengan kepala yang diuji. |
| `OWNER_ACTIVATION_APPROVAL` | **TIDAK ADA** | Brief owner secara eksplisit tidak mengizinkan aktivasi paralel. |

## Integritas test keamanan dan keuangan

Mode paralel membagi pekerjaan per file; setiap worker memakai database sendiri. Tidak ada test yang
dikeluarkan dari filter. Suite yang menjadi perhatian brief ikut berjalan di setiap run evaluasi karena
dipilih token critical filter yang sama: otorisasi dan isolasi cabang (`ClinicVisitBranchAuthority`,
`CrossBranchKontrol`, `DailyBranch`, `BranchChange`), maker-checker (`RmeInvoiceCorrection`, `PatientMerge`),
regresi keuangan (`RmePayment`, `CashierBilling`, `RmeBillingRefund`, `RmeBillingDispute`), konkurensi
PostgreSQL (`ClinicVisitRegistrationConcurrency`, suite `*Concurrency*` di bawah token di atas), refund,
idempotensi pembayaran (`RmePayment`), snapshot kuitansi (`RmeReceiptOnePage`, `RmeInvoiceRedesign`), dan
audit (`ObservabilityTelemetry`). Jumlah test per token identik di serial `w1-r1`, paralel `w4-r1`, `w2-r1`, dan `final-w4`, semuanya lulus tanpa
skip: `ClinicVisitBranchAuthority` 42, `CrossBranchKontrol` 12, `DailyBranch` 49, `BranchChange` 24,
`RmeInvoiceCorrection` 362, `PatientMerge` 74, `RmePayment` 106, `CashierBilling` 34, `RmeBillingRefund` 34,
`RmeBillingDispute` 175, `ClinicVisitRegistrationConcurrency` 4, semua file `*Concurrency*` 124,
`RmeReceiptOnePage` 16, `RmeInvoiceRedesign` 30, `ObservabilityTelemetry` 25. Suite konkurensi PostgreSQL
berjalan (bukan skip) di setiap worker karena tiap worker memakai database PostgreSQL 16 sendiri.

## Cara mengaktifkan (belum diizinkan)

1. Owner memberi persetujuan aktivasi tertulis yang menyebut jumlah worker.
2. PR yang mengubah `status` menjadi `ACTIVE` di kebijakan, dengan CI hijau pada SHA kandidat.
3. Setel variabel repository `CI_CRITICAL_TEST_WORKERS` ke jumlah yang disetujui. Tanpa langkah 2,
   variabel ini tidak berpengaruh.
4. Pantau beberapa run berturut-turut; rollback = kembalikan `status` ke `OFF`.
