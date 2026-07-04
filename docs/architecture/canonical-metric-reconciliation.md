# Canonical Metric Reconciliation

**Sprint:** NSF-5  
**Purpose:** Bridge document before DMO-1 unified Owner KPI metric registry.  
**Maintained by:** `php artisan architecture:canonical-metric-reconciliation`

## 1. Purpose

Map KPIs, reports, dashboards, and exports to canonical metric names, formulas, grains, dimensions, source tables, and DMO readiness — without changing business calculations.

## 2. Scope

- Read-only metric inventory across RME, cashier/receivable, inventory, lab, owner dashboard, and system telemetry
- Source-of-truth vs derived vs computed vs reporting vs telemetry classification
- Consumer routes/services and known test coverage
- Conflict and gap documentation for DMO-1

**Out of scope:** schema changes, new features, row-level data export, formula changes.

## 3. Metric Registry Principles

1. One `canonical_metric_name` per business concept (snake_case).
2. Every metric declares `source_type`, `grain`, `dimensions`, and `filters`.
3. Derived metrics must list upstream source tables explicitly.
4. Owner dashboard aliases are documented as duplicates until DMO-1 unification.
5. Command output never includes patient names, KTP/NIK, diagnosis, or raw financial rows.

## 4. Canonical Metric Naming Convention

```text
{domain}_{concept}   e.g. remaining_receivable, current_stock_qty
owner_{concept}      Owner dashboard aliases (duplicate until DMO-1)
```

Display labels may differ (`active_receivable`, `low_stock_items`) — reconciliation notes capture aliases.

## 5. Grain and Dimension Standards

| Grain | Example metrics |
| --- | --- |
| `per_branch_snapshot` | remaining_receivable, low_stock_count |
| `per_branch_per_date_range` | total_visits, paid_amount |
| `per_branch_per_day` | payment_trend, visit_trend |
| `per_product_branch_location` | current_stock_qty |
| `per_payment_batch` | carry_over_allocation |
| `global_snapshot` | lab_orders_active (lab global per Sprint 23.5) |

**Dimensions:** branch, date, status, doctor, treatment, payment_method, product, location, warehouse, batch, aging_bucket.

## 6. Branch / Date / Status Filter Standards

| Rule | Application |
| --- | --- |
| Branch | Operator views: `BranchContext::requireId()`. Owner KPI: active branches aggregate. |
| Date | Explicit per metric: `visit_date`, `created_at`, `paid_at`, `movement_date`, `expiry_date`. |
| Status | Visits exclude `cancelled` where noted; invoices exclude `VOID`/`DRAFT` for billable. |
| Soft delete | `deleted_at IS NULL` when model uses SoftDeletes. |
| Active | `is_active` on products, locations, branches. |

## 7. Source-of-Truth vs Derived / Computed / Reporting / Telemetry

| Type | Examples |
| --- | --- |
| **source_of_truth** | total_visits, paid_amount, trx_inventory_movements counts |
| **derived** | remaining_receivable, current_stock_qty, stock_value |
| **computed** | follow_up_due_count, expiry_alert_count, collection_rate |
| **reporting** | rpt_inventory_* snapshots (when used) |
| **telemetry** | slow_query_count, pg_stat_top_query |

## 8. Sensitive Data and Privacy Rules

| Class | Metrics | Handling |
| --- | --- | --- |
| **PII** | new_patients, total_visits | Aggregates only; no names in command output |
| **PHI** | medical_records, odontogram_count | Counts only |
| **financial** | paid_amount, remaining_receivable | Aggregates only |
| **internal** | inventory movement counts | Policy-gated |
| **telemetry** | pg_stat metrics | No query text with PHI |

## 9. RME Metrics

| Metric | Source | Formula (summary) |
| --- | --- | --- |
| total_visits | trx_clinic_visits | COUNT status != cancelled, visit_date in range |
| new_patients | mst_patients | COUNT created_at in range |
| completed_visits | trx_clinic_visits | COUNT status = completed |
| follow_up_due_count | trx_rme_invoices + follow_ups | UNPAID/PARTIAL with overdue follow-up date |

**Needs review:** active_patients, unique_patients, new_visits (no single persisted definition).

## 10. Cashier / Receivable Metrics

