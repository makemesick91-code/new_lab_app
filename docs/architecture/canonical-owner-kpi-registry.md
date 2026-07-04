# Canonical Owner KPI Registry (DMO-2)

## 1. Purpose

Unify Owner dashboard KPI aliases into one canonical registry so future dashboard/report changes reference a single source of truth. **No UI label or formula changes** in DMO-2 — alias resolution is documentation + machine-readable registry only.

## 2. Owner KPI canonical naming

Pattern: `owner_{concept}` — e.g. `owner_total_revenue`, `owner_visit_count`.

Command: `php artisan architecture:owner-kpi-registry [--json] [--output=storage/app/architecture/dmo2-owner-kpi-registry.json]`

## 3. Current duplicate alias problem

NSF-5 identified duplicate keys between `OwnerDashboardKpiService::metrics()` array keys and domain canonical metrics (`paid_amount` vs `total_revenue`, `active_receivable` vs `remaining_receivable`, etc.). DMO-1 deferred this as **DMO-M005**.

## 4. Canonical Owner KPI registry

| Canonical KPI | Service key (unchanged) | Source canonical metric |
| --- | --- | --- |
| `owner_total_revenue` | `total_revenue` | `paid_amount` |
| `owner_paid_amount` | — | `paid_amount` |
| `owner_receivable_total` | `active_receivable` | `remaining_receivable` |
| `owner_receivable_invoice_count` | `unpaid_invoices` | `receivable_count` |
| `owner_visit_count` | `total_visits` | `total_visits` |
| `owner_patient_count` | `new_patients` | `new_patients` |
| `owner_inventory_value` | `stock_value` | `stock_value` |
| `owner_low_stock_count` | `low_stock_items` | `low_stock_count` |
| `owner_follow_up_count` | `follow_up_due` | `follow_up_due_count` |
| `owner_lab_order_count` | `lab_orders_active` | `lab_orders_active` |
| `owner_collection_rate` | `collection_rate` | `collection_rate` |
| `owner_runtime_query_risk` | — | `runtime_query_total_time` |

**Count:** 12 canonical Owner KPIs (10 executive cards + collection rate + telemetry).

## 5. Alias map

Resolved aliases live in `OwnerKpiRegistryService::aliasMap()` and command output `alias_map[]` with `alias_of` + `status: resolved`.

## 6. Source canonical metric mapping

See [canonical-metrics-foundation.md](canonical-metrics-foundation.md) and `CanonicalMetricRegistry`. Owner KPIs are **derived views** over domain metrics — not separate formulas.

## 7. Formula / grain / dimension / filter mapping

Each Owner KPI entry declares `grain`, `dimensions`, `filters`, and `formula_reference` mirroring the underlying service logic in `OwnerDashboardKpiService`.

## 8. Sensitivity and privacy rules

| KPI area | Classification |
| --- | --- |
| Revenue, receivable, collection | `financial` |
| Visits, new patients | `PII` (counts only — no names) |
| Inventory value | `financial` |
| Low stock | `internal` |
| Runtime query risk | `telemetry` |

Command output: **no row-level data**, no KTP/NIK, no clinical notes.

## 9. Dashboard/report consumers

| Consumer | Route / artifact |
| --- | --- |
| `HomeDashboardController` | `dashboard` |
| `OwnerDashboardKpiService` | KPI aggregation |
| `resources/views/dashboards/owner-kpi.blade.php` | Executive KPI cards |
| Performance commands | `owner_runtime_query_risk` telemetry |

## 10. Blocked / needs_review KPIs

**Blocked (not Owner KPI):** `net_revenue`, `pod_count`

**Needs review:** `gross_revenue`, `receivable_aging_bucket`, `overdue_receivable_count` (superseded by `owner_follow_up_count`)

## 11. DMO-M005 closure decision

**CLOSED in DMO-2.** Aliases documented and encoded in `OwnerKpiRegistryService` + `architecture:owner-kpi-registry`. UI display keys preserved.

## 12. Handoff to DMO-3 / NDA

- Lineage automation for report/export consumers (DMO-B002)
- `net_revenue` canonical definition (DMO-M001)
- Receivable aging buckets (DMO-M003)
- National `region` dimension (DMO-B003)
