# DBPERF-1 — PostgreSQL Index Optimization & Query Plan Audit

## Objective

Add an audit-first, safe-index-first PostgreSQL performance governance layer:
a query-plan/index audit command, a documented index candidate inventory,
and (only where evidence justified it) one additive, non-destructive index
migration — wired into CI evidence, release evidence/safety, the deploy
script, and the Foundation Governance Summary.

## Baseline from QUEUE-1

QUEUE-1 (merged `4ce3e2d`, GO-tagged `queue-1-queue-idempotency-outbox-foundation-go`,
deployed to VPS) left the roadmap's `foundation_expansion` track with DBPERF-1
as `next_recommended_sprint`. All foundation GO statuses (DQ, DMO, NSF,
ROADMAP, FEATURE_FLAGS, CACHE_GOVERNANCE, QUEUE_GOVERNANCE, IDEMPOTENCY,
OUTBOX, AUTOMATED_SMOKE, RELEASE_SAFETY, Combined) were GO going into DBPERF-1.

## Prior art this sprint builds on (not duplicated)

This repository already had a separate, already-closed performance track
(NSF-1 through NSF-5, and the "Sprint 68.x" stress-test series) that is
**not** part of `config/foundation_roadmap.php`'s `foundation_expansion`
track but delivered real, merged infrastructure:

- `App\Services\Monitoring\SlowQueryAuditService` / `performance:slow-query-audit`
  — EXPLAIN ANALYZE benchmarks, index inventory, NSF-2 index status tracking.
- `App\Services\Monitoring\RuntimeQueryObservabilityService` /
  `performance:runtime-query-observability` — `pg_stat_statements` reporting.
- NSF-2's safe index pack migration
  (`2026_07_03_200001_add_nsf2_safe_performance_indexes.php`).
- Sprint 68.1/68.2 read-path index migrations on `trx_clinic_visits` and
  `trx_rme_payments`.

DBPERF-1 does **not** re-derive these. It adds the `foundation:*` governance
layer (`config/db_performance_governance.php`,
`App\Services\Foundation\DbPerformanceGovernanceService`,
`foundation:db-performance-check`) that this project's other foundation
sprints (CACHE-1, QUEUE-1) use, wires it into the same evidence/summary/CI/
deploy chain, and uses the discovery process to **confirm** existing index
coverage rather than assume a gap exists.

## Query plan audit policy

- `foundation:db-performance-check` is read-only. By default it only
  validates `config/db_performance_governance.php` completeness and
  cross-checks the `index_candidate_policy` against real migration files —
  no database introspection is required for a normal GO.
- `--include-db-stats` reads `pg_indexes`, `pg_stat_user_tables` (row
  estimates), and probes `pg_stat_user_indexes` / `pg_stat_statements`
  availability. Never selects application row data.
- `--include-query-plan-samples` runs plain `EXPLAIN` (never `ANALYZE`) on a
  small, fixed set of parameterized, representative queries (visit queue,
  receivable list, idempotency expiry sweep) and returns a sanitized plan
  summary string — never row data, never a literal request/PII value.
- On a non-`pgsql` connection (local/CI default test DB is sqlite per
  `phpunit.xml`), stats/plan-sample checks return `WATCH`, not `FAIL` — this
  is expected and non-blocking.

## Index candidate inventory method

Discovery was code-first: `rg` across `app/`, `database/migrations/`,
`routes/`, `tests/` for `where(`, `orderBy(`, `groupBy(`, `join(`,
`whereBetween(`, `whereIn(`, `paginate(` etc. against the target query
families in `config/db_performance_governance.php`, cross-referenced with
every existing migration that creates or extends an index. Each candidate
was recorded in `index_candidate_policy` with an explicit `decision`:
`add_index_now`, `no_action` (already covered), or `defer_to_rpt_1`.

## Safe index migration policy

- Additive only — `CREATE INDEX CONCURRENTLY IF NOT EXISTS`.
- PostgreSQL-only guard (`DB::connection()->getDriverName() !== 'pgsql'` →
  no-op) so sqlite test/CI runs are unaffected.
- `public bool $withinTransaction = false;` — PostgreSQL `CONCURRENTLY`
  cannot run inside a transaction.
- `down()` is `DROP INDEX CONCURRENTLY IF EXISTS` — reversible, but the
  production rollback decision still belongs to a human (see Applied
  indexes below).
- No index rename, no drop of an existing index, no duplicate index name.

## PostgreSQL concurrent index migration policy

