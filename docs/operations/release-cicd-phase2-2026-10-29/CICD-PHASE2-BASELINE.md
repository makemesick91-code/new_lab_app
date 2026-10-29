---
kb_status: snapshot
kb_verified_against: 52b7a486
kb_updated: 2026-10-30
---

# Baseline CI/CD Fase 2 (2026-10-30)

Baseline durasi workflow `foundation-evidence-gates` sebelum perbaikan CI/CD Fase 2. Angka diambil
langsung dari API GitHub (waktu mulai dan selesai job dan step), bukan dari catatan lama. Kembali ke
[00-INDEX](../../00-INDEX.md). Halaman terkait:
[CICD-SLOW-TEST-PROFILING](CICD-SLOW-TEST-PROFILING.md),
[CICD-PHASE2-FINAL-SUMMARY](CICD-PHASE2-FINAL-SUMMARY.md).

## Sampel

Tujuh run lengkap (profil classifier yang menjalankan critical gate dan modul selektif) pada commit
F-4b/F-4c dan rilis `fb3726c5`, 2026-10-28 s.d. 2026-10-29. Satu run docs-only dicatat sebagai kontrol.

| Run | Event | Commit | Workflow (s) | Menit runner | Critical job (s) | Step test critical (s) | Modul selektif (s) |
|---|---|---|---:|---:|---:|---:|---:|
| 40823677696 | pull_request | `6c52768f` | 6.070 | 152,9 | 5.777 | 5.714 | 2.929 |
| 40836499781 | push | `62ee56e4` | 6.683 | 172,7 | 6.394 | 6.327 | 3.550 |
| 40837127984 | pull_request | `f1294bd0` | 7.836 | 195,1 | 7.539 | 7.452 | 3.690 |
| 40855928295 | push | `bcc793e2` | 7.671 | 196,1 | 7.353 | 7.277 | 3.936 |
| 40856285308 | pull_request | `a8dcbb99` | 6.707 | 169,1 | 6.409 | 6.334 | 3.281 |
| 40872747306 | push | `897efd6b` | 7.519 | 178,6 | 7.225 | 7.153 | 3.016 |
| 40897488296 | workflow_dispatch (rilis) | `fb3726c5` | 7.359 | 173,9 | 7.065 | 6.994 | 2.901 |
| 40920300859 | pull_request (docs-only, kontrol) | `14e71739` | 367 | 9,1 | 63 | dilewati | dilewati |

## Ringkasan baseline

| Metrik | Median | Rentang |
|---|---:|---|
| Durasi workflow | **7.359 s (2 jam 3 menit)** | 6.070 – 7.836 s |
| Step test critical | **6.994 s (1 jam 57 menit)** | 5.714 – 7.452 s |
| Critical job | 7.065 s | 5.777 – 7.539 s |
| Modul selektif (berjalan paralel dengan critical) | 3.281 s | 2.901 – 3.936 s |
| Menit runner per run | 173,9 | 152,9 – 196,1 |
| Jumlah test critical | ~6.980 – 6.991 | |

Catatan:

- **Jalur kritis workflow adalah critical job.** Classifier dan quality gate selesai dalam beberapa
  menit; NSF-9 dan NSF-10 menunggu critical job. Mempercepat workflow berarti mempercepat critical job.
- **Variasi antar-run ±15%** untuk pekerjaan yang sama (5.714 s vs 7.452 s pada step test). Karena itu,
  perbandingan sebelum/sesudah harus memakai beberapa run, bukan satu run.
- Baseline lama yang dikutip di brief (7.401 s) berada di rentang yang sama. Median baru 7.359 s
  dipakai sebagai acuan karena diambil dari run yang bisa diverifikasi ulang.

## Target

| Target | Nilai | Status (diukur, lihat bagian berikut) |
|---|---|---|
| Tahap 1 | Workflow < 90 menit (5.400 s) | **Tercapai** di 6 dari 6 run sesudah perubahan |
| Tahap 2 | Workflow ≤ 60 menit (3.600 s) | **Belum konsisten**: 5 dari 6 run; satu run 3.849 s |

Batasan: coverage test wajib tidak dikurangi, dan gate NSF-R011, NSF-9, NSF-10, serta gate tata kelola
wajib lainnya tidak di-bypass.

## Sesudah perubahan (diukur di CI)

Run lengkap (critical gate dan modul selektif) setelah CI-PERF-01 (`seedAccessControl()` dari snapshot),
CI-PERF-02 dan CI-PERF-03. Diukur dengan cara yang sama dengan baseline (API GitHub).

| Run | Event | Tree | Workflow (s) | Menit runner | Step test critical (s) | Modul selektif (s) | Test critical |
|---|---|---|---:|---:|---:|---:|---|
| 40924536826 | PR #555 | CI-PERF-01 | 3.214 | 66,1 | 2.875 | 626 | 6.999 lulus |
| 40933218576 | push | `ec7f1c7c` (CI-PERF-01) | 3.849 | 78,7 | 3.472 | 674 | 6.999 lulus |
| 40939517489 | PR #556 | `ac720b8b` (CI-PERF-01 + 02) | 3.037 | 66,8 | 2.707 | 780 | 7.010 lulus |
| 40947344916 | push | `500f8be3` (CI-PERF-01 + 02) | 2.853 | 67,2 | 2.496 | 987 | 7.010 lulus |
| 40964727373 | PR #558 | `a535b053` (CI-PERF-01 + 02 + 03) | 2.589 | 52,4 | 2.247 | 391 | 7.015 lulus |
| 40970233065 | push | `52b7a486` (CI-PERF-01 + 02 + 03) | 2.749 | 53,2 | 2.423 | 265 | 7.015 lulus |

| Metrik | Baseline (median 7 run) | Sesudah (median 6 run) | Perubahan |
|---|---:|---:|---:|
| Workflow | 7.359 s | 2.945 s | **−60,0%** |
| Step test critical | 6.994 s | 2.602 s | **−62,8%** |
| Menit runner | 173,9 | 66,4 | **−61,8%** |

- Pembanding sejajar (malam yang sama, beban runner sebanding): run 40927067649 (CI-PERF-02 **tanpa**
  snapshot) mencatat step critical 4.329 s, modul selektif 3.837 s dan 144,8 menit runner. Dengan snapshot:
  step critical −34%, modul selektif −84%, menit runner −54%.
- Jumlah test critical tidak berkurang: 6.999 → 7.010 → 7.015 (+11 dan +5 adalah suite penjaga CI-PERF-02 dan
  CI-PERF-03). Semua gate wajib hijau dan warning critical 0 di keenam run. Full Suite SKIPPED oleh kebijakan global sementara, bukan lulus.
- Variasi antar-run tetap besar (step critical 2.247–3.472 s). Benchmark paralel menunjukkan model CPU
  runner sebagai salah satu penyebabnya. Target tahap 2 baru boleh dinyatakan
  tercapai setelah run berikutnya konsisten di bawah 3.600 s.

