---
kb_status: snapshot
kb_verified_against: 52b7a486
kb_updated: 2026-10-30
---

# Ringkasan Akhir Rilis Produksi & CI/CD Fase 2 (2026-10-30)

Ringkasan keputusan, bukti, perubahan, hasil regresi dan risiko tersisa untuk brief "Production Release &
CI/CD Phase 2" (2026-10-29). Kembali ke [00-INDEX](../../00-INDEX.md).

## Keputusan per tahap

| Tahap | Keputusan | Bukti utama | Laporan |
|---|---|---|---|
| 1. Rilis produksi F-4b/F-4c | **GO — LIVE** `fb3726c5` sejak 2026-10-30 01:08 WITA; tag `post-audit-f4bc-release-1-go` | Gate wajib hijau pada SHA kandidat, deploy resmi `DEPLOY OK`, verifikasi pasca-deploy lulus | [F4BC-PRODUCTION-RELEASE-REPORT](F4BC-PRODUCTION-RELEASE-REPORT.md) |
| 2. Kesiapan G7-B | **READY_FOR_OWNER_APPROVAL.** Rotasi APP_KEY produksi **tidak diotorisasi dan tidak dilakukan** | Lihat laporan | [G7B-FINAL-READINESS-REPORT](G7B-FINAL-READINESS-REPORT.md) |
| 3a. CI-PERF-01 profiling + snapshot seed | **GO — merge `ec7f1c7c`** (PR #555) | Step critical −34% dan modul selektif −84% di CI terhadap pembanding sejajar | [CICD-SLOW-TEST-PROFILING](CICD-SLOW-TEST-PROFILING.md) |
| 3b. CI-PERF-02 isolasi bukti test | **GO — merge `500f8be3`** (PR #556) | 13 suite + 5 suite restore-drill terisolasi; penjaga 11 kasus; mutasi 5/5 | [CICD-EVIDENCE-ISOLATION-REPORT](CICD-EVIDENCE-ISOLATION-REPORT.md) |
| 3c. CI-PERF-03 test paralel | Perbaikan keamanan-paralel **GO — merge `52b7a486`** (PR #558). Paralel sebagai default: **NO-GO** | 14 suite (88 test) gagal bila dijalankan sendiri → 0; benchmark CI 9/9 job hijau pada 1/2/4 worker; percepatan median 2,21× pada 4 worker | [CICD-PARALLEL-TESTING-BENCHMARK](CICD-PARALLEL-TESTING-BENCHMARK.md) |
| 4. Pembaruan vault Obsidian | **BLOCKED** — Obsidian sedang berjalan dengan vault terbuka | Aturan brief: jangan memperbarui vault bila Obsidian berjalan | — |

Perubahan CI/CD Fase 2 hanya menyentuh `tests/` dan registry suite wajib di `config/ci_runner.php`. Tidak ada
kode aplikasi, migrasi, atau workflow gate yang berubah, sehingga tidak ada deploy produksi untuk tahap 3.

## Target durasi

Baseline: median 7 run, workflow 7.359 s, step critical 6.994 s, 173,9 menit runner
([CICD-PHASE2-BASELINE](CICD-PHASE2-BASELINE.md)).

| Target | Status | Bukti |
|---|---|---|
| Tahap 1: workflow < 90 menit | **Tercapai**, 6 dari 6 run | 2.589–3.849 s |
| Tahap 2: workflow ≤ 60 menit | **Belum konsisten**, 5 dari 6 run | Satu run push 3.849 s |

Median enam run sesudah perubahan: workflow 2.945 s (−60,0%), step critical 2.602 s (−62,8%), 66,4 menit
runner (−61,8%). Jumlah test critical naik dari 6.999 ke 7.015; tidak ada test yang dihapus, dilewati atau
dilemahkan.

## Hasil regresi

| Pemeriksaan | Hasil |
|---|---|
| Critical gate di setiap PR dan push merge (#555, #556, #558) | Hijau; 6.999 → 7.010 → 7.015 test, warning 0 |
| Selective Module Gate (Inventory, Lab, Ui, Permission) | Hijau di setiap run; Inventory 1.608 / 8.424 assertion tidak berubah |
| NSF-9, NSF-10, NSF-R012 quality gate | Hijau di setiap run |
| Identitas tree merge | Setiap tree merge diuji CI: `ec7f1c7c` langsung oleh push run 40933218576 (base sudah maju satu commit docs); tree `500f8be3` = `ac720b8b` dan `52b7a486` = `a535b053`, keduanya diuji di PR-nya |
| Penjaga baru | `AccessControlSeedSnapshotTest`, `TestEvidenceIsolationContractTest` (11 kasus, mutasi 5/5), `ParallelTestHelperResolutionContractTest` (5 kasus, mutasi 6/6); ketiganya terdaftar di `critical_gate_mandatory_suites` |
| Paralel, sesudah perbaikan | CI: 9/9 job hijau (3 attempt × 1/2/4 worker). Lokal: 2/2 run critical 4 worker, 0 gagal |
| Full Suite | **SKIPPED** oleh GLOBAL TEMPORARY FULL-SUITE POLICY (`FULL_SUITE_NOT_ENABLED_FOR_EVENT`). Tidak dijalankan, tidak dinyatakan lulus |

Tidak ada test yang dihapus, dinonaktifkan, dilewati atau dilemahkan. Coverage suite wajib bertambah.

## Risiko tersisa

- **Full Suite tidak dijalankan** (kebijakan aktif). Perubahan tahap 3 hanya menyentuh `tests/` dan registry
  `config/ci_runner.php`; critical gate dan modul selektif adalah bukti yang tersedia. Risiko: regresi di
  suite yang tidak terpilih gate mana pun tidak akan terlihat sampai Full Suite berikutnya.
- **Variasi durasi CI besar.** Model CPU runner berbeda antar-job; target tahap 2 belum konsisten.
- **Runner self-hosted dan paralel.** PostgreSQL persisten membuat `migrate:fresh` serentak di database worker
  lama menghabiskan lock table (SQLSTATE 53200). Belum relevan selama paralel tidak diaktifkan.
- **Vault Obsidian belum diperbarui** dan masih menunjuk `fb3726c5`.
- **G7-B:** rotasi APP_KEY produksi tetap NO-GO; risiko yang tercatat di laporan G7-B tetap berlaku.

## Keputusan yang menunggu owner

1. **G7-B:** persetujuan rotasi APP_KEY produksi dan penetapan custodian kedua (tidak diputuskan oleh agen).
2. **CI-PERF-04 (usulan):** mengaktifkan critical gate paralel di kedua varian secara identik, dengan syarat di
   [CICD-PARALLEL-TESTING-BENCHMARK](CICD-PARALLEL-TESTING-BENCHMARK.md#keputusan).
3. **Full Suite:** kapan kebijakan global sementara mengizinkan satu run otoritatif.
4. **Vault Obsidian:** perbarui ke SHA dokumen final setelah Obsidian ditutup.
