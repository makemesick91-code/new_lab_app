# DMO Application Rules (DMO-2)

## 1. Purpose

Encode DMO foundation (NSF-4, NSF-5, DMO-1) as **application-level** architecture rules validated by code — not docs-only.

## 2. Why application-level

Pastikan fondasi DMO konsisten: registries in code/config, validated by `architecture:dmo-governance-check`, covered by tests, referenced by docs. Rules are **read-only guardrails** — they do not block runtime business operations.

## 3. Rule registry DMO-R001–DMO-R015

| ID | Title | Severity |
| --- | --- | --- |
| DMO-R001 | Canonical metric uniqueness | error |
| DMO-R002 | Metric grain declaration | error |
| DMO-R003 | Branch dimension for branch-scoped metrics | error |
| DMO-R004 | Date dimension for time-based metrics | error |
| DMO-R005 | Financial metric status rules | error |
| DMO-R006 | Inventory stock ledger derivation | error |
| DMO-R007 | Receivable source specification | error |
| DMO-R008 | Clinical aggregate privacy | error |
| DMO-R009 | Owner KPI canonical mapping | error |
| DMO-R010 | Report metric entity mapping | warning |
| DMO-R011 | Entity scope declaration | error |
| DMO-R012 | Sensitivity classification | error |
| DMO-R013 | Blocked metrics not Owner KPIs | error |
| DMO-R014 | Duplicate alias resolution | error |
| DMO-R015 | Registry change workflow | info |

Source: `config/dmo.php`  
Validator: `App\Services\Architecture\DmoApplicationRulesService`

## 4. Validation behavior

```bash
php artisan architecture:dmo-governance-check
  [--json]
  [--output=storage/app/architecture/dmo2-governance-check.json]
  [--strict]          # exit 1 on errors
  [--domain=all|owner|rme|cashier|inventory|lab|system]
  [--no-warnings]
```

**Decision:** `GO` (no errors), `WATCH` (warnings only — deferred backlog), `NO-GO` (errors).

## 5. Required future sprint workflow

When changing metrics/KPIs: update registry → lineage docs → Pest tests → sprint evidence → run governance check.

## 6. How to add/change a metric safely

1. Add entry to `CanonicalMetricRegistry`
2. Declare grain, dimensions, filters, sensitivity
3. Add/update consumer services and tests
4. Run `architecture:dmo-governance-check --strict`
5. Update `canonical-metrics-foundation.md` if user-facing

## 7. How to add/change an entity safely

1. Add entry to `CanonicalEntityRegistry` with `scope`
2. Wire migration only if explicitly approved
3. Run `architecture:canonical-entity-inventory`
4. Run governance check

## 8. How to add/change Owner KPI safely

1. Add/update `OwnerKpiRegistryService` entry + alias_map
2. Map to exactly one `source_canonical_metric`
3. Do not use blocked metrics (`net_revenue`, `pod_count`)
4. Run `architecture:owner-kpi-registry` and governance check
5. Update `canonical-owner-kpi-registry.md`

## 9. Privacy/security constraints

Governance and registry commands must never emit: patient names, KTP/NIK, phone, address, diagnosis, odontogram/medical notes, or raw financial row data.

## 10. CI / local / VPS command usage

| Command | Purpose |
| --- | --- |
| `architecture:owner-kpi-registry` | Owner KPI + alias evidence |
| `architecture:dmo-governance-check` | Rule validation |
| `architecture:dmo-foundation` | Full DMO foundation (DMO-1 + extended rules) |
| `architecture:canonical-metric-reconciliation` | NSF-5 metric inventory |
| `architecture:canonical-entity-inventory` | NSF-4 entity inventory |
