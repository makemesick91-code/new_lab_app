# Sprint 68.3 — Query Plan Evidence & Aggregation Optimization

## Scope
- Evidence-first PostgreSQL query-plan review of the Sprint 68.2 read-path bottlenecks.
- Verify whether the Sprint 68.2 payment index is actually used by the planner.
- Implement at most one or two safe, evidence-backed read-path optimizations **only if** plans prove them needed.
- Read-path only. **No business logic changes.** No payment/invoice/visit/RM/lab/inventory-ledger/branch-isolation semantics touched.

## Environment
| Item | Value |
|---|---|
| Branch | `feature/sprint-68-3-query-plan-evidence-aggregation-optimization` |
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Commit (base HEAD) | `f365eef` (Sprint 68.2 merge `f365eefc3d…`) |
| Laravel | 12.61.0 |
| PHP | 8.5.4 |
| DB engine | PostgreSQL 18.4 |
| Dataset | **local dev/stress — see limitation below** |
| Date | 2026-07-01 |

### Critical dataset limitation (evidence method)
Both local databases on the dev instance carry the full schema but **zero business
data**:

| DB | payments | invoices | visits | inv_movements | 68.2 index present |
|---|---|---|---|---|---|
| `asia_dental_lab` (app `.env`) | 0 | 2 | 2 | 4 | **No** |
| `daengtisia_stress` | 0 | 0 | 0 | 0 | **Yes** |

- The Sprint 68.2 migration is recorded as `Ran` in `asia_dental_lab` (`migrate:status`),
  but the index `trx_rme_payments_branch_paid_at_idx` is **physically absent** there.
  The index **is** present and **valid** in `daengtisia_stress`. The stress DB is the one
  that actually received the 68.2 migration; the app `.env` DB has the migration row but
  not the object (likely re-seeded / out of sync). This is a local-environment drift note
  only — the **production/pilot** index was created by the deployed migration and is out of
  scope to re-verify here (no VPS access requested).
- Because both tables are empty, a plain `EXPLAIN` would always show trivial seq scans and
  prove nothing. To obtain **real planner evidence at representative scale without
  persisting any data**, each plan was captured inside a single transaction:
  `BEGIN → SET LOCAL session_replication_role = replica (bypass FK triggers) →
  INSERT … generate_series → ANALYZE → EXPLAIN (ANALYZE, BUFFERS) → ROLLBACK`.
  Post-run row counts re-verified `0` for both tables. **No data mutated, nothing
  committed, FK/business triggers never fired against persisted rows.**
- Synthetic scale: 30,000 `trx_rme_payments` rows across 4 branches over ~180 days;
  40,000 `trx_inventory_movements` rows across 4 branches × ~500 products.
- The soft-delete predicate `deleted_at IS NULL` (added by Eloquent `SoftDeletes`) was
  included in every payment EXPLAIN so the plans match the real query shapes.

## Sprint 68.2 Index Verification
- **Index:** `trx_rme_payments_branch_paid_at_idx` —
  `(branch_id, paid_at DESC) INCLUDE (amount)`.
