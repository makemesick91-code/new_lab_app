# DaengtisiaMS — Database Performance Contract (ENT-2)

## Status

- Sprint: **ENT-2 — Database Performance Contract**
- Status: **LOCKED — durable enterprise governance**
- Date: 2026-07-06
- Parent freeze doc: `docs/architecture/enterprise-foundation-freeze-rules.md` (Section 5 — Database Enterprise Rules)
- Architecture baseline: `docs/architecture/enterprise-architecture-baseline-lock.md` (ENT1-R001..ENT1-R014)
- Shipped foundations this contract builds on: **DBPERF-1** (`docs/architecture/dbperf-1-postgresql-index-optimization-query-plan-audit.md`) and **DBPERF-2** (`docs/architecture/dbperf-2-pgbouncer-postgresql-runtime-tuning.md`)
- Hotspot baseline: `docs/architecture/database-performance-hotspot-inventory.md`
- Roadmap registration: `config/foundation_roadmap.php` (`ENT-2`, `rules.database_performance_contract_doc`)
- Enforcement test: `tests/Feature/Architecture/Ent2DatabasePerformanceContractTest.php`

This contract makes the shipped DBPERF-1 index/EXPLAIN audit and DBPERF-2 pooling/runtime
tuning design **binding** for all future work. Every later ENT sprint (especially ENT-3
reporting expansion and ENT-4 cache policy) must comply with the rules below. Violating a
DBPERF rule is a NO-GO condition for the sprint that introduces the violation.

---

## Contract Rules (DBPERF-R001..DBPERF-R014)

### DBPERF-R001 — Declared query pattern rule

Every **new high-traffic query** (list pages, queues, dashboards, worklists, exports) must
state its expected **filter / sort / pagination** pattern in the sprint spec or PR body
before it ships. "High-traffic" means: hit per page-load by pilot roles (Admin Klinik,
Dokter, Kasir, Owner) or run against transaction tables (`trx_*`).

### DBPERF-R002 — Branch scope rule

Branch-owned queries must include the branch scope (via `BranchContext` / repository
branch filtering) as a leading predicate. A query on a branch-owned table without a
branch filter is a defect, not an optimization opportunity. `branch_id` from the request
is never trusted (ENT1-R005).

### DBPERF-R003 — Cross-branch analytics rule

Cross-branch analytics (Owner dashboard, consolidated reports) must be **read-only** and
**permission-gated** (`view_owner_dashboard` or an equivalent explicit permission). No
cross-branch query may perform writes or bypass policy checks.

### DBPERF-R004 — Summary strategy rule

Report/dashboard-heavy queries must move to a **summary table (`rpt_*`), materialized
summary, or cached aggregate** strategy once data volume grows, instead of repeatedly
aggregating raw transaction rows per page-load. ENT-3 executes this expansion; new heavy
reports must document their summary/cache plan at design time.

### DBPERF-R005 — No unbounded list rule

No unbounded list queries on transaction tables. Every list endpoint over `trx_*` data
must paginate (or hard-limit) at the query level. `->get()` without a limit on a
transaction table in a controller/service serving a list page is a contract violation.

### DBPERF-R006 — No N+1 regression rule

No N+1 query regressions. List/detail pages must use eager loading (`with()`),
`withCount()`, or a single aggregate query. A page whose query count scales with row
count fails review.

### DBPERF-R007 — No undocumented full scan rule

No full table scan on large transaction tables without a documented reason. If a
sequential scan is genuinely acceptable (tiny table, one-off admin/report path), the
reason must be written in the sprint spec, PR body, or hotspot inventory.

### DBPERF-R008 — Index-matches-query rule

Indexes must match the actual `WHERE` / `ORDER BY` / `GROUP BY` / `JOIN` patterns of the
queries they serve (per the DBPERF-1 audit method). Do not add speculative indexes for
queries that do not exist; do not ship queries whose hot predicates have no index plan.

### DBPERF-R009 — Additive index migration rule

New indexes ship only as **additive, production-safe migrations** with clear explicit
names. On production-sized tables prefer PostgreSQL `CREATE INDEX CONCURRENTLY` (as done
in `2026_06_30_153729_add_rme_receivables_performance_indexes`). Never `migrate:fresh`,
`db:wipe`, destructive rewrites, or surprise backfills on VPS (ENT1-R008).

