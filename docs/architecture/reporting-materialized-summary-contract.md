# DaengtisiaMS — Reporting Materialized Summary Contract (ENT-3)

## Status

- Sprint: **ENT-3 — Reporting Materialized Summary Expansion**
- Status: **LOCKED — enterprise reporting summary contract**
- Date: 2026-07-06
- Parent freeze doc: `docs/architecture/enterprise-foundation-freeze-rules.md` (Sections 5, 6, 14)
- Database contract: `docs/architecture/database-performance-contract.md` (DBPERF-R001..DBPERF-R014, especially DBPERF-R004/R014)
- Shipped foundation this contract builds on: **RPT-1** (`docs/architecture/rpt-1-materialized-view-rpt-summary-foundation.md`, `config/reporting_summary_governance.php`, `foundation:reporting-summary-check`)
- Candidate baseline: `docs/architecture/reporting-summary-candidate-inventory.md`
- Roadmap registration: `config/foundation_roadmap.php` (`rules.reporting_materialized_summary_contract_doc`)
- Enforced by: `Ent3ReportingMaterializedSummaryExpansionTest` + `php artisan foundation:roadmap-check --strict`

This document is **governance only**. ENT-3 does not add physical summary tables,
materialized views, or migrations — physical summary objects ship in dedicated
report-summary implementation sprints, each with its own evidence, tests, and GO tag.
The existing RPT-1 physical tables (`rpt_inventory_daily_summaries`,
`rpt_inventory_branch_summaries`, `rpt_inventory_product_summaries`,
`rpt_procurement_daily_summaries`) remain governed by RPT-1 rules and are now also
bound by this contract.

---

## Contract Rules (RPTSUM-R001..RPTSUM-R016)

### RPTSUM-R001 — No repeated raw-transaction scanning rule

Heavy reports and dashboards must not repeatedly scan raw transaction tables
(`trx_*`) per page-load once data volume grows. As volume grows they must move to a
declared `rpt_*` summary, materialized summary, or cached aggregate strategy
(DBPERF-R004).

### RPTSUM-R002 — Report declaration rule

Every report/dashboard must declare, in its spec/PR:
source tables, branch scope, date range filter, grouping dimensions,
sort/pagination behavior, PII exposure level, freshness requirement, and
summary/cache eligibility.

### RPTSUM-R003 — Canonical prefix rule

Summary objects must use canonical prefixes:

- `rpt_*` — report summaries (read-side acceleration only)
- `sys_*` — system/governance metadata
- `stg_*` — staging/import only (never a report source of truth)

### RPTSUM-R004 — Explicit refresh strategy rule

Every summary's refresh strategy must be explicit and one of:

- **synchronous** — only for small, safe, bounded updates
- **queued job** — for heavy refresh work
- **scheduled command** — for periodic refresh
- **manual rebuild command** — always available for recovery

### RPTSUM-R005 — Documented freshness rule

Summary freshness must be documented as one of: **real-time**, **near-real-time**,
**hourly**, **daily**, or **manual/rebuild**. Stale-capable pages must label staleness
(RPT-1 `stale_data_label_required`).

### RPTSUM-R006 — Never-source-of-truth rule

Summary data must never be the source of truth for transactional decisions
(payment amounts, invoice status, stock availability, visit state).

### RPTSUM-R007 — Source-of-truth rule

The source of truth remains the transactional tables, ledger tables, invoices,
visits, and payments (e.g. `trx_rme_invoices`, `trx_rme_payments`,
`trx_clinic_visits`, `trx_inventory_movements`).

### RPTSUM-R008 — Ledger-derived inventory stock rule

Inventory stock remains ledger-derived from `trx_inventory_movements`. Summary
tables may accelerate reads but must never become a mutable stock column or a
writable stock source of truth.

### RPTSUM-R009 — Branch isolation rule

All summary queries must enforce branch isolation (`BranchContext`, never trusting
`branch_id` from request) or be explicit **permission-gated, read-only cross-branch
analytics** (DBPERF-R003).

### RPTSUM-R010 — PII masking rule

Summary tables, reports, and exports must mask PII and must not expose full
KTP/NIK, raw clinical notes, or scanned documents. PII masking is mandatory at
build time, not only at render time.

### RPTSUM-R011 — Idempotent refresh rule

Summary refresh must be idempotent: re-running the same refresh window produces
the same summary rows (upsert/rebuild-by-window, never blind append).

### RPTSUM-R012 — Non-corrupting refresh rule

Summary refresh failure must never corrupt source transaction data. Refresh reads
sources, writes only `rpt_*` objects, and a failed refresh leaves sources untouched.

### RPTSUM-R013 — Observability hook rule

Materialized/summary refresh must plan observability hooks (duration, row counts,
failure logging with OBS-1 request/correlation context) for the ENT-7/ENT-8
observability sprints.

### RPTSUM-R014 — Cache compatibility rule

Cache layer acceleration on top of summaries must be compatible with the ENT-4
Redis Cache Enterprise Policy (standard key prefixes, explicit TTL, clear
invalidation, no raw PII cached).

> **ENT-4 fulfilled (2026-07-06):** summary/report cache acceleration must now follow
> `docs/architecture/redis-cache-enterprise-policy.md`,
> `docs/architecture/cache-ttl-matrix.md`, and
> `docs/architecture/cache-invalidation-matrix.md`.

### RPTSUM-R015 — Queue compatibility rule

Queue-heavy refresh must be compatible with the ENT-5/ENT-6 queue, retry,
idempotency, and outbox foundations (idempotent jobs, retry policy, visible
failed jobs).

### RPTSUM-R016 — Summary object completeness rule

Every new summary object must ship with: an owner module, a refresh command/job,
indexes matching its access pattern, a rebuild procedure, a validation
(reconciliation) query, test coverage, and a rollback/safe disable plan.

---

## Enforcement

1. This document is the canonical reporting summary contract; it may only be changed
   by a dedicated governance sprint.
2. `Ent3ReportingMaterializedSummaryExpansionTest` asserts this contract, its rule IDs,
   the candidate inventory, the freeze-rules and database-contract references, and the
   roadmap registration.
3. `php artisan foundation:roadmap-check --strict` must stay GO with ENT-3 registered.
4. Sprint review checklist: any PR adding a report/dashboard/export or an `rpt_*`
   object is checked against RPTSUM-R001..R016 and DBPERF-R001..R014.
5. RPT-1 runtime policy remains in force: no auto refresh, no summary-as-runtime-source
   switch without a feature flag and reconciliation evidence.

## Relasi Dokumen

- `docs/architecture/enterprise-foundation-freeze-rules.md` — parent freeze rules (Sections 5, 6, 14)
- `docs/architecture/database-performance-contract.md` — ENT-2 database performance contract
- `docs/architecture/reporting-summary-candidate-inventory.md` — ENT-3 candidate baseline
- `docs/architecture/redis-cache-enterprise-policy.md` — ENT-4 cache key, TTL, invalidation, branch-scope, and PII safety policy
- `docs/architecture/cache-ttl-matrix.md` — ENT-4 cache TTL matrix
- `docs/architecture/cache-invalidation-matrix.md` — ENT-4 cache invalidation matrix
- `docs/architecture/rpt-1-materialized-view-rpt-summary-foundation.md` — shipped RPT-1 foundation
- `.cursor/rules/52-reporting-materialized-summary.mdc` — AI-assistant mirror rule
