# Sprint 68.12 — Performance Closure Report

## Executive Summary

- Sprint 68.8–68.11 mengevaluasi performa read-path RME dan Owner Dashboard pada database stress lokal (`daengtisia_stress`) dengan data sintetis — bukan pilot/VPS production.
- Dataset stress akhir: **250,000 pasien**, **556,000 kunjungan RME**, **500,400 pembayaran RME**, ukuran DB **~2,059 MB** (tabel `trx_rme_payments` ~223 MB).
- Jalur SQL operasional (visit list, patient history, receivables, payment report LIMIT 50) tetap **sub-ms hingga <2 ms** pada skala 500k pembayaran.
- Q5 owner payment aggregate mencapai **~80 ms** (seq scan) pada 500k pembayaran — masih **OK/WATCH**, jauh di bawah ambang FIX (>1 s).
- Q6 daily trend mencapai **~118 ms** pada 500k pembayaran — **WATCH** untuk SQL isolasi, tetapi tidak memengaruhi HTTP user-facing.
- Forced index pada Q5 aggregate **ditolak** — 276–333 ms pada 250k–500k pembayaran vs 46–80 ms seq scan.
- HTTP benchmark (13 target, termasuk Owner Dashboard KPI) tetap **HTTP 200**, avg **~53–63 ms** RME dan **~60 ms** Owner Dashboard pada Stage B; p95 max **71.80 ms**.
- **Tidak ada deploy diperlukan.** Tidak ada migrasi/index/query rewrite/cache/materialized summary sekarang.
- Concurrency multi-user tidak diukur sebagai fokus utama; bukti lokal stress tidak identik dengan hardware/concurrency VPS/pilot — monitoring pilot tetap diperlukan.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed | No |
| Reason | Evidence/report-only; performa masih dalam ambang OK/WATCH |
| VPS action | None |
| Migration/index needed | No |
| Runtime code change needed | No |

## Evidence Sources

| Sprint | Focus | Final Status | Key Output |
|---|---|---|---|
| 68.8 | RME history growth to 100k visits | GO | 250k patients, ~100k visit chain, Q1–Q4 OK, Q5/Q6 WATCH (~27–33 ms) |
| 68.9 | SQL read-path bottleneck review | GO | Seq scan optimal for Q5/Q6; forced index slower; HTTP RME ~51–66 ms |
| 68.10 | HTTP benchmark harness expansion | GO | 13 targets incl. Owner Dashboard; avg ~40–44 ms at 90k payments |
| 68.11 | 500k payment growth benchmark | GO | Stage B: 500k payments, Q5 ~80 ms, Q6 ~118 ms, Owner HTTP ~60 ms |

## Final Stress Dataset Capacity

| Metric | Value |
|---|---:|
| Patients | 250,000 |
| Visits | 556,000 |
| RME payments | 500,400 |
| DB size | 2,059 MB |
| Payment table size | 223 MB |
| Payment index size | 22 MB |
| Disk free during test | ~60 GB |
| Max PHP RSS during seed | 208 MB |

## SQL Benchmark Closure

| Query | 90k Payments | 250k Payments | 500k Payments | Decision |
|---|---:|---:|---:|---|
| Q4 Payment report LIMIT 50 | 0.10 ms | 0.12 ms | 0.10 ms | OK |
| Q5 Owner aggregate | 39.76 ms | 46.06 ms | 79.81 ms | OK/WATCH |
| Q5 Forced index | 27.78 ms | 276.28 ms | 333.35 ms | Do not force index |
| Q6 Daily trend | 36.34 ms | 59.05 ms | 118.25 ms | WATCH |
| Q6 Forced index | 35.04 ms | 45.91 ms | 83.27 ms | WATCH, not required |

### Additional SQL paths (68.8–68.9, 90k–100k visits)

| Query | Runtime (representative) | Decision |
|---|---:|---|
| Q1 Visit list LIMIT 50 | 1.37–1.95 ms | OK |
| Q2 Patient history LIMIT 50 | 0.04–0.13 ms | OK |
| Q3 Active receivables LIMIT 50 | 0.20–0.37 ms | OK |

## HTTP Benchmark Closure

| Stage | Payments | Owner Dashboard Avg | Owner KPI Month Avg | Owner Branch Avg | RME Range | P95 Max | Decision |
|---|---:|---:|---:|---:|---|---:|---|
| Baseline | 90,000 | 55.20 ms | 53.11 ms | 53.95 ms | 51–61 ms | 66.82 ms | OK |
| Stage A | 250,200 | 58.29 ms | 52.40 ms | 66.46 ms | 52–56 ms | 74.62 ms | OK |
| Stage B | 500,400 | 60.12 ms | 62.35 ms | 59.28 ms | 53–63 ms | 71.80 ms | OK |

Benchmark command: `php artisan stress:benchmark-rme-pages --env=stress --runs=3 --branch-code=TST --include-owner --warmup=1`. Server: `http://127.0.0.1:8008`. Semua 13 target HTTP 200, 0 errors di setiap stage.

## Bottleneck Decision Matrix

