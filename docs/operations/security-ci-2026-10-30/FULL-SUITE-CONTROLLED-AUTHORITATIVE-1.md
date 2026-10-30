---
kb_status: snapshot
kb_verified_against: 23868da0
kb_updated: 2026-10-30
---

# Full Suite Otoritatif Terkendali — Satu Run yang Diizinkan Owner (2026-10-30)

**Hasil: `FULL_SUITE=PASS`.** Langkah `Run full Pest suite` benar-benar dieksekusi dan selesai dengan
**0 kegagalan**: `1 risky, 5 skipped, 11953 passed (63231 assertions)`. Otorisasi owner untuk satu run
telah **terpakai**. Run berikutnya membutuhkan otorisasi baru.

Kembali ke [00-INDEX](../../00-INDEX.md). Halaman terkait:
[G7B-SECURITY-GATE-CLOSURE](G7B-SECURITY-GATE-CLOSURE.md).

## Otorisasi

| Item | Nilai |
|---|---|
| Pemberi otorisasi | Owner, brief "SECURITY & CI PERFORMANCE EXECUTION" 2026-10-30, Fase 2 |
| Cakupan | **Tepat satu** Full Suite terkendali di bawah kebijakan sementara yang masih ACTIVE |
| Input workflow | `run_full_suite=true`, `full_suite_policy_override=true` (kontrak `.github/ci-policy/full-suite-policy.json`, tidak diubah) |
| Alasan override | `TEMPORARY_FULL_SUITE_POLICY_ACTIVE` |
| Keputusan resolver di job classify | `full_suite_authorized=true`, `full_suite_defer_reason=AUTHORISED_CONSOLIDATED_FULL_SUITE`, `policy_status=ACTIVE`, `gate_profile=unknown_high_risk` |
| Kebijakan sementara | Tetap **ACTIVE**. Run ini tidak mempensiunkannya. |

## Prasyarat yang diverifikasi sebelum dispatch

| Prasyarat | Hasil |
|---|---|
| SHA terintegrasi final | `23868da05389dfc89f8bec4f2bf4ebc80a34c1e2` = ujung base kanonik setelah merge G7-B (#560) |
| Semua perbaikan sebelumnya ter-merge | Ya: F-4b/F-4c, CI-PERF-01..03, dokumen G7-B |
| Runner yang disetujui | GitHub-hosted (`CI_RUNNER_MODE=github-hosted`; 0 runner self-hosted terdaftar). Job Full Suite selalu `ubuntu-latest`. |
| Kapasitas runner | 4 vCPU / 15,98 GB (terukur di CI-PERF-03); service `postgres:16` baru per job |
| Isolasi database test | Database service `testing`, langkah `ci:assert-non-production-database` sebelum migrasi. **Database klinis produksi tidak disentuh.** |
| Tidak ada deploy produksi yang bentrok | Produksi `fb3726c5`; tidak ada proses deploy di host (hanya file lock lama 2026-10-29) |
| Tidak ada job sensitif keamanan yang bentrok | Rotasi G7-B tidak dijalankan (NO-GO) |
| Konflik kepemilikan sumber daya | Dispatch di ref khusus `ci/controlled-authoritative-full-suite-1` yang menunjuk SHA persis, agar push ke base tidak bisa membatalkan run (grup concurrency berbasis ref). Tidak ada workflow lain yang dibatalkan. |
| Retensi artefak | 30 hari |

## Bukti run

| Field | Nilai |
|---|---|
| `TARGET_SHA` | `23868da05389dfc89f8bec4f2bf4ebc80a34c1e2` (dicetak ulang oleh job: `commit=23868da0…`) |
| `WORKFLOW_ID` | `foundation-evidence-gates.yml` (Foundation Evidence Gates) |
| `RUN_ID` | `40981123068` (event `workflow_dispatch`, dibuat 2026-10-30 00:40:06Z) |
| `JOB_ID` | `123837615538` (NSF-R011 Full Suite Gate) |
| `RUNNER_ID` | `GitHub Actions 1000033602`, label `ubuntu-latest` |
| `START_TIME` | Job 2026-10-30 01:29:43Z; langkah test 01:30:36Z |
| `END_TIME` | Langkah test 02:42:50Z; job 02:42:57Z |
| `EXIT_CODE` | 0 (langkah `Run full Pest suite` = success; `shell: bash` dengan `pipefail`, jadi exit code Pest tidak tertelan `tee`) |
| `TEST_COUNT` | 11.959 (11.953 lulus + 5 skip + 1 risky) |
| `ASSERTION_COUNT` | 63.231 |
| `FAILURE_COUNT` | 0 |
| `SKIPPED_COUNT` | 5 |
| `WARNING_COUNT` | 0 (ringkasan tidak memuat kategori warning) |
| `RISKY_COUNT` | 1 |
| Durasi Pest | 4.333,80 s |
| Artefak | `nsf-r011-full-suite-gate` (233.003 byte, berlaku sampai 2026-11-29) |

Langkah `Note skipped full suite (docs-only change)` berstatus **skipped**, yaitu bukti bahwa suite tidak
dilewati sebagai perubahan docs-only.

### Job lain di run yang sama

| Job | Hasil |
|---|---|
| CICD-CTRL Gate Classifier | success |
| NSF-R012 Quality Gate | success |
| NSF-R011 Critical Test Gate (GitHub-hosted) | success (00:43:16Z → 01:29:39Z) |
| NSF-R011 Critical Test Gate (self-hosted) | skipped (varian tidak aktif; hanya satu varian yang berjalan) |
| CICD-CTRL Selective Module Gate | success |
| NSF-9 Release Safety & Automated Smoke Gate | success |
| NSF-10 Release Evidence Gate | success |
| Phase 3 Android Clinic App Gate | success |

### Lima test yang di-skip dan satu yang risky

Semuanya test yang sengaja menargetkan driver atau runtime selain PostgreSQL/Redis yang dipakai CI:

| Kelas | Status | Alasan |
|---|---|---|
| `Tests\Unit\Console\RuntimeQueryObservabilityCommandTest` (2 test) | skip | Menargetkan driver non-pgsql / `pg_stat` tidak tersedia |
| `Tests\Feature\Foundation\CacheGovernanceFoundationTest` (1) | skip | Membutuhkan runtime Redis aktif |
| `Tests\Feature\Foundation\DbPerformanceGovernanceTest` (1) | skip | Menargetkan koneksi non-pgsql |
| `Tests\Feature\Foundation\PostgresRuntimeGovernanceTest` (1) | skip | Menargetkan koneksi non-pgsql |
| `Tests\Feature\Architecture\Nsf8ObservabilityRawGoClosureTest` (1) | risky | Kasus "r009 tidak berlaku di driver non-pgsql" tidak menjalankan assertion di pgsql |

Bentuk yang sama (1 risky, 5 skip) tercatat di Full Suite otoritatif sebelumnya (run `32700184849`,
2026-08-24); di run itu 1 test gagal karena kontrak teks PDF, yang sudah diperbaiki sesudahnya.

## Apa yang tidak diklaim

- Run ini tidak mempensiunkan kebijakan Full Suite sementara.
- Run ini tidak mencakup perubahan CI-PERF-04 (dikerjakan sesudahnya, di sprint terpisah).
- Run ini tidak dijalankan ulang. Tidak ada rerun otomatis.
