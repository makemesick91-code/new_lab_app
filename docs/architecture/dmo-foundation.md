# DMO Foundation — Data Model, Ontology & Canonical Metrics

**Sprint:** DMO-1  
**Maintained by:** `php artisan architecture:dmo-foundation`  
**Inputs:** NSF-4 (`architecture:canonical-entity-inventory`) + NSF-5 (`architecture:canonical-metric-reconciliation`)

## 1. Purpose

Establish the official read-only foundation for DaengtisiaMS data model governance: canonical entities, ontology relationships, metric registry, dimension/grain standards, lineage, sensitivity classification, and DMO backlog — without changing business logic, formulas, or schema.

## 2. Scope

| In scope | Out of scope |
| --- | --- |
| Consolidate NSF-4 + NSF-5 into unified DMO report | Schema migrations |
| Canonical data dictionary (entities) | Formula changes in dashboards |
| Ontology relationship map | New product features |
| Canonical metrics foundation | Row-level data export |
| Lineage & governance rules | Redis/Kafka/GraphQL/gRPC |
| Privacy/sensitivity classification | Mutable stock columns |
| DMO-2+ backlog documentation | |

## 3. Inputs from NSF-4 and NSF-5

| Sprint | Command | Doc | Registry |
| --- | --- | --- | --- |
| NSF-4 | `architecture:canonical-entity-inventory` | `canonical-entity-workflow-inventory.md` | 56 entities, 9 workflows, 6 gaps |
| NSF-5 | `architecture:canonical-metric-reconciliation` | `canonical-metric-reconciliation.md` | 71 metrics, 6 domains, 7 gaps |

DMO-1 composes both via `DmoFoundationService` and adds ontology, dimensions, lineage, governance rules, and unified backlog.

## 4. Domain Model

| Domain | Scope |
| --- | --- |
| **foundation** | Branch, User, Role, Doctor, Treatment, Tariff, Payment Method |
| **rme** | Patient, Clinic Visit, Medical Record, Odontogram |
| **cashier** | RME Invoice, Payment, Receivable (derived) |
| **inventory** | Product, Movement (ledger), Batch, Procurement, Opname, Transfer |
| **lab** | Lab Order, Case Candidate, QC, Delivery, Production |
| **owner** | Owner Dashboard KPI, reporting snapshots |
| **telemetry** | Performance evidence, audit, pg_stat_statements |
| **system** | Cross-cutting monitoring (maps to telemetry for entities) |

**Branch rule:** `BranchContext::requireId()` — never trust request `branch_id`.

## 5. Entity Model

Each canonical entity declares:

- `canonical_name`, `domain`, `primary_table`, `model`
- `scope`: global | branch_scoped | system
- `source_type`: source_of_truth | derived | reporting | telemetry | configuration
- `lifecycle_status_fields`, `relationships`, `sensitivity`
- `downstream_consumers`, `dmo_readiness`, `gaps`

**Inventory mandate:** Stock = `SUM(quantity_in) - SUM(quantity_out)` on `trx_inventory_movements` — no mutable stock columns.

See `canonical-data-dictionary.md` for full registry.

## 6. Metric Model

Each canonical metric declares:

- `canonical_metric_name` (snake_case)
- `domain`, `source_entities`, `source_tables`
- `formula_current` / `canonical_formula_candidate`
- `grain`, `dimensions`, `filters` (branch/date/status/soft_delete/active)
- `source_type`: source_of_truth | derived | computed | reporting | telemetry
- `sensitivity`, `conflict_status`, `dmo_readiness`
- Owner dashboard aliases documented as `duplicate` until DMO-M005

See `canonical-metrics-foundation.md` for full registry and rules.

## 7. Ontology Model

26 core relationships document how entities connect across domains: Branch scopes operations, Patient → Visit → MR/Odontogram → Invoice → Payment, Inventory ledger derivation, procurement chain, RME→Lab candidate conversion.

See `ontology-relationship-map.md`.

## 8. Lineage Model

Entity → metric → report/dashboard/export chains documented for Owner KPI, receivable, inventory ledger, lab queue, and performance telemetry.

See `data-lineage-governance.md`.

## 9. Governance Model

10 canonical rules (DMO-R001–R010) enforce: one metric one definition, declared grain, branch filter, date field, financial status rules, ledger-derived stock, receivable source clarity, PHI-free aggregates, Owner KPI alias mapping, report lineage.

## 10. Privacy and Sensitivity Model

| Class | Handling |
| --- | --- |
| none | Operational metadata |
| internal | Staff/branch data, policy-gated |
| PII | Mask KTP; aggregates only in commands |
| PHI | Counts only; no notes/handwriting in reports |
| financial | Aggregates only |
| telemetry | pg_stat without PHI in query text |

Command output never includes patient names, KTP/NIK, diagnosis, phone, address, or raw financial rows.

## 11. DMO Readiness Decision

| Metric | Local value |
| --- | --- |
| DMO-ready entities | 54 / 56 |
| DMO-ready metrics | 57 / 71 |
| Blocked metrics | 2 (net_revenue, pod_count) |
| needs_review entities | 2 (Patient Document, RME Prescription) |
| **Decision** | **GO** — foundation sufficient for DMO-2; blocked items deferred |

## 12. DMO-2+ Backlog

- DMO-M005: Unified Owner KPI metric registry (10 duplicate aliases)
- net_revenue canonical definition
- receivable aging persisted buckets (DMO-M003)
- treatment/tariff multi-branch boundary (DMO-006)
- Patient Document policy (DMO-001)
- expiry alert persistence (DMO-004)
- pod_count blocked pending POD module confirmation
- Report/export lineage automation (DMO-B002)
- National dimension standard: region, multi-site (DMO-B003)

## 13. National Distributed Architecture Handoff

DMO-1 provides the seed ontology and metric registry for future NDA planning:

- Canonical entity names map 1:1 to future distributed domain boundaries
- Branch dimension is the primary isolation key today
- Ledger-derived inventory is non-negotiable for any distributed stock service
- PHI/PII classification must propagate to any federated reporting layer
- Blocked metrics must be resolved before financial NDA packs

## Command

```bash
php artisan architecture:dmo-foundation
  [--json]
  [--output=storage/app/architecture/dmo1-foundation.json]
  [--domain=foundation|rme|cashier|inventory|lab|owner|system|all]
  [--include-references]
  [--no-lineage]
  [--no-backlog]
```