### DBPERF-R010 — PII-free performance evidence rule

PII (full KTP/NIK, raw clinical notes, scanned documents) must never appear in query
logs, slow-query captures, EXPLAIN evidence, reports, exports, or performance evidence
docs. Evidence uses masked or synthetic values only.

### DBPERF-R011 — Ledger-derived stock rule

Inventory stock remains **ledger-derived**. Performance work on inventory (current
stock, stock card, valuation, alerts) must optimize the ledger aggregation path
(indexes, summaries, caching) — never by introducing mutable stock columns (ENT1-R009).

### DBPERF-R012 — Hotspot performance evidence rule

Performance evidence (query pattern + index verification, and EXPLAIN output where
warranted) must be captured for the named hotspot domains: **RME visits/queue, RME
patient search, cashier invoices/receivables, inventory stock views, owner dashboard
KPI, lab orders/candidates, and reports/exports**. The living baseline is
`docs/architecture/database-performance-hotspot-inventory.md`.

### DBPERF-R013 — Slow-query triage rule

Slow-request triage must categorize the bottleneck as **DB, PHP, cache, queue, storage,
or network** before choosing a fix. "Add an index" is not the default answer; the
category must be identified from evidence (per the OBS-1/OBS-2 observability
foundations).

### DBPERF-R014 — Downstream reference rule

Future **ENT-3 (reporting/RPT expansion)** and **ENT-4 (enterprise cache policy)** work
must explicitly reference this contract and state which DBPERF rules apply to each new
heavy query, summary, or cache they introduce.

> **ENT-3 fulfilled (2026-07-06):** the reporting materialized summary expansion is now
> locked at `docs/architecture/reporting-materialized-summary-contract.md`
> (RPTSUM-R001..RPTSUM-R016) with its candidate baseline at
> `docs/architecture/reporting-summary-candidate-inventory.md`; RPTSUM rules bind every
> future summary/report to this DBPERF contract (notably DBPERF-R004/R005/R008).

> **ENT-4 fulfilled (2026-07-06):** cache acceleration for DB/report reads is now
> governed by `docs/architecture/redis-cache-enterprise-policy.md`,
> `docs/architecture/cache-ttl-matrix.md`, and
> `docs/architecture/cache-invalidation-matrix.md`. Cache may reduce repeated heavy
> reads, but PostgreSQL transaction, ledger, invoice, visit, payment, and medical
> record tables remain authoritative.

---

## Enforcement

1. This document is the canonical DB performance contract; it may only be changed by a
   dedicated governance sprint.
2. `Ent2DatabasePerformanceContractTest` asserts the contract, its rule IDs, the freeze
   rules reference, the roadmap registration, and the hotspot inventory baseline.
3. `php artisan foundation:roadmap-check --strict` must stay GO with ENT-2 registered.
4. Sprint review checklist: any PR touching queries on `trx_*`/`inv_*`/`rpt_*` tables or
   adding migrations is checked against DBPERF-R001..R014.

## Relasi Dokumen

- `docs/architecture/enterprise-foundation-freeze-rules.md` — parent freeze rules (Section 5)
- `docs/architecture/enterprise-architecture-baseline-lock.md` — ENT-1 architecture baseline
- `docs/architecture/dbperf-1-postgresql-index-optimization-query-plan-audit.md` — shipped index/EXPLAIN audit
- `docs/architecture/dbperf-2-pgbouncer-postgresql-runtime-tuning.md` — shipped pooling/tuning design
- `docs/architecture/database-performance-hotspot-inventory.md` — hotspot inventory baseline (ENT-2)
- `docs/architecture/reporting-materialized-summary-contract.md` — reporting summary contract (ENT-3, RPTSUM-R001..RPTSUM-R016)
- `docs/architecture/redis-cache-enterprise-policy.md` — Redis cache enterprise policy (ENT-4, CACHE-R001..CACHE-R018)
- `docs/architecture/cache-ttl-matrix.md` — ENT-4 cache TTL matrix
- `docs/architecture/cache-invalidation-matrix.md` — ENT-4 cache invalidation matrix
- `docs/architecture/database-scale-governance-rules.md` — database scale governance
