# Sprint NSF-5 — Reporting & KPI Metric Reconciliation

**Branch:** `feature/sprint-nsf-5-reporting-kpi-metric-reconciliation`  
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`  
**GO tag:** `sprint-nsf-5-reporting-kpi-metric-reconciliation-go`  
**Status:** Implementation complete — pending PR merge / VPS deploy evidence

## Objective

Create canonical metric and reporting reconciliation bridge before DMO-1: map KPIs, formulas, grains, dimensions, consumers, conflicts, and DMO readiness without changing business calculations.

## Pre-flight

| Item | Value |
| --- | --- |
| Starting HEAD | `186a05c286b9dd2dd0ad388e9892fe8bbb469017` (includes NSF-4 evidence) |
| Laravel | 12.61.0 |
| PHP | 8.5.4 |
| DB driver | pgsql |
| App env | local |

## Graphify Summary

- Updated locally: `graphify-out/graph.json`, `GRAPH_REPORT.md` (gitignored — local only)
- Mapped: OwnerDashboardKpiService, InventoryReportService, InventoryAlertService, receivable/cashier routes, performance commands
- Nodes: 17891 | Edges: 24382

## Changed Files

| File | Change |
| --- | --- |
| `app/Services/Architecture/CanonicalMetricRegistry.php` | Static 71-metric registry |
| `app/Services/Architecture/CanonicalMetricReconciliationService.php` | Collect/enrich service |
| `app/Console/Commands/ArchitectureCanonicalMetricReconciliationCommand.php` | Read-only Artisan command |
| `docs/architecture/canonical-metric-reconciliation.md` | Architecture doc |
| `tests/Unit/Console/CanonicalMetricReconciliationCommandTest.php` | 10 tests |

## Command

```bash
php artisan architecture:canonical-metric-reconciliation
  [--json]
  [--output=storage/app/architecture/nsf5-canonical-metric-reconciliation.json]
  [--domain=rme|cashier|inventory|lab|owner|system|all]
  [--include-entity-reference]
  [--no-consumers]
```

## Local Evidence

| Path | Notes |
| --- | --- |
| `storage/app/architecture/nsf5-canonical-metric-reconciliation.json` | 71 metrics |
| `storage/app/architecture/nsf5-entity-inventory-reference.json` | NSF-4 cross-ref |
| `storage/app/performance/nsf5-local-runtime-query-observability.json` | pg_stat snapshot |
| `storage/app/performance/nsf5-local-slow-query-audit.json` | slow query audit |

**Privacy:** No row-level data, patient names, KTP/NIK, or clinical content in command output.

## Metric Summary (local)

| Count | Value |
| --- | --- |
| Metrics | 71 |
| Domains | 6 (rme, cashier, inventory, lab, owner, system) |
| DMO ready | 57 |
| needs_review | 12 |
| duplicate | 10 |
| blocked | 2 |
| Conflict groups | 4 |
| Gaps | 7 |

### Source type classification

| Type | Count (approx) |
| --- | --- |
| source_of_truth | ~25 |
| derived | ~12 |
| computed | ~28 |
| telemetry | ~5 |

### Sensitivity classification

| Class | Present in metrics |
| --- | --- |
| financial | paid_amount, remaining_receivable, stock_value, owner_* |
| PII | new_patients, total_visits, owner_patient_count |
| PHI | medical_records, odontogram_count |
| internal | inventory counts, lab orders |
| telemetry | pg_stat, slow_query |

## DMO Readiness

- **Ready:** Core visit, payment, receivable, stock ledger, owner alias documentation
- **needs_review:** active_patients, gross_revenue, aging buckets, QC metrics
- **blocked:** net_revenue, pod_count

## Deferred DMO-1 Items

- Unified Owner KPI metric registry (DMO-M005)
- net_revenue canonical definition (DMO-M001)
- Receivable aging persisted buckets (DMO-M003)
- Treatment/tariff multi-branch metric boundary (DMO-M006)
- Patient Document policy (from NSF-4 DMO-001)
- Expiry alert persistence decision (DMO-M004)

## Tests

```bash
php artisan test --filter=CanonicalMetricReconciliation   # 10 passed
php artisan test --filter=CanonicalEntityInventory        # regression green
php artisan test                                          # 3619 passed, 7 skipped
```

## Build / Style

```bash
./vendor/bin/pint --dirty   # passed
npm ci && npm run build     # passed (app-BF9piW1U.css, app-JStlj-rZ.js)
```

## Risk Assessment

| Risk | Mitigation |
| --- | --- |
| Owner KPI alias confusion | Documented duplicates; no code change |
| Wrong date grain in reports | Documented per-metric date fields |
| Command exposes PII | Privacy flags + aggregate-only registry |

## Rollback Plan

1. Revert sprint commit(s) on base branch
2. No DB rollback — no migrations added
3. VPS: checkout previous GO tag `sprint-nsf-4-canonical-entity-workflow-inventory-go`

## GO/NO-GO (pre-deploy)

**GO** — read-only inventory, tests green, no schema/business logic changes.

---

## Post-deploy evidence (to be filled after VPS deploy)

| Item | Value |
| --- | --- |
| PR | TBD |
| Merge commit | TBD |
| GO tag | TBD |
| VPS previous HEAD | TBD |
| VPS deployed HEAD | TBD |
| Backup path | TBD |
| Smoke | TBD |
| Final decision | TBD |
