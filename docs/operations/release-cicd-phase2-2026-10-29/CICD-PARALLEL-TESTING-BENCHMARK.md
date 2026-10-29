---
kb_status: snapshot
kb_verified_against: 52b7a486
kb_updated: 2026-10-30
---

# CI-PERF-03 — Benchmark Test Paralel (2026-10-30)

Pengujian terkendali `php artisan test --parallel` pada 1, 2 dan 4 worker, dengan database terpisah per
worker. Kembali ke [00-INDEX](../../00-INDEX.md). Halaman terkait:
[CICD-EVIDENCE-ISOLATION-REPORT](CICD-EVIDENCE-ISOLATION-REPORT.md),
[CICD-PHASE2-BASELINE](CICD-PHASE2-BASELINE.md),
[CICD-PHASE2-FINAL-SUMMARY](CICD-PHASE2-FINAL-SUMMARY.md).

**Keputusan:**

- **GO** untuk perbaikan keamanan-paralel PR #558 (test saja), merge `52b7a486`.
- **NO-GO** untuk mengaktifkan mode paralel sebagai default di critical gate saat ini. Alasannya di bagian
  [Keputusan](#keputusan).

## Cara mengukur

- Stack: Laravel 12, Pest 3.8.6, ParaTest 7.8.5. Mode paralel membagi pekerjaan **per file**. Setiap
  worker memakai database sendiri (`testing_test_N`), dibuat oleh Laravel.
- Host lokal: Intel i7-7700 (4 core / 8 thread), RAM 15 GB, PostgreSQL 16 di container `postgres:16`
  (`max_connections` 100, `max_locks_per_transaction` 64; nilai bawaan, sama dengan service CI).
- Dua beban kerja:
  - **Slice RME**: `tests/Feature/RME`, 2.038 test.
  - **Critical**: filter NSF-R011 yang persis sama dengan critical gate (95 token), 7.010 test.
- Sampler setiap 2 detik mencatat RSS PHP, memori container PostgreSQL, CPU sibuk, load, koneksi
  PostgreSQL aktif dan backend yang menunggu lock. Skrip dan CSV per run disimpan sebagai bukti di
  luar repository.
- Runner CI GitHub (`ubuntu-latest`, repository publik): **terukur 4 vCPU / 15,98 GB** di setiap job benchmark CI.
  Hasilnya ada di bagian [Pengukuran di CI](#pengukuran-di-ci).

## Hasil lokal

### Critical filter (7.010 test)

| Worker | Run | Wall (s) | Percepatan | RSS PHP puncak (MB) | CPU sibuk rata-rata / puncak (%) | Sampel dengan tunggu lock | Hasil |
|---:|---|---:|---:|---:|---:|---:|---|
| 1 | crit-w1-v2 | 2.792,4 | 1,00× | 1.453 | 17,3 / 27 | 49 / 888 (5,5%) | 7.005 lulus, 5 skip, **0 gagal** |
| 2 | crit-w2-rc1 | 1.594,2 | 1,75× | 1.899 | 31,6 / 42 | 48 / 505 (9,5%) | 7.003 lulus, 5 skip, 2 gagal |
| 4 | crit-w4-rc1 | 1.071,2 | 2,61× | 2.254 | 52,3 / 70 | 33 / 336 (9,8%) | 7.003 lulus, 5 skip, 2 gagal |
| 4 | crit-w4-rc2 | 1.052,7 | 2,65× | 2.252 | 52,7 / 71 | 45 / 330 (13,6%) | 7.003 lulus, 5 skip, 2 gagal |
| 4 | crit-w4-rc3 | 1.016,4 | 2,75× | 2.258 | 51,4 / 69 | 39 / 320 (12,2%) | 7.003 lulus, 5 skip, 2 gagal |

Setelah perbaikan PR #558 (tree `a535b053`, database worker di-drop sebelum setiap run):

| Worker | Run | Wall (s) | Percepatan | RSS PHP puncak (MB) | CPU sibuk rata-rata / puncak (%) | Sampel dengan tunggu lock | Hasil |
|---:|---|---:|---:|---:|---:|---:|---|
| 4 | crit-w4-fix1 | 1.082,6 | 2,58× | 2.234 | 52,5 / 72 | 38 / 340 (11,2%) | 7.010 lulus, 5 skip, **0 gagal** |
| 4 | crit-w4-fix2 | 1.050,7 | 2,66× | 2.231 | 53,1 / 71 | 37 / 330 (11,2%) | 7.010 lulus, 5 skip, **0 gagal** |

- Rata-rata 4 worker sebelum perbaikan: **1.046,8 s, 2,67× lebih cepat** dari serial; sebaran tiga run 5,2%.
  Sesudah perbaikan: 1.066,7 s (2,62×), dengan **0 kegagalan** di kedua run.
- Jumlah test identik di semua mode (7.010). Lima skip lokal adalah test yang bergantung pada
  lingkungan host; di CI kelimanya berjalan (7.010 lulus).
- Dua kegagalan paralel identik di **keempat** run paralel (lihat Temuan 1). Selain dua itu, **tidak ada**
  kegagalan lain di 28.040 eksekusi test paralel.
- Tunggu lock PostgreSQL juga ada di serial (5,5% sampel): sebagian test sengaja membuka koneksi kedua
  untuk membuktikan row lock. Paralel menaikkannya ke 9,5–13,6% sampel; puncak backend yang menunggu
  tetap kecil (maksimum 3).
- Memori container PostgreSQL naik dari run ke run (1.107 → 1.739 MB) karena mencakup page cache yang
  terakumulasi. Angka itu tidak dibandingkan antar-mode.

### Slice RME (2.038 test)

| Worker | Run | Wall (s) | Percepatan | RSS PHP puncak (MB) | CPU rata-rata (%) | Database worker | Hasil |
|---:|---|---:|---:|---:|---:|---|---|
| 1 | rme-w1-r1 | 536,9 | 1,00× | 561 | 21,2 | — | 2.038 lulus |
| 2 | rme-w2-r1 | 309,4 | 1,74× | 796 | 34,6 | dipakai ulang | 2.038 lulus |
| 2 | rme-w2-r2 | 317,5 | 1,69× | 791 | 34,3 | dipakai ulang | 2.038 lulus |
| 4 | rme-w4-r1 | 197,6 | 2,72× | 1.070 | 60,5 | 2 dari 4 dipakai ulang | 2.038 lulus |
| 4 | rme-w4-r2 | 156,6 | — | 1.092 | 55,9 | 4 dari 4 dipakai ulang | **1.436 gagal** (SQLSTATE 53200) |
| 4 | rme-w4-rc1 | 131,3 | — | 934 | 56,3 | `--recreate-databases` | **1.996 gagal** (SQLSTATE 53200) |
| 4 | rme-w4-ddl | 150,8 | — | 1.093 | 51,7 | `--recreate-databases` + log DDL | **1.049 gagal** (SQLSTATE 53200) |
| 4 | rme-w4-dropdb1 | 193,1 | 2,78× | 1.076 | 65,3 | di-drop eksplisit | 2.038 lulus |
| 4 | rme-w4-dropdb2 | 206,1 | 2,61× | 1.069 | 66,2 | di-drop eksplisit | 2.038 lulus |

## Temuan

### 1. Helper test dipinjam lintas file (cacat laten, deterministik)

Pest memuat **semua** file test sebelum run serial dimulai, sehingga file yang memanggil helper yang
dideklarasikan di file `*Test.php` lain lulus secara kebetulan. Worker paralel hanya memuat file yang
diberikan kepadanya; panggilan yang sama menjadi `Call to undefined function`.

- Di critical: `DoctorDeviceAndroidDirectApkReleaseTest` baris 417 dan 428 memanggil `androidCheck()`
  yang dideklarasikan di `DoctorDeviceAndroidReleaseGovernanceTest`. Gagal di 4 dari 4 run paralel.
- Pemindaian tokenizer atas seluruh `tests/` menemukan pola yang sama di 14 suite: 13 suite Inventory
  meminjam tujuh helper goods-receipt dari `GoodsReceiptServiceTest` dan `GoodsReceiptBatchTest`, plus
  suite direct-APK di atas. Inventory tidak masuk critical filter, sehingga belum terlihat di benchmark.
- Dibuktikan dengan menjalankan setiap file **sendirian** di prosesnya sendiri (PostgreSQL 16,
  lingkungan setara CI):

| Tree | Suite yang gagal sendirian | Test gagal | Penyebab |
|---|---:|---:|---|
| Base `500f8be3` | 14 dari 17 | 88 | 88 dari 88 adalah `Call to undefined function` |
| Setelah perbaikan (PR #558) | **0** dari 17 | **0** | — (235 test di kedua tree) |

### 2. Lock table PostgreSQL habis saat beberapa worker memigrasi ulang database lama (SQLSTATE 53200)

- `RefreshDatabase` menjalankan `migrate:fresh` sekali per worker. Pada database worker yang **sudah
  ada** dari run sebelumnya, perintah itu menjalankan **satu** `DROP TABLE` atas sekitar 190 tabel.
  Empat worker yang mulai bersamaan mengeluarkan empat DROP sekaligus, melebihi kapasitas lock table
  bersama (64 × 100 slot), dan PostgreSQL menjawab `out of shared memory`.
- Log DDL PostgreSQL membuktikan urutannya: 1,2 detik setelah start, empat backend mengirim DROP yang
  sama dalam rentang 27 ms, lalu keempatnya gagal pada milidetik yang sama.
- Kegagalan itu berantai: `RefreshDatabaseState::$migrated` tetap `false`, sehingga setiap test berikutnya
  di worker itu mengulang DROP dan bertabrakan lagi (1.049–1.996 test gagal per run).
- **`--recreate-databases` tidak berpengaruh** pada stack ini: log DDL tidak berisi satu pun
  `DROP DATABASE`/`CREATE DATABASE`. Mekanisme internal penyebabnya tidak diisolasi lebih jauh.
- Konsisten dengan penjelasan itu: sebelum `rme-w4-r1` hanya dua database worker yang sudah ada (dari
  smoke run 2 worker), sehingga hanya dua DROP bersamaan, dan run itu lulus.
- **Mitigasi terbukti:** men-drop `testing_test_1..N` secara eksplisit sebelum run. Dua dari dua run
  RME 4 worker lulus 2.038/2.038.
- **Lingkup risiko:** service `postgres:16` di runner GitHub dibuat baru per job, sehingga database
  worker belum ada dan DROP pertama tidak terjadi. Risiko ini nyata untuk PostgreSQL yang persisten:
  mesin developer dan **runner self-hosted** (PostgreSQL 16 native). Di critical filter, urutan file
  membuat worker mulai DROP pada waktu berbeda (0 dari 6 run critical paralel terkena), tetapi ketergantungan
  pada urutan bukan jaminan.

### 3. Vendor lokal yang basi memalsukan 109 kegagalan

Dua run critical pertama gagal 109 test (WebAuthn: `Class ParagonIE\…\Base64UrlSafe not found`,
`CBOR\MapObject not found`). Penyebabnya `vendor/` checkout lokal yang tertinggal dari `composer.lock`,
bukan mode paralel. Setelah `composer install`, suite yang sama lulus. Kedua run itu dikeluarkan dari
semua angka di atas.

## Pengukuran di CI

Eksperimen di draft PR #557 (**tidak di-merge**, ditutup setelah pengukuran): workflow sekali pakai yang
menjalankan filter critical yang persis sama (95 token, dibaca saat runtime dari workflow gate) pada
`ubuntu-latest` + service `postgres:16`, dengan environment job yang sama dengan critical gate. Tree: perbaikan
PR #558 (`a535b053`) ditambah workflow eksperimen. Run 40963287675, tiga attempt, masing-masing tiga job
paralel (1, 2, 4 worker). Runner terukur: **4 vCPU, 15,98 GB**; PostgreSQL `max_connections` 100,
`max_locks_per_transaction` 64.

| Attempt | Worker | CPU runner | Step test (s) | CPU rata-rata / puncak (%) | Memori terpakai puncak (MB) | RSS PHP puncak (MB) | Sampel tunggu lock | Hasil |
|---:|---:|---|---:|---:|---:|---:|---:|---|
| 1 | 1 | AMD EPYC 9V45 | 1.862 | 24,4 / 33 | 2.612 | 1.462 | 74 / 907 (8,2%) | 7.015 lulus |
| 2 | 1 | AMD EPYC 9V45 | 1.897 | 24,5 / 47 | 2.593 | 1.460 | 72 / 924 (7,8%) | 7.015 lulus |
| 3 | 1 | AMD EPYC 9V45 | 1.884 | 24,4 / 43 | 2.616 | 1.461 | 73 / 918 (8,0%) | 7.015 lulus |
| 1 | 2 | AMD EPYC 7763 | 1.504 | 51,0 / 73 | 3.035 | 1.935 | 68 / 719 (9,5%) | 7.015 lulus |
| 2 | 2 | AMD EPYC 9V45 | 991 | 47,8 / 69 | 3.056 | 1.951 | 70 / 481 (14,6%) | 7.015 lulus |
| 3 | 2 | AMD EPYC 9V45 | 932 | 47,4 / 69 | 3.019 | 1.934 | 66 / 452 (14,6%) | 7.015 lulus |
| 1 | 4 | Intel Xeon Platinum 8573C | 793 | 77,3 / 100 | 3.381 | 2.301 | 60 / 381 (15,7%) | 7.015 lulus |
| 2 | 4 | AMD EPYC 7763 | 1.095 | 82,1 / 100 | 3.326 | 2.309 | 60 / 515 (11,7%) | 7.015 lulus |
| 3 | 4 | AMD EPYC 9V74 | 852 | 77,2 / 99 | 3.348 | 2.306 | 59 / 407 (14,5%) | 7.015 lulus |

| Worker | Median step test (s) | Percepatan (median) | Median durasi job = menit runner | Kegagalan |
|---:|---:|---:|---:|---:|
| 1 | 1.884 | 1,00× | 32,3 | 0 dari 3 run |
| 2 | 991 | 1,90× | 17,4 | 0 dari 3 run |
| 4 | 852 | **2,21×** | **15,1** | 0 dari 3 run |

- **9 dari 9 job hijau**, 7.015 test (7.010 + 5 penjaga baru) di setiap job, **0 kegagalan, 0 flaky**.
- **CPU runner menentukan.** Job yang sama mendarat di empat model CPU berbeda. Pada CPU yang sama: 2 worker
  di EPYC 9V45 1,96× lebih cepat dari serial; 4 worker di EPYC 7763 hanya 1,37× lebih cepat dari 2 worker di
  CPU yang sama. Angka median di atas mencampur model CPU dan harus dibaca sebagai perkiraan.
- **4 worker menjenuhkan 4 vCPU** (puncak 99–100%), sehingga tambahan dari 2 ke 4 worker kecil dan bervariasi.
  Memori tidak menjadi batas: puncak 3,4 GB dari 16 GB.
- Menit runner turun sebanding dengan durasi job, karena satu job tetap memakai satu runner.
- Durasi serial di sini (1.862–1.897 s) lebih pendek dari step critical gate yang sesungguhnya (2.247–3.472 s
  pada run gate sesudah perubahan). Ketiga job serial kebetulan mendarat di CPU tercepat, dan job gate tidak
  memilih CPU. Ini contoh lain bahwa satu run tidak cukup sebagai bukti durasi.
- **Proyeksi, bukan hasil gate:** di run gate PR #558, critical job = step test + 67 s dan workflow = critical
  job + 275 s. Dengan step 850–1.100 s pada 4 worker, critical job menjadi sekitar 15–20 menit dan workflow
  sekitar 20–24 menit (sekarang 43–64 menit). Angka itu baru boleh diklaim setelah gate yang sesungguhnya
  mengukurnya.

## Keputusan

**Perbaikan keamanan-paralel (PR #558): GO.** Test saja, tidak mengubah gate. Penjaga baru dipilih token `Cicd`
di kedua varian critical dan terdaftar di `critical_gate_mandatory_suites`.

**Paralel sebagai default critical gate: NO-GO untuk sprint ini.** Hasil di atas menunjukkan paralel stabil
dan jauh lebih cepat setelah perbaikan, tetapi empat syarat belum terpenuhi:

1. **Bukti berulang di tree yang di-merge.** Benchmark CI berjalan di draft PR eksperimen. Aktivasi
   membutuhkan beberapa run hijau berturut-turut dari workflow gate itu sendiri, pada SHA yang di-merge.
2. **Kesetaraan dua varian critical.** Aturan 91 mewajibkan varian GitHub-hosted dan self-hosted identik.
   Runner self-hosted memakai PostgreSQL 16 native yang persisten, sehingga Temuan 2 berlaku di sana.
   Diperlukan langkah provisioning yang men-drop `testing_test_1..N` (terbukti di sini) atau menaikkan
   `max_locks_per_transaction`, di kedua varian.
3. **Kontrak warning dan bukti rilis.** `ci:assert-critical-gate-warning-contract` (baseline 0) dan
   pengambilan bukti NSF-10 membaca log critical. Format output paralel berbeda (tanpa durasi per test).
   Kesesuaiannya belum diverifikasi, dan profiling per-test di CI akan butuh `--log-junit`.
4. **Keputusan owner.** Mengubah workflow gate adalah perubahan CI yang diatur tata kelola; brief ini
   melarang mengaktifkan paralel sebelum stabilitas terbukti berulang.

Rekomendasi: sprint lanjutan bernama (usulan `CI-PERF-04`) yang mengubah kedua varian critical secara
identik di balik variabel repository (default 1 worker), menambah provisioning database worker, memvalidasi
kontrak warning dan bukti NSF-10, lalu meminta keputusan owner berdasarkan beberapa run berturut-turut.

## Risiko tersisa

- **Runner GitHub heterogen.** Job yang sama mendarat di CPU berbeda (AMD EPYC 9V45, AMD EPYC 7763, Intel Xeon
  8573C). Rasio percepatan dari satu run tidak sejajar; angka di atas memakai beberapa attempt.
- **CPU jenuh pada 4 worker di CI** (puncak 100%, rata-rata 77%). Menambah worker di atas jumlah vCPU tidak
  diuji dan tidak direkomendasikan.
- **Pola helper lintas file bisa muncul lagi.** Penjaga statis menolaknya di critical gate.
- **Urutan dan pembagian file berbeda di setiap run paralel.** Cacat yang bergantung pada urutan bisa
  muncul hanya kadang-kadang. 28.040 eksekusi lokal, ditambah run CI di atas, tidak menemukan yang lain;
  itu bukti, bukan jaminan.
- **`--recreate-databases` tidak berfungsi pada stack ini.** Jangan mengandalkannya sebagai mitigasi.
