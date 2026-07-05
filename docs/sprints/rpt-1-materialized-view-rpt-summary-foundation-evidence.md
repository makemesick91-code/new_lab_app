# RPT-1 Materialized View + rpt_* Summary Foundation Evidence

Status: LOCAL IMPLEMENTATION IN PROGRESS
Date: 2026-07-05

## Summary

RPT-1 adds reporting summary governance, materialized-view readiness policy, dry-run refresh readiness, feature flags, release evidence integration, CI/deploy gates, Foundation Summary integration, and permanent DaengtisiaMS rules.

No report read path was switched to rpt summaries. No materialized view or heavy refresh was created. No auto schedule, queue worker, Redis, destructive migration, or PII summary payload was introduced.

## Current Evidence

- Reporting summary governance config: `config/reporting_summary_governance.php`
- Governance command: `php artisan foundation:reporting-summary-check`
- DB inventory command: `php artisan foundation:reporting-summary-check --include-db-inventory`
- Refresh readiness command: `php artisan foundation:reporting-summary-refresh --dry-run`
- Foundation summary section: `REPORTING_SUMMARY`
- Next sprint: STORAGE-1 — Object Storage Readiness

## Existing rpt_* Inventory

- `rpt_inventory_daily_summaries`
- `rpt_inventory_branch_summaries`
- `rpt_inventory_product_summaries`
- `rpt_procurement_daily_summaries`

No materialized views were required for RPT-1 GO.

## Deferred Candidates

- `reporting.owner_daily_payment_trend`
- `reporting.owner_receivable_aging_snapshot`
- `inventory.low_stock_summary`

## Final Deployment Fields

To be filled after PR merge, GO tag, and VPS deploy:

- PR:
- Merge commit:
- GO tag:
- VPS previous HEAD:
- VPS deployed HEAD:
- Backup path and size:
- CI checks:
- Reporting summary governance:
- rpt_* inventory:
- Materialized view readiness:
- Summary refresh dry-run:
- Release evidence:
- Release safety:
- Automated smoke:
- DQ/DMO/NSF/ROADMAP/Combined:
- Evidence commit:
- Cleanup/no-leftover-process result:
