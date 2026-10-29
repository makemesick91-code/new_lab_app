---
kb_status: snapshot
kb_verified_against: 500f8be3
kb_updated: 2026-10-30
---

# CI-PERF-02 — Isolasi Bukti Test (2026-10-30)

Laporan isolasi bukti (evidence) yang ditulis oleh test, sebagai prasyarat test paralel. Kembali ke
[00-INDEX](../../00-INDEX.md). Halaman terkait:
[CICD-PARALLEL-TESTING-BENCHMARK](CICD-PARALLEL-TESTING-BENCHMARK.md),
[CICD-PHASE2-FINAL-SUMMARY](CICD-PHASE2-FINAL-SUMMARY.md).

**Keputusan: GO** untuk perubahan test (PR #556, squash-merge `500f8be3`). Mode paralel **tidak** diaktifkan
di CI oleh perubahan ini.

## Apa yang diaudit

Semua test yang membuat atau membaca bukti rilis (release evidence), bukti uji restore, dan manifest
rollback, beserta kode aplikasi yang menulisnya (`ReleaseEvidenceService`, `release:evidence-check`,
`rollout:restore-drill-evidence`, `clinical-evidence:migrate-public`).

## Temuan

| # | Temuan | Dampak | Bukti |
|---|---|---|---|
| 1 | Lima suite restore-drill menulis ke direktori bukti **kanonik** `storage/app/readiness/restore-drills`; satu di antaranya menulis dan menghapus `latest.json` kanonik | Suite rollout-readiness membaca file yang sama; hasilnya bergantung urutan, dan menjadi race saat paralel | Pembacaan kode lima suite |
| 2 | `ClinicalEvidencePrivacyTest` menjalankan `clinical-evidence:migrate-public`, yang menulis manifest ke `storage/app/clinical-evidence-migration` | **6 manifest bocor per run** suite di base; test membaca `end(glob(...))` dari direktori bersama | Diukur: run suite base meninggalkan 6 file, dibersihkan per nama |
| 3 | 13 suite memakai satu direktori tetap di `storage/framework/testing` | Dua worker paralel yang menjalankan suite yang sama akan saling menghapus bukti | Pembacaan kode |
| 4 | `php artisan test --parallel` **tidak bisa dimulai sama sekali** | Pernyataan `use RuntimeException;` tanpa namespace memicu warning compile; loader ParaTest mengubahnya menjadi `ErrorException` | Direproduksi |
| 5 | Pemanggil `ReleaseEvidenceService::capture()` di test sudah mengalihkan direktori | Tidak ada test yang menulis direktori bukti kanonik `storage/ci-evidence` atau `storage/release-evidence` | Pemindaian seluruh `tests/` |

Catatan: `Storage::fake()` sudah aman untuk paralel karena root-nya diberi akhiran token worker oleh
Laravel. ParaTest secara default membagi pekerjaan **per file**; dengan `--functional` pembagian dilakukan
per test.

## Perbaikan (test saja, tanpa perubahan aplikasi)

| Persyaratan | Cara dipenuhi |
|---|---|
| Direktori sementara unik per proses test | Helper `isolatedTestStoragePath()` menambahkan token worker ParaTest (`-p3`) |
| Tidak ada overwrite lintas proses | 13 suite memakai helper; suite restore-drill memakai direktori privat per file; manifest klinis memakai root storage per test |
| Tidak ada penulisan ke path produksi/kanonik | Suite restore-drill dialihkan lewat kunci konfigurasi yang sudah ada; default kanonik kini dikunci oleh assertion, bukan ditulis |
| Tidak ada kebocoran bukti antar-test | Setiap test manifest klinis hanya melihat manifest-nya sendiri; test manifest kini menuntut tepat satu |
| Hasil deterministik | Dua run paralel 4 worker menghasilkan angka identik |
| Cleanup idempoten | Cleanup menghapus direktori persis yang dimiliki, bukan glob prefix; aman dijalankan ulang |
| Kontrak bukti dan verdict tata kelola tidak berubah | Tidak ada service, konfigurasi, atau command yang diubah; jalur serial memakai path yang sama persis |

Helper menolak path di luar `storage/framework/testing/`, termasuk direktori bukti kanonik, root testing
itu sendiri, traversal, dan path absolut.

## Penjaga regresi

`TestEvidenceIsolationContractTest` (11 kasus), terdaftar di `critical_gate_mandatory_suites` dan dipilih
token `Cicd` di kedua varian critical gate:

- perilaku helper di bawah token worker simulasi (tanpa token: path tetap; empat token: empat path berbeda);
- penolakan path terlarang;
- pemindaian statis `tests/`: tidak ada pemanggilan filesystem ke direktori bukti kanonik, dan setiap
  literal direktori test tetap melewati helper;
- suite manifest klinis tetap memakai root storage per test.

Mutasi: **5 dari 5 terbunuh** (helper mengabaikan token, helper menerima semua path, satu suite kembali
ke literal, suite restore-drill kembali ke direktori kanonik, manifest kembali ke root bersama).

## Hasil

| Pemeriksaan | Hasil |
|---|---|
| Suite yang diubah, serial | 261 lulus / 2 skip — identik dengan base (+1 assertion: default kanonik yang dikunci) |
| Suite yang diubah, **4 worker paralel, dua kali** | 294 lulus / 2 skip / 1.022 assertion, **identik di kedua run** |
| Direktori worker yang tersisa | 0 |
| Direktori bukti kanonik restore-drill | Tidak tersentuh |
| `tests/Feature/Cicd` | 286 lulus |
| CI PR #556, run 40939517489 (tree `ac720b8b`) | Critical 7.010 lulus / 39.119 assertion, exit 0, warning 0; semua gate wajib hijau |
| CI push merge `500f8be3`, run 40947344916 | Critical 7.010 lulus / 39.119 assertion; semua gate wajib hijau; Full Suite SKIPPED (kebijakan) |
| Identitas tree | Tree `500f8be3` = tree `ac720b8b` yang diuji CI (`d61ec142`) |

## Risiko tersisa

- Isolasi ini menutup penulisan bukti oleh test. Ia tidak membuat setiap test di repository aman untuk
  paralel; itu diukur terpisah di benchmark paralel, yang menemukan dua cacat lain (helper test yang dipinjam
  lintas file, dan lock table PostgreSQL saat database worker dipakai ulang).
- Test yang ditulis di masa depan bisa menambah literal direktori tetap. Penjaga statis akan menolaknya di
  critical gate.