- **Exists:** Yes, in `daengtisia_stress` (the DB that received the migration).
- **Valid / ready:** Yes (`indisvalid = t`, `indisready = t`), built with
  `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, pgsql-guarded, `$withinTransaction = false`.
- **Used by plan:** **Yes** — chosen for all branch-filtered payment aggregates and for
  the branch-filtered payment report ordering (see Q1–Q4 below).
- **Evidence summary:** the planner uses the index via Bitmap Index Scan for
  branch + `paid_at` range aggregates, and via an ordered Index Scan (no sort node) for
  the `branch_id = X ORDER BY paid_at DESC LIMIT 50` report path.

## Query Plans Reviewed
| ID | Area | Query Shape | Dataset | Plan Summary | Index Used | Runtime | Buffers | Decision |
|---|---|---|---|---|---|---|---|---|
| Q1 | Owner KPI period SUM | `branch_id IN (…) AND paid_at BETWEEN … SUM(amount)` | 30k synth | Bitmap Index Scan → Bitmap Heap Scan → Aggregate | **Yes** `…branch_paid_at_idx` | 1.57 ms | hit=310 | Keep |
| Q2 | Owner daily trend | `branch_id IN (…) AND paid_at BETWEEN … SELECT paid_at, amount` (PHP groups) | 30k synth | Bitmap Index Scan → Bitmap Heap Scan | **Yes** `…branch_paid_at_idx` | 1.33 ms | hit=310 | Keep |
| Q3 | branchPerformance revenue | `branch_id = X AND paid_at BETWEEN … SUM(amount)` | 30k synth | Bitmap Index Scan → Aggregate | **Yes** `…branch_paid_at_idx` | 0.48 ms | hit=266 | Keep |
| Q4 | Payment report (branch-filtered) | `branch_id = X ORDER BY paid_at DESC LIMIT 50` | 30k synth | **Ordered Index Scan, no sort** | **Yes** `…branch_paid_at_idx` | 0.05 ms | hit=52 | Keep |
| Q4b | Payment report (all branches) | `(no branch) ORDER BY paid_at DESC LIMIT 50` | 30k synth | Seq Scan → top-N heapsort | No (cannot — no leading-col predicate) | 6.24 ms | hit=518 | Acceptable / defer |
| INV-Q1 | Inventory current stock | `branch_id = X … SUM(in)-SUM(out) GROUP BY product_id` | 40k synth | Bitmap Index Scan (`branch…`) → HashAggregate | branch index, **not** covering (see note) | 3.40 ms | hit=581 | Keep, defer |
| INV-Q2 | Inventory valuation | `branch_id = X SUM(quantity_in*unit_cost)` | 40k synth | Bitmap Index Scan → Aggregate | branch index | 3.00 ms | hit=578 | Keep, defer |

## Finding 1 — Payment KPI index usage (F1 verification)
- **Route/page:** Owner dashboard (`dashboard` → `HomeDashboardController` → owner-kpi partial).
- **Service:** `OwnerDashboardKpiService::metrics()` (period SUM, lines 112–113),
  `dailyPaymentTrend()` (lines 260–264), via `paymentQuery()`.
- **Query:** `branch_id IN (…) AND paid_at BETWEEN a AND b AND deleted_at IS NULL` → `SUM(amount)`
  / `SELECT paid_at, amount`.
- **Plan evidence:** Q1, Q2 — both use `trx_rme_payments_branch_paid_at_idx` (Bitmap Index
  Scan keyed on `(branch_id, paid_at)`); 1.3–1.6 ms over a 5,177-row March slice of 30k.
- **Decision:** **Index confirmed used and effective. No change.** Note: the plan is a
  Bitmap *Heap* Scan (not Index-Only) because `deleted_at IS NULL` is not in the index, so
  the `INCLUDE (amount)` payload does not currently yield index-only aggregation. Adding
  `deleted_at` to the index would enable index-only scans, but the heap recheck cost here is
  negligible (sub-2 ms) — **not justified, deferred as a micro-optimization only.**

## Finding 2 — Payment report sort/filter
- **Route/page:** RME payment report (`RmeReportController@payments` / `paymentsExport` /
  `paymentsPrint`).
- **Service:** `RmeReportController::paymentReportQuery()` (lines 229–257), sorted
  `->latest('paid_at')` (i.e. `ORDER BY paid_at DESC`).
- **Query:** branch-filtered listing `branch_id = X AND deleted_at IS NULL ORDER BY paid_at DESC`.
- **Plan evidence:** Q4 — ordered **Index Scan** on `…branch_paid_at_idx`, **no Sort node**,
  0.05 ms for the first 50 rows. The `(branch_id, paid_at DESC)` key order matches the
  report's filter + sort exactly. Q4b shows the all-branches case (no `branch_id` filter)
  falls back to Seq Scan + top-N heapsort (6.24 ms at 30k) — the index cannot help a query
  with no leading-column predicate, and a top-N sort of 30k rows in ~6 ms is acceptable.
- **Decision:** **Index confirmed used for the primary (branch-filtered) path. No change.**
  Date filters in this report are applied on `clinic_visit.visit_date` via `whereHas`
  (not on `paid_at`), so they do not change the `paid_at` ordering benefit.

## Finding 3 — Owner branchPerformance aggregation (F2)
- **Current behavior:** `OwnerDashboardKpiService::branchPerformance()` (lines 176–224)
  loads active branches, then **loops per branch** running ~5 queries each:
  visits `count`, new-patients `count`, revenue `SUM(amount)` (Q3 shape),
  receivable (`withSum` over UNPAID/PARTIAL invoices), and follow-up-due
  (`whereHas('latestFollowUp', …)->count()`).
- **Query count estimate:** ~`5 × N` branches (N is small in the pilot — a handful of
  RME-enabled branches).
- **Plan evidence:** the heaviest per-branch query (revenue) is Q3 — 0.48 ms using the 68.2
  index. Each individual statement is sub-millisecond at 30k-row scale.
- **Safe optimization possible:** Partially. Revenue / visit / new-patient counts could be
  collapsed into single `GROUP BY branch_id` queries. However `receivable` uses an in-PHP
  `withSum` reduction and `follow_up_due` uses `whereHas('latestFollowUp')` — both carry
  ordering/semantic nuance the task explicitly flags ("Preserve latestFollowUp semantics",
  "Do not rewrite if risk is high").
- **Decision:** **Deferred (report-only).** The per-branch loop is a query-*count* pattern,
  not an index/seq-scan problem; every statement is already index-served and sub-ms, and the
  pilot branch count is tiny. A grouped-aggregate rewrite is a behavior-adjacent change that
  needs (a) pilot-scale evidence that the loop is actually hot and (b) old/new parity tests
  for `receivable` and `follow_up_due`. Diff/risk outweighs current benefit. See Sprint 68.4.

## Finding 4 — Inventory ledger aggregate (F3)
- **Current behavior:** `InventoryAnalyticsSummaryRefreshService` computes current stock as
  `SUM(quantity_in) - SUM(quantity_out) GROUP BY product_id WHERE branch_id = X`
  (`stockSubquery()` / `stockByProductMap()`, lines 497–525) and valuation as
  `SUM(quantity_in * unit_cost)`.
- **Existing indexes:** `trx_inventory_movements` already carries a rich set, including
  `trx_inv_movements_branch_product_covering_index (branch_id, product_id) INCLUDE
  (quantity_in, quantity_out)` — purpose-built for exactly this aggregate.
- **Plan evidence:** INV-Q1 — Bitmap Index Scan on a branch-leading index → HashAggregate,
  3.40 ms over 10k branch rows of 40k. The planner did **not** select the covering index for
  an index-only scan. **Caveat:** rows inserted inside the evidence transaction have an
  **unset visibility map** (never VACUUMed), which disqualifies index-only scans — so this
  synthetic harness *cannot* exercise the covering index's index-only benefit. This is a
  method artifact, **not** evidence the covering index is wrong.
- **Safe optimization / new index possible:** **No new index.** A covering index for this
  pattern already exists; on real, VACUUMed, pilot-scale data it should serve an index-only
  scan. Adding another index would be speculative and duplicative.
- **Decision:** **Deferred.** Needs real (VACUUMed, pilot-scale) `trx_inventory_movements`
  data to measure whether the existing covering index yields index-only scans in practice.
  No change this sprint.

## Changes Made
- **Code: none.** Documentation only (this report).
- No migration added. No index added. No service/controller/repository edited.

## Tests / Commands
```bash
# Branch
git switch -c feature/sprint-68-3-query-plan-evidence-aggregation-optimization

