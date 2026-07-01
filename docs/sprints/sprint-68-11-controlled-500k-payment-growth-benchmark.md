# Sprint 68.11 — Controlled 500k Payment Growth Benchmark

## Scope
- Local/stress DB only (`daengtisia_stress` via `--env=stress`).
- Controlled synthetic payment growth from ~90k → ~250k → ~500k.
- SQL Q4/Q5/Q6 and HTTP Owner Dashboard/RME page benchmarks at each stage.
- No VPS deploy. No production/pilot data. No migration. No business-logic change.

## Deploy Decision
- Deploy needed: **No**.
- Reason: benchmark evidence only; HTTP Owner Dashboard remains <100 ms avg at ~500k payments; no index/migration/query optimization warranted now.

## Environment
| Item | Value |
|---|---|
| Branch | `feature/sprint-68-11-controlled-500k-payment-growth-benchmark` |
| Commit before work | `e0891ac` |
| APP_ENV | `stress` (artisan `--env=stress` → `.env.stress`) |
| DB_CONNECTION | `pgsql` |
| DB_DATABASE | `daengtisia_stress` |
| PHP | 8.5.4 |
| Laravel | 12.61.0 |
| Dataset source | Sprint 68.8/68.10 retained local stress DB |

## Baseline Dataset
| Table | Count |
|---|---:|
| mst_patients | 250,000 |
| trx_clinic_visits | 100,000 |
| trx_rme_invoices | 100,000 |
| trx_rme_invoice_items | 300,000 |
| trx_rme_payments | 90,000 |
| trx_rme_receivable_follow_ups | 6,000 |
| trx_lab_case_candidates | 90,000 |

## Growth Plan
| Stage | Visit Target | Expected Payments | Purpose |
|---|---:|---:|---|
| Baseline | ~100k | ~90k | Current reference |
| Stage A | 278k | ~250k | Mid-volume |
| Stage B | 556k | ~500k | High-volume |

## Resource Baseline
| Metric | Value |
|---|---|
| DB size | 494 MB |
| Disk free | 60 GB (`/home`) |
| Memory available | ~5.1 GiB |

## Payment Index Review
| Index | Exists | Valid | Ready | Size (baseline → 500k) | Notes |
|---|---|---|---|---|---|
| `trx_rme_payments_branch_paid_at_idx` | Yes | Yes | Yes | 4056 kB → 22 MB | `(branch_id, paid_at DESC) INCLUDE (amount)` — Sprint 68.2 migration |

Other payment indexes unchanged: `branch_invoice`, `rme_invoice_id`, `clinic_visit_id`, `payment_batch_uuid`, `payment_number` unique, PK.

## Stage Results
| Stage | Visit Target | Visits After | Payments After | Duration | Max RSS | DB Size | Disk Free | Result |
|---|---:|---:|---:|---:|---:|---|---|---|
| Baseline | 100k | 100,000 | 90,000 | — | — | 494 MB | 60 GB | Reference |
| Stage A | 278k | 278,000 | 250,200 | 7:50 | 208 MB | 1132 MB | 60 GB | OK |
| Stage B | 556k | 556,000 | 500,400 | 10:47 | 208 MB | 2059 MB | 60 GB | OK |

## SQL Benchmark Results
| Stage | Payments | Q4 Payment Report | Q5 Aggregate | Q5 Forced Index | Q6 Daily Trend | Q6 Forced Index | Decision |
|---|---:|---:|---:|---:|---:|---:|---|
| Baseline | 90k | 0.10 ms | 39.76 ms | 27.78 ms | 36.34 ms | 35.04 ms | OK / WATCH |
| Stage A | 250k | 0.12 ms | 46.06 ms | 276.28 ms | 59.05 ms | 45.91 ms | OK / WATCH |
| Stage B | 500k | 0.10 ms | 79.81 ms | 333.35 ms | 118.25 ms | 83.27 ms | OK / WATCH |

Notes:
- Q4 uses Index Scan on `trx_rme_payments_branch_paid_at_idx` at all stages — sub-millisecond.
- Q5/Q6 use Seq Scan + Aggregate at baseline through Stage B; planner choice remains optimal.
- Forced index (`enable_seqscan=off`) on Q5 aggregate becomes **slower** at 250k+ (276–333 ms vs 46–80 ms seq scan) — do not force index.
- Q6 daily trend crosses 100 ms threshold at 500k (118 ms) but remains well under FIX candidate (>1s).

