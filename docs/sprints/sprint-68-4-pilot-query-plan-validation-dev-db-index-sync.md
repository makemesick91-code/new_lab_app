# Sprint 68.4 — Pilot Query Plan Validation & Dev DB Index Sync

## Scope
- Read-only pilot query-plan validation.
- Local dev DB physical index sync.
- No app business logic changes.
- No VPS deploy.

## Environment

### Local
| Item | Value |
|---|---|
| Branch | `feature/sprint-68-4-pilot-query-plan-validation-dev-db-index-sync` |
| Base / Commit | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `e5bab39` |
| Laravel | 12.61.0 |
| PHP | 8.5.4 |
| DB_DATABASE | `asia_dental_lab` |
| DB driver | pgsql (PostgreSQL) |
| Environment | stress (local) |

### VPS / Pilot
| Item | Value |
|---|---|
| Path | /var/www/asia-dental-lab-v2 |
| Commit/tag | tag `sprint-68-2-read-path-bottleneck-investigation-go` (commit `f365eef`) |
| Laravel | 12.61.0 |
| PHP | 8.3.6 |
| Environment | pilot |
| Debug | OFF |
| Maintenance | OFF |
| DB driver | pgsql |
| DB name | `asia_dental_lab_pilot` |

## Sprint 68.2 Index Validation on Pilot
- **Index name:** `trx_rme_payments_branch_paid_at_idx`
- **Exists:** Yes
- **Valid:** Yes (`indisvalid = t`)
- **Ready:** Yes (`indisready = t`)
- **Definition:** `CREATE INDEX trx_rme_payments_branch_paid_at_idx ON public.trx_rme_payments USING btree (branch_id, paid_at DESC) INCLUDE (amount)`

The pilot index is byte-for-byte identical to the migration (`2026_07_01_680200`) and to the
copy synced into local `asia_dental_lab` (Finding 4).

## Pilot Data Snapshot
| Table | n_live_tup | n_dead_tup | total_size | last analyze / autovacuum |
|---|---:|---:|---|---|
| trx_clinic_visits | 26 | 5 | 176 kB | autoanalyze 2026-06-26; autovacuum 2026-06-29 |
| trx_inventory_movements | 49 | 0 | 288 kB | never analyzed / never vacuumed |
| trx_rme_invoices | 17 | 34 | 112 kB | autoanalyze 2026-06-27 |
| trx_rme_payments | 25 | 0 | 128 kB | never analyzed / never vacuumed |

- Branch ids present: `1, 2, 3, 4`.
- Payments: 25 rows, `paid_at` range `2026-06-11 → 2026-06-27`.
- **Interpretation:** pilot volumes are still very small (each hot table fits in ~1 data page).
  This is the dominant factor in every plan below.

## Pilot Query Plans Reviewed
| ID | Area | Index Used | Plan (top node) | Runtime | Buffers | Decision |
|---|---|---|---|---|---|---|
| Q1 | Owner payment KPI SUM (multi-branch + 90d) | No — Seq Scan | Aggregate → Seq Scan (25 rows) | 0.113 ms | hit=3 | Keep / expected |
| Q2 | Owner daily payment trend select | No — Seq Scan | Seq Scan (25 rows) | 0.049 ms | hit=3 | Keep / expected |
| Q3 | RME payment report, branch-filtered ORDER BY paid_at DESC LIMIT 50 | No — Seq Scan + quicksort | Limit → Sort → Seq Scan | 0.047 ms | hit=5 | Keep / expected |
| Q4 | RME payment report, all branches ORDER BY paid_at DESC LIMIT 50 | No — Seq Scan + quicksort | Limit → Sort → Seq Scan | 0.022 ms | hit=1 | Keep / expected |
| Q5 | Inventory current stock aggregate (SUM in − out GROUP BY product_id) | **Yes — Index Only Scan** on `…branch_product_covering_index` | GroupAggregate → Index Only Scan (Heap Fetches: 1) | 0.273 ms | hit=3 | Keep / no action |
| Q6 | Inventory valuation SUM(quantity_in*unit_cost) | **Yes — Index Scan** on `…branch_product_covering_index` | Aggregate → Index Scan | 0.166 ms | hit=3 | Keep / no action |

> Note: the `InitPlan` Seq Scan on `mst_branches` that appears in most plans is the
> self-parameterizing branch-id subquery embedded in the evidence script (so the script
> needs no manual editing); it scans 4 branch rows and is not part of the production query.

## Findings

### Finding 1 — Payment index on real pilot data
- **Evidence:** The `trx_rme_payments_branch_paid_at_idx` index **exists and is valid/ready**
  on the pilot DB. However, at the current pilot volume (25 payment rows, one heap page) the
  planner correctly chooses a **Seq Scan** for Q1–Q4 — a single-page sequential scan is cheaper
  than any index access, so the index is *not selected*. All four payment queries complete in
  **0.02–0.11 ms**.
- **Decision:** **Confirmed present & valid; not yet exercised by the planner because the table
  is too small — expected and correct.** The index becomes beneficial once the table grows past
  the seq-scan/index crossover (the synthetic 30k-row evidence in Sprint 68.3 already showed the
  planner switching to Bitmap/Index scans on this exact index). **No change. No new payment index.**
  Matches decision-matrix C1/C2.

### Finding 2 — Cross-branch payment report
- **Evidence:** Q4 (no `branch_id` filter) is a Seq Scan + top-N quicksort of 25 rows in
  0.022 ms. The leading-column index cannot help a query with no `branch_id` predicate, and at
  pilot scale the sort is trivial.
- **Decision:** **Expected. No standalone `paid_at` index added** (would be speculative and would
  only matter at much larger all-branch volumes). Matches C2/C3 — no slow path observed.

