# Data Lineage & Governance

**Sprint:** DMO-1  
**Maintained by:** `DmoOntologyRegistry` via `architecture:dmo-foundation`

## Lineage Model

Lineage chains document: **canonical entity → canonical metric → consumer** (report, dashboard, export, command).

## Documented Lineage Chains

| Entity | Metric | Consumer | Domain |
| --- | --- | --- | --- |
| Patient | new_patients | Owner Dashboard | owner |
| Clinic Visit | total_visits | Owner Dashboard / RME reports | rme |
| RME Invoice | remaining_receivable | Receivable report / Owner KPI | cashier |
| RME Payment | paid_amount | Owner Dashboard / cashier | cashier |
| Inventory Movement | current_stock_qty | Inventory dashboard / reports | inventory |
| Inventory Movement | stock_value | Inventory reports / Owner KPI | inventory |
| Lab Order | lab_orders_active | Owner Dashboard / lab queue | lab |
| Performance Runtime Evidence | pg_stat_top_query | NSF-3 observability | system |
| NSF Foundation Governance | nsf_governance_check | NSF-6 application rules | system |
| Owner Dashboard KPI | owner_* canonical KPIs | dashboard route | owner |

## Owner Dashboard Lineage

```
BranchContext / active branches
  → OwnerDashboardKpiService
    → domain metrics (total_visits, paid_amount, remaining_receivable, ...)
    → canonical Owner KPI registry (DMO-2)
  → resources/views/dashboards/owner-kpi.blade.php
```

**Rule:** Future Owner KPI changes must update `CanonicalMetricRegistry` and pass metric reconciliation command.

## Inventory Ledger Lineage

```
inv_products + inv_inventory_locations + inv_inventory_batches
  → trx_inventory_movements (quantity_in, quantity_out)
    → InventoryStockService (SUM aggregation)
      → current_stock_qty, stock_value, low_stock_count metrics
        → inventory.dashboard, inventory.reports.*, Owner KPI
```

**Rule:** No lineage path may introduce mutable `current_stock` column (DMO-R006).

## Receivable Lineage

```
trx_rme_invoices (UNPAID/PARTIAL, remaining>0)
  → derived Receivable entity (no separate table)
    → remaining_receivable, follow_up_due_count metrics
      → rme.receivables.*, Owner KPI, receivable reports
```

**Rule:** Aging buckets are computed at read time — DMO-M003 tracks persistence decision.

## Performance Telemetry Lineage

```
PostgreSQL pg_stat_statements
  → performance:runtime-query-observability command
    → storage/app/performance/*.json
      → pg_stat_top_query, slow_query_count metrics (telemetry)
```

**Rule:** Query text must not contain PHI; command output is aggregate only.

## Privacy and Audit Rules

| Rule | Enforcement |
| --- | --- |
| No patient names in commands/reports | Privacy flags in all architecture commands |
| KTP/NIK masked in UI | Patient entity privacy_notes |
| PHI counts only | Medical Record, Odontogram metrics |
| Financial aggregates only | No row-level invoice/payment in JSON exports |
| Branch isolation | BranchContext on all operational queries |
| Audit trail | sys_audit_logs entity; inventory activity logs |

## Change Governance

Future metric or formula changes require:

1. Update `CanonicalMetricRegistry` (or entity registry for new tables)
2. Run `architecture:canonical-metric-reconciliation` and `architecture:dmo-foundation`
3. Add/update Pest tests for affected domain
4. Document in sprint evidence with GO/NO-GO
5. No formula change without explicit sprint approval
6. Inventory changes must preserve ledger derivation
7. RME/cashier changes must preserve branch isolation and privacy rules

## Governance Rules Registry

| ID | Rule |
| --- | --- |
| DMO-R001 | One metric, one canonical definition |
| DMO-R002 | Grain required |
| DMO-R003 | Branch filter for branch-scoped metrics |
| DMO-R004 | Date field for time-based metrics |
| DMO-R005 | Status rules for financial metrics |
| DMO-R006 | Ledger-derived inventory stock |
| DMO-R007 | Receivable derived source documented |
| DMO-R008 | No PHI in clinical aggregates |
| DMO-R009 | Owner KPI → canonical alias |
| DMO-R010 | Report/export → entity + metric lineage |

## Deferred Automation (DMO-B002)

Automated report/export → metric lineage scanning is backlog for DMO-2+. Current lineage is manually curated in `DmoOntologyRegistry`.

## Export

```bash
php artisan architecture:dmo-foundation --json | jq '.lineage, .governance_rules'
```
