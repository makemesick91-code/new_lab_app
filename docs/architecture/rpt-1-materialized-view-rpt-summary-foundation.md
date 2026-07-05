# RPT-1 Materialized View + rpt_* Summary Foundation

Date: 2026-07-05
Status: Implemented

## Objective

RPT-1 establishes reporting summary governance and materialized-view readiness for DaengtisiaMS. It is a foundation sprint, not a report rewrite sprint.

## Baseline

DBPERF-2 is the preserved baseline: PostgreSQL runtime governance is GO, DB performance governance is GO, PgBouncer cutover is disabled, runtime tuning is recommendation-only, and release evidence/safety gates remain active.

## Existing rpt_* Inventory

Existing inventory analytics summaries are additive derived read models:

- `rpt_inventory_daily_summaries`
- `rpt_inventory_branch_summaries`
- `rpt_inventory_product_summaries`
- `rpt_procurement_daily_summaries`

The source migration is `2026_06_10_100000_create_rpt_inventory_analytics_summary_tables.php`. Inventory stock remains ledger-derived from `trx_inventory_movements`; rpt tables are not source of truth.

No RPT-1 materialized view is created. The owner dashboard daily payment trend remains a deferred candidate from DBPERF-1.

## Governance Policy

Source of truth: `config/reporting_summary_governance.php`.

Command: `php artisan foundation:reporting-summary-check [--json] [--include-db-inventory]`.

Every future summary must define source tables, branch scope, date grain, refresh strategy, freshness SLA, PII policy, reconciliation requirement, feature flag, stale-data behavior, and rollback.

## Materialized View Readiness

Materialized views are evidence-based only. Concurrent refresh defaults off and requires a valid unique index. RPT-1 does not schedule refreshes, start queue workers, add Redis, or run production refresh during clinic hours.

## Refresh Policy

Command: `php artisan foundation:reporting-summary-refresh --dry-run [--json]`.

Dry-run is the default. Execute mode requires both `--execute` and `--confirm`, and remains a no-op readiness result in RPT-1 because no physical reporting summary object is added.

## Branch, PII, And Source Truth

Branch-scoped summaries must include branch isolation and reconciliation. Financial summaries must reconcile to source transaction totals. Inventory summaries must never replace ledger `SUM(quantity_in) - SUM(quantity_out)` truth.

Denied categories include patient identity PII, RME medical record content, raw payment sensitive payload, mutable inventory stock payloads, auth permission decisions, branch runtime context, and private document scans.

## Feature Flags

RPT-1 registers:

- `foundation.reporting.materialized_summary_readiness`
- `foundation.reporting.rpt_summary_governance`
- `foundation.reporting.summary_runtime_reads_enabled`
- `foundation.reporting.summary_auto_refresh_enabled`

Runtime reads and auto refresh default false. No production report source changes in RPT-1.

## Release And Deploy Gates

RPT-1 is captured by:

- CI: `storage/ci-evidence/reporting-summary-check.json`
- CI dry-run: `storage/ci-evidence/reporting-summary-refresh-dry-run.json`
- VPS: `storage/release-evidence/latest/reporting-summary-check.json`
- VPS dry-run: `storage/release-evidence/latest/reporting-summary-refresh-dry-run.json`
- Foundation Summary: `REPORTING_SUMMARY`
- Release safety required pre-deploy gates

## Deferred Candidates

- `reporting.owner_daily_payment_trend`: DBPERF-1 deferred candidate, approximately 118ms at 500K-payment scale.
- `reporting.owner_receivable_aging_snapshot`: requires financial aging reconciliation fixtures.
- `inventory.low_stock_summary`: existing rpt inventory summaries may be standardized under this governance later.

## GO / WATCH / NO-GO

GO: governance config complete, commands pass, risky runtime flags default false, release evidence captures artifacts, Foundation Summary includes REPORTING_SUMMARY, no runtime source switch, no auto refresh, no PII.

WATCH: optional materialized views absent, DB inventory unavailable in a non-production test environment, or physical summary deferred with documented reason.

NO-GO: runtime summary reads enabled by default, auto refresh enabled by default, PII category allowed, concurrent materialized refresh without unique index, or missing source/refresh/staleness/branch/reconciliation policy.

## Next Sprint

STORAGE-1 — Object Storage Readiness.