| Metric | Source | Formula (summary) |
| --- | --- | --- |
| paid_amount | trx_rme_payments | SUM(amount) by paid_at |
| remaining_receivable | trx_rme_invoices | SUM(grand_total - payments) UNPAID/PARTIAL |
| carry_over_allocation | trx_rme_payments | payment_batch_uuid cross-invoice allocation |
| collection_rate | payments + invoices | paid / billable * 100 |

**Receivable is derived** from invoice/payment state — not a separate table (DMO-003).

**Blocked:** net_revenue — not materialized in pilot.

## 11. Inventory Metrics

| Metric | Source | Formula (summary) |
| --- | --- | --- |
| current_stock_qty | trx_inventory_movements | SUM(in) - SUM(out) per product/location/batch |
| stock_value | movements + products | derived_stock × average_cost |
| low_stock_count | products + movements | At/below reorder_point or minimum_stock |
| expiry_alert_count | inv_inventory_batches | Computed from expiry_date vs today/window |

**Inventory stock is ledger-derived** — no mutable stock columns (Sprint 12).

**Expiry alert is computed** at read time (DMO-004) — not persisted.

## 12. Lab Metrics

| Metric | Source | Notes |
| --- | --- | --- |
| lab_order_count | trx_lab_orders | Branch-scoped |
| lab_orders_active | trx_lab_orders | Global lab; excludes closed statuses |
| qc_pass_count / qc_fail_count | trx_lab_quality_controls | DMO-002 naming alignment |

**Blocked:** pod_count — POD field not standardized.

## 13. Owner Dashboard Metrics

`OwnerDashboardKpiService` is a **multi-service consumer**:

| Owner key | Canonical metric | Upstream |
| --- | --- | --- |
| total_revenue | paid_amount | trx_rme_payments |
| active_receivable | remaining_receivable | trx_rme_invoices |
| low_stock_items | low_stock_count | InventoryAnalyticsRepository |
| follow_up_due | follow_up_due_count | receivable follow-ups |

Owner aliases are marked `duplicate` until DMO-1 unified registry (DMO-005).

## 14. System / Monitoring Metrics

| Metric | Command | Source |
| --- | --- | --- |
| slow_query_count | performance:slow-query-audit | Static audit + pg catalog |
| runtime_query_total_time | performance:runtime-query-observability | pg_stat_statements |
| pg_stat_top_query | performance:runtime-query-observability | pg_stat_statements top N |

Evidence files: `storage/app/performance/*.json` — no PHI.

## 15. Conflicts and Duplicate Definitions

| Conflict group | Issue | Resolution |
| --- | --- | --- |
| remaining_receivable / active_receivable / owner_receivable_total | Same formula | Canonical: remaining_receivable |
| paid_amount / total_revenue / owner_total_revenue | Owner uses payment sum as revenue | Document gross vs collected |
| low_stock_count / low_stock_items | Array key vs metric name | Unify to low_stock_count |
| follow_up_due_count / overdue_receivable_count | Overlapping semantics | Single metric in DMO-1 |

## 16. Gaps and Ambiguities

| ID | Gap | Severity |
| --- | --- | --- |
| DMO-M001 | net_revenue not materialized | medium |
| DMO-M002 | active_patients / unique_patients definitions | medium |
| DMO-M003 | receivable_aging_bucket no persisted table | medium |
| DMO-M004 | expiry_alert_count computed only | low |
| DMO-M005 | Owner KPI duplicate aliases | medium |
| DMO-M006 | Treatment/tariff multi-branch boundary | medium |
| DMO-M007 | pod_count / production_pending status mapping | low |

## 17. DMO-1 Metric Registry Handoff

1. Adopt `canonical_metric_name` from `CanonicalMetricRegistry` as ontology seed.
2. Deprecate Owner dashboard duplicate keys gradually.
3. Add unified metric dimension model (branch, date grain per metric).
4. Resolve net_revenue vs paid_amount before financial DMO pack.
5. Cross-link entities via `architecture:canonical-entity-inventory --include-entity-reference`.

## 18. NSF-6 / NDA Handoff Notes

- Metric command is read-only; safe for VPS evidence capture.
- No migrations in NSF-5 — rollback is git revert only.
- Graphify updated locally for metric/report dependency navigation.
- Full machine-readable registry: `storage/app/architecture/nsf5-canonical-metric-reconciliation.json`.