| Area | Status | Reason | Action |
|---|---|---|---|
| RME visit list | OK | Index-backed branch+date scan; <2 ms | No action |
| Patient history | OK | Index on patient_id; sub-ms | No action |
| Receivables | OK | LIMIT 50 acceptable; HTTP ~40–56 ms | No action |
| Payment report | OK | Uses `trx_rme_payments_branch_paid_at_idx`; sub-ms | No action |
| Owner aggregate Q5 | OK/WATCH | 79.81 ms seq scan at 500k payments; <100 ms | Monitor |
| Daily trend Q6 | WATCH | 118.25 ms SQL at 500k; HTTP not impacted | Monitor |
| Forced index Q5 | Reject | 333 ms at 500k vs 80 ms seq scan | Do not implement |
| Owner Dashboard HTTP | OK | ~60 ms avg at 500k payments; p95 <72 ms | No action |

## Official Performance Thresholds

Ambang resmi untuk evaluasi sprint performa berikutnya.

### SQL thresholds

| Runtime | Status | Action |
|---:|---|---|
| <100 ms | OK | No action |
| 100–300 ms | WATCH | Monitor |
| 300–500 ms | WATCH / investigate | Review frequency and HTTP impact |
| 500 ms–1 s | INVESTIGATE | Candidate for query/index/summary review |
| >1 s | FIX | Optimization sprint required |

### HTTP thresholds

| Runtime | Status | Action |
|---:|---|---|
| <100 ms avg | OK | No action |
| 100–300 ms avg | OK/WATCH | Monitor |
| 300–500 ms avg | WATCH | Investigate if frequent |
| 500 ms–1 s avg | INVESTIGATE | Optimization candidate |
| >1 s avg or p95 | FIX | Optimization required |

## Optimization Trigger Rules

Optimisasi **tidak diperlukan sekarang**.

Trigger optimisasi hanya jika satu atau lebih kondisi berikut terpenuhi:

- Owner Dashboard HTTP avg > 300 ms secara konsisten.
- Owner Dashboard HTTP avg > 500 ms pada stress/pilot.
- Q5/Q6 SQL > 500 ms secara konsisten.
- Payment rows melebihi 1M dan Q5/Q6 menjadi user-visible slow.
- Owner dashboard p95 > 1 s.
- Bukti pilot/VPS menunjukkan perilaku lebih lambat dari local stress.

Jenis optimisasi yang **bukan** scope Sprint 68.12 (hanya jika trigger terpenuhi dan disetujui):

- Migrasi index PostgreSQL baru.
- Rewrite query Owner Dashboard aggregate.
- Summary table / materialized aggregate.
- Cache strategy.
- Benchmark tooling untuk VPS/pilot.

## Current Safe Capacity Statement

Berdasarkan bukti local stress (bukan kapasitas production terjamin), DaengtisiaMS tetap responsif pada skala:

- **250,000** pasien sintetis
- **556,000** kunjungan RME
- **500,400** pembayaran RME
- **~2 GB** ukuran database stress

dengan Owner Dashboard avg **~60 ms**, halaman RME **53–63 ms** avg, dan p95 max **71.80 ms**.

**Caveat:** Bukti ini dihasilkan pada environment stress lokal (single-session HTTP benchmark, data sintetis `TST-*`, semua `paid_at` ter-cluster pada satu hari seed). Hardware VPS/pilot, concurrency multi-user, dan distribusi data production dapat berbeda. Monitoring pilot/VPS tetap diperlukan untuk kepercayaan operasional nyata.

## Deploy / Release Recommendation

- No deploy for Sprint 68.12.
- No index migration.
- No materialized summary.
- No cache layer.
- No runtime query rewrite.
- Continue monitoring Q5/Q6 SQL dan Owner Dashboard HTTP pada sprint berikutnya jika volume data pilot bertambah.

## Recommended Next Sprint Options

**Primary recommendation:**

**Sprint 68.13 — Performance Closure Owner/Stakeholder Summary**

Ringkasan non-teknis untuk stakeholder: kapasitas aman lokal, ambang WATCH, dan kapan optimisasi diperlukan — tanpa deploy atau perubahan runtime.

**Alternatives:**

- **Sprint 68.13 — Controlled 1M Payment Growth Benchmark** — hanya dengan persetujuan eksplisit; belum dibuktikan perlu sekarang.
- **Sprint 68.13 — Pilot Performance Monitoring Dashboard** — jika monitoring VPS/pilot diperlukan untuk validasi real-world.
- **Sprint 68.13 — Owner Dashboard Aggregate Optimization** — hanya jika bukti masa depan melintasi ambang trigger (HTTP >300 ms atau Q5/Q6 SQL >500 ms).

## Safety Confirmation

- No deploy.
- No VPS SSH.
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump committed.
- No real PII/KTP/NIK exposed.
- Concurrency not measured as primary focus in Sprint 68.8–68.11.

## Commands Run

```bash
cd ~/Projects/new_lab_app
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch -c feature/sprint-68-12-performance-closure-report

graphify update .

pwd
git status --short
git status --short --ignored | grep -E '(^!! .env$|.env|storage/app/backups|graphify-out|ssh|daengtisiams_vps|dump|\.sql|benchmark|screenshots)' || true
git branch --show-current
git log --oneline -5
php artisan about

# Doc discovery
ls docs/sprints | grep -E "68-(8|9|10|11)"
rg -n "Sprint 68\\.(8|9|10|11)|Q5|Q6|Owner Dashboard|500k|payment|benchmark|GO|NO DEPLOY" docs/sprints -g'*.md'

# Report authoring (docs-only)
git diff --check
git status --short
git diff --stat
```

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / NO DEPLOY