Every additive index migration in this project (NSF-2, Sprint 68.1/68.2, and
this sprint's) follows the same shape: `withinTransaction = false`, a
`getDriverName() !== 'pgsql'` no-op guard, and `DB::statement()` with
`CREATE INDEX CONCURRENTLY IF NOT EXISTS`. This avoids long write locks on
production tables and keeps sqlite test runs a safe no-op.

## PII/secrets artifact policy

`config/db_performance_governance.php.artifact_policy.forbidden` bans raw
PII, DB credentials, and full result sets from any DB performance artifact.
`DbPerformanceGovernanceService` never selects application row data — only
`pg_indexes`/`pg_stat_user_tables`/`pg_stat_user_indexes`/`pg_extension`
metadata and sanitized `EXPLAIN`-only plan summaries. The shared
`config/release_evidence.php` `forbidden_patterns`/`forbidden_regex` scan
(DB_PASSWORD, DB_USERNAME, APP_KEY=, 16-digit KTP/NIK-shaped sequences)
applies to the `db-performance-check.json` artifact exactly like every other
evidence artifact.

## Branch filter / index policy

Every `add_index_now`/`no_action` candidate on a branch-scoped table leads
with `branch_id` (or documents why not) — matching the project rule that
branch-scoped queries must never trust request-supplied `branch_id` and must
always be able to use a branch-leading index.

## Inventory ledger query policy

`inventory.current_stock_ledger_sum` and `inventory.stock_card` remain
`SUM(quantity_in - quantity_out)` over `trx_inventory_movements` — DBPERF-1
made no schema or semantic change to inventory ledger queries; the existing
`trx_inventory_movements_branch_location_product_index` composite index
already serves this read path.

## Applied indexes

| Migration | Table | Columns | Reason |
| --- | --- | --- | --- |
| `2026_07_05_200001_add_dbperf1_idempotency_status_expires_at_index` | `sys_idempotency_keys` | `(status, expires_at)` | `IdempotencyService::expireOld()` sweeps `WHERE status = reserved AND expires_at < now()`; previously only separate single-column indexes existed. |

## Deferred candidates

All other `target_query_families` candidates were found **already covered**
by NSF-2 or Sprint 68.1/68.2 migrations (see
`config/db_performance_governance.php.index_candidate_policy` for the full
per-table decision + reason), except:

- `reporting.owner_dashboard` daily payment trend aggregate (~118ms at
  500K-payment scale per the Sprint 68.13 closure report) — **deferred to
  RPT-1** (materialized view / `rpt_*` summary foundation), not an indexing
  gap.

## EXPLAIN / EXPLAIN ANALYZE safety rules

- Default `foundation:db-performance-check` run: no EXPLAIN at all.
- `--include-query-plan-samples`: `EXPLAIN` only, never `ANALYZE`, on a fixed
  small query set — never arbitrary/request-driven SQL.
- `EXPLAIN ANALYZE` (which executes the query) is never run by this
  command in any environment; the existing `performance:slow-query-audit`
  command (NSF-1/2 track) is the tool for local/test-fixture `ANALYZE`
  benchmarking and already guards production execution.

## Release evidence integration

- `config/release_evidence.php`: `db-performance-check.json` added to both
  `ci` and `vps` required artifacts.
- `App\Services\Foundation\ReleaseEvidenceService::buildJobs()`: new job runs
  `foundation:db-performance-check --json` (adds `--include-db-stats` on the
  `vps` profile).

## Release safety integration

`config/release_safety.php.required_pre_deploy_gates` includes
`foundation:db-performance-check`.

## Deploy gate integration

- CI: `.github/workflows/foundation-evidence-gates.yml` runs
  `foundation:db-performance-check` (text + JSON to
  `storage/ci-evidence/db-performance-check.json`) in the release safety gate
  job.
- VPS: `scripts/deploy-vps.sh` runs `foundation:db-performance-check`,
  `--include-db-stats`, and captures JSON to
  `storage/release-evidence/latest/db-performance-check.json`.
- `config/foundation_governance.php.ci_evidence_gates.gates.DBPERF-1`
  registers the artifact for the Foundation Governance Summary's CI gate
  listing.

## GO / WATCH / NO-GO criteria

See `config/db_performance_governance.php` `go_criteria` / `watch_criteria` /
`no_go_criteria`. Summary: GO requires config completeness, no denied
actions, and every `add_index_now` candidate to have a real migration +
rollback note. WATCH is optional `pg_stat_*` unavailability or a non-pgsql
connection. NO-GO is a denied action, a missing migration/rollback note for
an `add_index_now` candidate, or a duplicate/unsafe index name.

## Next sprint

DBPERF-2 — PgBouncer & PostgreSQL Runtime Tuning (`config/foundation_roadmap.php`,
`depends_on: ['DBPERF-1']`). DBPERF-1 intentionally does not touch PgBouncer,
runtime tuning, partitioning, or read-replica routing.
