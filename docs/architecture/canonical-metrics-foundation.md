# Canonical Metrics Foundation

**Sprint:** DMO-1  
**Source:** NSF-5 `CanonicalMetricRegistry`  
**Maintained by:** `architecture:dmo-foundation` / `architecture:canonical-metric-reconciliation`

## Registry Summary

| Count | Value |
| --- | --- |
| Metrics | 71 |
| Domains | 6 (rme, cashier, inventory, lab, owner, system) |
| DMO ready | 57 |
| needs_review | 12 |
| duplicate (Owner aliases) | 10 |
| blocked | 2 |
| Conflict groups | 4 |

## Naming Convention

```text
{domain}_{concept}     e.g. total_visits, remaining_receivable, current_stock_qty
owner_{concept}        Owner dashboard aliases (resolved DMO-2 — see canonical-owner-kpi-registry.md)
```

Display labels may differ (`active_receivable`, `low_stock_items`) — reconciliation notes capture aliases.

## Grain Standard

| Grain | Example metrics |
| --- | --- |
| per_branch_snapshot | remaining_receivable, low_stock_count |
| per_branch_per_date_range | total_visits, paid_amount |
| per_branch_per_day | payment_trend, visit_trend |
| per_product_branch_location | current_stock_qty |
| per_payment_batch | carry_over_allocation |
| global_snapshot | lab_orders_active |

## Dimension Standard

branch, region (deferred NDA), date, doctor, treatment, payment_method, product, warehouse/location, batch, status, aging_bucket (computed — DMO-M003).

## Filter Standard

| Filter | Rule |
| --- | --- |
| branch | `BranchContext::requireId()` for operator; Owner may aggregate active branches |
| date | Explicit: visit_date, created_at, paid_at, movement_date, expiry_date |
| status | Exclude cancelled/VOID/DRAFT per metric notes |
| soft_delete | `deleted_at IS NULL` when applicable |
| active | `is_active` on products, locations, branches |

## Source Type Definitions

| Type | Examples |
| --- | --- |
| source_of_truth | total_visits, paid_amount, movement counts |
| derived | remaining_receivable, current_stock_qty, stock_value |
| computed | follow_up_due_count, expiry_alert_count |
| reporting | rpt_inventory_* snapshots |
| telemetry | slow_query_count, pg_stat_top_query |

## Owner KPI Alias Rules

- Owner dashboard uses `owner_*` prefixed metrics as **duplicate** aliases
- DMO-M005: **closed DMO-2** — see `canonical-owner-kpi-registry.md`
- 10 duplicate groups documented in NSF-5 conflicts registry
- New Owner KPIs must reference existing canonical name or register new one

## Blocked Metrics

| Metric | Reason | Resolution |
| --- | --- | --- |
| net_revenue | Not materialized; pilot uses paid_amount | DMO-M001 — define gross vs net in DMO-2 |
| pod_count | POD module/source unconfirmed | DMO-B004 — confirm Delivery/POD entity |

## needs_review Metrics (sample)

active_patients, gross_revenue, receivable_aging_bucket, expiry_alert_count, production_pending_count, QC-related counts.

## Canonical Rules (DMO-R001–R010)

1. One metric = one canonical definition
2. Every metric declares grain
3. Branch-scoped metrics declare branch filter
4. Time-based metrics declare date field
5. Financial metrics declare status rules
6. Inventory stock metrics trace to trx_inventory_movements
7. Receivable metrics specify derived vs persisted source
8. Clinical metrics: counts only, no PHI
9. Owner KPI maps to canonical alias
10. Reports map to entity + metric lineage

## Metric Gaps (DMO-M001 – DMO-M007)

| ID | Area | Gap |
| --- | --- | --- |
| DMO-M001 | cashier | net_revenue not materialized |
| DMO-M002 | rme | active_patients vs unique_patients |
| DMO-M003 | cashier | aging buckets not persisted |
| DMO-M004 | inventory | expiry_alert_count computed only |
| DMO-M005 | owner | Owner KPI alias duplication — **closed DMO-2** |
| DMO-M006 | foundation | treatment/tariff multi-branch |
| DMO-M007 | lab | pod_count / production_pending status |

## Export

```bash
php artisan architecture:canonical-metric-reconciliation --json
php artisan architecture:dmo-foundation --json --include-references
```
