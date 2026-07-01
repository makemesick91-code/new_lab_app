# Sprint 68.9 — RME Read Path Bottleneck Review on 100k Visits

## Scope
- Local/stress DB only (`daengtisia_stress` via `--env=stress`).
- Review RME read-path bottlenecks on ~100k visit stress dataset.
- Focus Q5/Q6 owner payment aggregate and daily trend WATCH findings from Sprint 68.8.
- No deploy. No VPS SSH. No production/pilot data. No migration. No business-logic change.

## Environment
| Item | Value |
|---|---|
| Branch | `feature/sprint-68-9-rme-read-path-bottleneck-review-100k-visits` |
| Commit before work | `99d9ef3` (Sprint 68.8 merge) |
| APP_ENV | `stress` (artisan `--env=stress` → `.env.stress`) |
| DB_CONNECTION | `pgsql` |
| DB_DATABASE | `daengtisia_stress` |
| PHP | 8.5.4 |
| Laravel | 12.61.0 |
| Dataset source | Sprint 68.8 pattern — **rebuilt locally** (DB was empty at sprint start) |

## Dataset Baseline
| Table | Count |
|---|---:|
| mst_patients | 250,000 |
| trx_clinic_visits | 100,000 |
| trx_medical_records | 100,000 |
| trx_odontograms | 100,000 |
| trx_rme_invoices | 100,000 |
| trx_rme_invoice_items | 300,000 |
| trx_rme_payments | 90,000 |
| trx_rme_receivable_follow_ups | 6,000 |
| trx_lab_case_candidates | 90,000 |

**Note:** Sprint 68.8 reported 99,823 visits / 89,844 payments due to visit-seq gaps. This rebuild reached max seq 100,000 with no gaps (+156 payments, +177 invoice items). Shape and scale are equivalent for read-path review.

## Resource Baseline
| Metric | Value |
|---|---|
| DB size | 494 MB |
| Disk free | 60 GB (`/home`) |
| Memory available | ~5.3 Gi |
| Load average | 2.68 (post-seed) |

## Payment Index Review
| Index | Exists | Valid | Ready | Size | Notes |
|---|---|---|---|---|---|
| `trx_rme_payments_branch_paid_at_idx` | Yes | Yes | Yes | 4056 kB | `(branch_id, paid_at DESC) INCLUDE (amount)` — Sprint 68.2 migration present and valid |

Other payment indexes: `branch_invoice`, `rme_invoice_id`, `clinic_visit_id`, `payment_batch_uuid`, `payment_number` unique, PK.

## Main Query Evidence
| ID | Query | Plan Summary | Runtime | Buffers | Decision |
|---|---|---|---:|---|---|
| Q1 | Visit list (branch, date desc LIMIT 50) | Index Scan Backward `trx_clinic_visits_branch_date_status_index` + top-N sort | 1.95 ms | hit=100 read=188 | **OK** |
| Q2 | Patient RME history (patient_id LIMIT 50) | Index Scan `trx_clinic_visits_patient_id_index` | 0.04 ms | hit=3 | **OK** |
| Q3 | Active receivables (UNPAID/PARTIAL + payment subplan LIMIT 50) | Index Scan Backward PK + SubPlan per row (payment index) | 0.37 ms | hit=137 | **OK** |
| Q4 | Payment report (branch, paid_at desc LIMIT 50) | Index Scan `trx_rme_payments_branch_paid_at_idx` | 0.07 ms | hit=5 | **OK** |
| Q5 | Owner aggregate (branch, year sum) | **Seq Scan** on `trx_rme_payments` (~90k rows) | 32.21 ms | hit=720 read=1853 | **WATCH** — acceptable; planner optimal |
| Q6 | Payment daily trend (branch, year, GROUP BY day) | **Seq Scan** + HashAggregate (~90k rows) | 35.85 ms | hit=2573 | **WATCH** — acceptable at current scale |

**Q3 query shape:** Uses `applyActiveReceivableConstraint` equivalent (`grand_total > payment subquery`), not a non-existent `remaining_amount` column.

## Q5/Q6 Comparison
| Test | Plan Summary | Runtime | Decision |
|---|---|---:|---|
| C1 force index aggregate (`enable_seqscan=off`) | Index Only Scan `trx_rme_payments_branch_paid_at_idx` | 68.38 ms | **Seq scan faster** — do not force index |
| C2 force index daily trend | Index Only Scan + HashAggregate | 28.11 ms | Marginal vs seq (35.85 ms); not material enough to change planner |
| C3 narrow date aggregate (June 2026) | Index Only Scan, 0 rows | 0.04 ms | N/A — all stress payments `paid_at` on 2026-07-01 (seed artifact) |
| C4 all branches aggregate (year) | Seq Scan ~90k rows | 22.31 ms | **OK** — single-branch filter adds little at this scale |