### Finding 3 — Inventory ledger aggregate on real pilot data
- **Evidence:** Q5 (current stock) runs as an **Index Only Scan** using the existing
  `trx_inv_movements_branch_product_covering_index (branch_id, product_id) INCLUDE
  (quantity_in, quantity_out)` → GroupAggregate, 0.273 ms. Q6 (valuation) uses an Index Scan on
  the same index, 0.166 ms. This **resolves Sprint 68.3's deferred F3 question**: on real
  (non-synthetic) pilot data the covering index *does* yield an index-only scan.
- **Caveat (not a defect):** `Heap Fetches: 1` on Q5 because `trx_inventory_movements` has
  **never been vacuumed/analyzed** (visibility map unset), so PostgreSQL still visits the heap
  once. A `VACUUM (ANALYZE)` would drop heap fetches toward 0, but at 49 rows / 288 kB the cost
  is negligible and unnecessary.
- **Decision:** **No new index. No rewrite. No VACUUM required at this scale.** The existing
  covering index is correct and effective. Matches C4 (fast enough).

### Finding 4 — Local dev DB `asia_dental_lab` index sync
- **Before:** `migrate:status` showed `2026_07_01_680200` as **Ran**, but
  `trx_rme_payments_branch_paid_at_idx` was **physically absent** from `asia_dental_lab`
  (table has 0 rows). This is the local drift flagged in Sprint 68.3.
- **Backup:** `storage/app/backups/local/pre_sprint_68_4_dev_index_sync_asia_dental_lab_20260701-074702.sql`
  (392 kB, verified non-empty) taken before any change.
- **Action:** `CREATE INDEX CONCURRENTLY IF NOT EXISTS trx_rme_payments_branch_paid_at_idx ON
  trx_rme_payments (branch_id, paid_at DESC) INCLUDE (amount);` — migration-equivalent SQL,
  local DB only.
- **After:** Index present; `indisvalid = t`, `indisready = t`; definition matches the migration
  and the pilot copy exactly.
- **Decision:** **Drift resolved (C6).** No migration-history surgery performed — physical index
  sync only. No code change required.

## Changes Made
- **Local DB sync only** (index created on `asia_dental_lab`).
- **Documentation** (this report).
- **No app code changes.** No migration added. No service/controller/repository edited.
- `.env` (gitignored, never committed) updated with VPS connection details at the operator's
  explicit request — no secret is included in this report.

## Commands Run
```bash
# Branch
git switch -c feature/sprint-68-4-pilot-query-plan-validation-dev-db-index-sync

# Local: confirm drift (migration Ran, index absent, 0 rows)
php artisan migrate:status | grep 680200
psql -d asia_dental_lab -c "SELECT indexname FROM pg_indexes WHERE indexname='trx_rme_payments_branch_paid_at_idx';"

# Local: backup then sync (local dev DB only)
pg_dump -d asia_dental_lab --clean --if-exists --no-owner --no-privileges -f storage/app/backups/local/pre_sprint_68_4_dev_index_sync_asia_dental_lab_<TS>.sql
psql -d asia_dental_lab -c "CREATE INDEX CONCURRENTLY IF NOT EXISTS trx_rme_payments_branch_paid_at_idx ON trx_rme_payments (branch_id, paid_at DESC) INCLUDE (amount);"
psql -d asia_dental_lab -c "SELECT i.indisvalid, i.indisready FROM pg_class c JOIN pg_index i ON i.indexrelid=c.oid WHERE c.relname='trx_rme_payments_branch_paid_at_idx';"

# VPS/pilot: READ-ONLY evidence (SELECT / EXPLAIN only, statement_timeout=10s, lock_timeout=3s)
#   A1 index existence + valid/ready
#   A2 pg_stat_user_tables + table sizes
#   A3 branch ids ; A4 payment min/max/count
#   Q1..Q4 EXPLAIN (ANALYZE, BUFFERS) payment shapes
#   Q5..Q6 EXPLAIN (ANALYZE, BUFFERS) inventory shapes
#   A10 inventory vacuum/analyze stats
# No INSERT/UPDATE/DELETE/DDL, no migrate, no deploy, no cache clear, no restart.
```

## Safety Confirmation
- No VPS deploy.
- No VPS migration.
- No VPS write SQL (SELECT / EXPLAIN only; `statement_timeout=10s`, `lock_timeout=3s`).
- No destructive DB commands (`migrate:fresh` / `db:wipe` / DROP not run).
- No business logic changed (payment, invoice, visit, RME, lab, inventory ledger,
  branch isolation, authorization, KTP/NIK privacy all untouched).
- No PII exposed — evidence is ids, counts, sizes, and query plans only; no patient names,
  KTP/NIK, notes, scans, or credentials in this report.

## Deferred / Next Sprint Recommendation
- **Sprint 68.5 should be report-only / hold.** Pilot data is far below any index-selection or
  sort bottleneck; every reviewed query is sub-millisecond. No grouped-aggregate rewrite of
  `branchPerformance()` is justified yet (the per-branch loop is a query-*count* concern, not a
  plan bottleneck, and pilot branch count is 4). No new index.
- **Re-validate at higher volume:** re-run this exact read-only evidence set after the pilot
  accumulates materially more payments/movements — that is when the 68.2 payment index will
  start being *selected* (not just present). Track the seq→index crossover.
- **Optional pilot housekeeping (not required):** a one-off `VACUUM (ANALYZE)
  trx_inventory_movements` (and `trx_rme_payments`) would populate the visibility map and drop
  Q5's `Heap Fetches` to 0, but is unnecessary at 49/25 rows.
- No commit/push/PR performed — awaiting explicit instruction.