# Index / dataset verification (read-only)
psql -d daengtisia_stress -c "SELECT indisvalid, indisready FROM pg_class c JOIN pg_index i ON i.indexrelid=c.oid WHERE c.relname='trx_rme_payments_branch_paid_at_idx';"

# Evidence harness — synthetic rows in a transaction, EXPLAIN, ROLLBACK (no persistence)
psql -d daengtisia_stress -f scratchpad/explain_payments.sql      # Q1–Q4b
psql -d daengtisia_stress -f scratchpad/explain_inventory.sql     # INV-Q1, INV-Q2
# Post-rollback counts re-verified = 0 for both tables.
```
- No application tests run — no application code changed.
- No `pint` / `git diff --check` code impact — only a new doc file added.

## Risks
- **Local index drift:** `asia_dental_lab` (app `.env` DB) shows the 68.2 migration as `Ran`
  but lacks the physical index. Harmless locally (empty DB) but worth re-syncing the dev DB
  (`migrate:status` vs. actual objects) before the next stress run so local benchmarks reflect
  production. Production/pilot index integrity is unaffected by this and was not touched.
- **Synthetic-data plans** approximate, not reproduce, pilot reality (data distribution,
  visibility map, autovacuum). Conclusions are directional; the payment index conclusion is
  strong (clear index selection), the inventory index-only question is explicitly unresolved.

## Deferred Items
- **F2 grouped-aggregate rewrite** of `branchPerformance()` — defer until pilot-scale evidence
  shows the per-branch loop is hot; requires parity tests for `receivable` + `follow_up_due`.
- **F3 inventory covering-index validation** — defer until VACUUMed pilot-scale movement data
  exists; verify the existing `…branch_product_covering_index` produces index-only scans.
- **Optional micro-opt:** adding `deleted_at` to `…branch_paid_at_idx` for index-only payment
  aggregation — not justified at current cost.

## Sprint 68.4 Recommendation
1. Re-run these exact EXPLAIN shapes against **real VPS/pilot data** (read-only, with
   `statement_timeout`) to confirm production plans match this synthetic evidence — especially
   the inventory index-only question.
2. Sync the local `asia_dental_lab` dev DB so its physical indexes match `migrate:status`.
3. Only if pilot data proves `branchPerformance()` is hot: implement the grouped-aggregate
   refactor for visits/new-patients/revenue **with old/new parity tests**, leaving
   `receivable` and `latestFollowUp`-based `follow_up_due` semantics untouched.
4. Hold on any new index until pilot EXPLAIN shows a real seq-scan/sort bottleneck not covered
   by existing indexes.

## Confirmations
- No deploy performed.
- No destructive DB commands used (`migrate:fresh` / `db:wipe` / DROP not run); all writes were
  rolled back; post-run row counts re-verified `0`.
- No business logic changed (payment, invoice, visit completion, RM finalization, lab
  conversion, inventory ledger, branch isolation all untouched).
- No PII exposed (synthetic data only; no KTP/NIK/patient names/notes/scans in this report).
- No speculative index added.