## HTTP Benchmark Results
Command: `php artisan stress:benchmark-rme-pages --env=stress --runs=3 --branch-code=TST --include-owner`

| Stage | Payments | Owner Dashboard Avg | Owner KPI Month Avg | Owner Branch Avg | RME Avg Range | P95 Max | Decision |
|---|---:|---:|---:|---:|---:|---:|---|
| Baseline | 90k | 55.20 | 53.11 | 53.95 | 51–61 | 66.82 | OK |
| Stage A | 250k | 58.29 | 52.40 | 66.46 | 52–56 | 74.62 | OK |
| Stage B | 500k | 60.12 | 62.35 | 59.28 | 53–63 | 71.80 | OK |

All 13 targets returned HTTP 200 at every stage. No errors. Owner Dashboard avg <65 ms even at 500k payments; p95 max 71.80 ms.

## Payment Distribution
| Branch | Payment Count | Date Range | Null Paid At | Table Size |
|---|---:|---|---:|---|
| TST (id=9) | 500,400 | 2026-07-01 → 2026-07-01 | 0 | 223 MB |

All stress payments share seed `paid_at` on 2026-07-01 (known artifact from Sprint 68.8+).

## Idempotency Check
- Final target: 556,000 visits / 500,400 payments
- Rerun command: `stress:seed-rme-history --target=556000 --force`
- Result: **PASS** — "Target already reached. No new RME history inserted." Counts unchanged.

## Laravel Log Review
- Fresh errors: None from Sprint 68.11 seed/benchmark runs (Stage A/B completed exit 0).
- Notes: Stale log entry from prior session (`smallint` overflow value 32768) — predates successful 556k growth; not reproduced in this sprint.

## Decision
- Q4: **OK** — index scan sub-ms at all scales.
- Q5: **OK** at 500k (80 ms seq scan); forced index slower — no index optimization.
- Q6: **WATCH** at 500k (118 ms) — acceptable; HTTP path unaffected.
- Owner Dashboard: **OK** — avg ~60 ms at 500k payments, well under 300 ms threshold.
- Overall: **NO OPTIMIZATION NOW** — payment aggregate performance acceptable at 5× payment growth.

## Recommendation
- Immediate action: **None** — no deploy, no migration, no query rewrite.
- Future optimization threshold: Revisit if payments exceed ~1M **and** Owner Dashboard HTTP avg >300 ms or Q5/Q6 SQL >500 ms consistently.
- Suggested Sprint 68.12: **Performance Closure Report** — consolidate Sprint 68.8–68.11 stress evidence; defer aggregate optimization unless production pilot shows degradation.

## Commands Run
```bash
# Branch setup
git switch -c feature/sprint-68-11-controlled-500k-payment-growth-benchmark

# Baseline verification (daengtisia_stress)
source .env.stress
psql ... COUNT(*) baseline tables
ANALYZE trx_rme_payments; ...

# Baseline benchmarks
EXPLAIN (ANALYZE, BUFFERS) Q4/Q5/Q6 (+ forced index)
php artisan stress:benchmark-rme-pages --env=stress --runs=3 --branch-code=TST --include-owner

# Stage A (~250k payments)
php artisan stress:seed-rme-history --env=stress --target=278000 --chunk-size=1000 --branch-code=TST --force
# → 178k visits inserted, 250,200 payments, 7:50 elapsed

# Stage A post-growth benchmarks (same SQL + HTTP commands)

# Stage B (~500k payments)
php artisan stress:seed-rme-history --env=stress --target=556000 --chunk-size=1000 --branch-code=TST --force
# → 278k visits inserted, 500,400 payments, 10:47 elapsed

# Stage B post-growth benchmarks (same SQL + HTTP commands)

# Idempotency
php artisan stress:seed-rme-history --env=stress --target=556000 --chunk-size=1000 --branch-code=TST --force
# → Target already reached
```

## Safety Confirmation
- No deploy.
- No VPS SSH.
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump committed.
- No real PII/KTP/NIK exposed.

## Deferred
- Owner Dashboard aggregate/materialized summary optimization — deferred until evidence shows HTTP degradation.
- Forced `trx_rme_payments_branch_paid_at_idx` aggregate path — rejected (slower than seq scan at scale).
- 1M payment growth benchmark — deferred unless explicitly approved.