### Q5/Q6 interpretation
1. **Why seq scan?** At ~90k rows / ~41 MB table, PostgreSQL estimates full-table read is cheaper than index walk + heap fetches (`Heap Fetches: 14436` on forced index-only scan).
2. **Index exists and is valid** — used correctly for Q4 (ordered LIMIT) and C3 narrow range.
3. **ANALYZE** run before evidence; stats fresh (`last_autoanalyze` 2026-07-01).
4. **~30 ms SQL** is under WATCH threshold (<100 ms); HTTP RME pages all <73 ms (see below).
5. **Daily trend returns 1 day group** because stress seeder stamped all `paid_at` on seed run date — not a production distribution; grouping cost still bounded.

## Payment Distribution
| Branch | Payment Count | Date Range | Null Paid At | Notes |
|---:|---:|---|---:|---|
| 9 (TST) | 90,000 | 2026-07-01 09:29–09:32 | 0 | Single-day cluster from bulk seed; explains C3 zero rows for June filter |

## HTTP Benchmark
- **Status:** Ran successfully
- **Command:** `php artisan stress:benchmark-rme-pages --env=stress --runs=3`
- **Server:** `http://127.0.0.1:8008` (stress app, authenticated)
- **Result:** All pages HTTP 200; avg latency 0.051–0.066 s (max p95 0.073 s)

| Page | Avg (s) | p95 (s) |
|---|---:|---:|
| rme_dashboard | 0.055 | 0.059 |
| rme_visits | 0.057 | 0.066 |
| rme_receivables | 0.056 | 0.062 |
| rme_reports_payments | 0.061 | 0.064 |

- **Owner dashboard (`/dashboard` KPI block):** Not in benchmark command page list; deferred. SQL Q5/Q6 ~32–36 ms alone would not explain >1 s HTTP; no evidence of owner-dashboard HTTP regression at 100k scale.

## Laravel Log Review
- Fresh errors: historical `queue_number` smallint overflow from prior partial seed attempt (Sprint 68.8 class of issue); successful full rebuild completed without new application errors.
- Notes: no RME business-logic errors during Sprint 68.9 evidence run.

## Decision
- **Q1:** OK — index-backed visit list.
- **Q2:** OK — patient history index.
- **Q3:** OK — subplan per row acceptable at LIMIT 50; HTTP receivables ~56 ms.
- **Q4:** OK — branch+paid_at index used.
- **Q5:** WATCH — seq scan **expected and faster than forced index** at 90k rows; no immediate fix.
- **Q6:** WATCH — seq scan acceptable; forced index ~20% faster in isolation but total <36 ms; defer optimization.
- **Overall:** **GO / WATCH** — read paths healthy at 100k visits; Q5/Q6 remain monitoring items for higher volume or multi-branch aggregate growth, not blockers.

## Recommendation
- **Immediate action:** None. No migration, no query rewrite, no deploy.
- **Future Sprint 68.10 proposal:** **Defer Owner Payment Aggregate Optimization** unless:
  - payment row count exceeds ~500k–1M per branch, or
  - owner dashboard HTTP KPI block exceeds 500 ms–1 s in measured harness.
  - Optional: extend `stress:benchmark-rme-pages` to include `/dashboard` owner KPI period for regression tracking.
- **Deploy needed now:** No.

## Commands Run
```bash
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch -c feature/sprint-68-9-rme-read-path-bottleneck-review-100k-visits

graphify query "Owner dashboard payment aggregate daily trend"

# Dataset rebuild (DB empty at start)
php artisan stress:seed-foundation --env=stress
php artisan stress:seed-patients --env=stress --target=250000 --chunk-size=1000
php artisan stress:seed-rme-history --env=stress --target=100000 --chunk-size=1000 --branch-code=TST

# ANALYZE + EXPLAIN (ANALYZE, BUFFERS) Q1–Q6, C1–C4 on daengtisia_stress
# TST_BRANCH_ID=9

php artisan stress:benchmark-rme-pages --env=stress --runs=3

git diff --check
```

## Safety Confirmation
- No deploy.
- No VPS SSH.
- No migration.
- No destructive DB command (`migrate:fresh` / `db:wipe` not run).
- No business logic changed (docs-only sprint).
- No `.env`/backup/SSH key/DB dump/benchmark raw artifacts committed.
- No real PII/KTP/NIK exposed.

## Deferred
- Owner dashboard HTTP benchmark (`/dashboard` KPI) — add to stress harness in future sprint if needed.
- Q5/Q6 optimization / materialized daily payment summary — only if volume or HTTP evidence warrants Sprint 68.10.
- Stress `paid_at` single-day distribution — cosmetic for trend grouping tests; does not affect aggregate sum evidence.
