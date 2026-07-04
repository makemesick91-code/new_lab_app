# DMO-1 — Data Model, Ontology & Canonical Metrics Foundation

**Branch:** `feature/dmo-1-data-model-ontology-canonical-metrics-foundation`  
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`  
**GO tag:** `dmo-1-data-model-ontology-canonical-metrics-foundation-go`  
**Status:** Implementation complete — pending PR merge / VPS deploy evidence

## Objective

Consolidate NSF-4 canonical entity inventory and NSF-5 metric reconciliation into the official DMO foundation: data dictionary, ontology map, metrics foundation, lineage/governance, read-only command, and sprint evidence — without schema changes or business formula changes.

## Pre-flight

| Item | Value |
| --- | --- |
| Starting HEAD | `d517382a085a371e66ade46996c33efd5cd082da` (includes NSF-5 evidence) |
| Laravel | 12.61.0 |
| PHP | 8.5.4 |
| DB driver | pgsql |
| App env | local |

## Graphify Summary

- Updated locally: `graphify-out/graph.json`, `GRAPH_REPORT.md` (gitignored — local only)
- Nodes: 18176 | Edges: 24680
- Mapped: NSF-4/NSF-5 commands, DmoFoundationService, entity/metric registries, Owner KPI, inventory ledger, RME clinical chain

## Changed Files

| File | Change |
| --- | --- |
| `app/Services/Architecture/DmoOntologyRegistry.php` | Ontology, dimensions, lineage, governance, backlog |
| `app/Services/Architecture/DmoFoundationService.php` | Composes NSF-4 + NSF-5 |
| `app/Console/Commands/ArchitectureDmoFoundationCommand.php` | Read-only Artisan command |
| `docs/architecture/dmo-foundation.md` | Main DMO architecture doc |
| `docs/architecture/canonical-data-dictionary.md` | Entity registry reference |
| `docs/architecture/ontology-relationship-map.md` | 26 relationships |
| `docs/architecture/canonical-metrics-foundation.md` | 71 metrics foundation |
| `docs/architecture/data-lineage-governance.md` | Lineage + governance rules |
| `tests/Unit/Console/DmoFoundationCommandTest.php` | 12 tests |

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

## Local Evidence

| Path | Notes |
| --- | --- |
| `storage/app/architecture/dmo1-foundation.json` | Unified DMO report |
| `storage/app/architecture/dmo1-entity-inventory-reference.json` | NSF-4 cross-ref |
| `storage/app/architecture/dmo1-metric-reconciliation-reference.json` | NSF-5 cross-ref |

**Privacy:** No row-level data, patient names, KTP/NIK, or clinical content in command output.

## Summary Counts (local)

| Count | Value |
| --- | --- |
| Domains | 8 |
| Entities | 56 |
| Workflows | 9 |
| Metrics | 71 |
| Relationships | 26 |
| Dimensions | 11 |
| Entity gaps | 6 |
| Metric gaps | 7 |
| DMO backlog items | 17 |
| DMO-ready entities | 54 |
| DMO-ready metrics | 57 |
| Blocked metrics | 2 |

## Ontology Highlights

- Branch scopes Patient, Visit, Invoice, Payment, Inventory Movement
- Patient → Visit → MR/Odontogram → Invoice → Payment → Receivable (derived)
- Inventory Product → Movement → Current Stock (ledger SUM)
- PR → PO → GR → Movement; Transfer/Opname → Movement
- RME Invoice Item → Lab Case Candidate → Lab Order
- Owner Dashboard consumes domain metrics (duplicate aliases backlog)

## Canonical Metric Governance

- 10 rules DMO-R001–R010 documented
- Owner KPI aliases duplicate until DMO-M005
- Blocked: net_revenue, pod_count
- Inventory stock must trace to trx_inventory_movements

## Sensitivity Classification

none, internal, PII, PHI, financial, telemetry — all documented in command output.

## Deferred DMO-2 / NDA Items

- DMO-M005 unified Owner KPI registry
- net_revenue canonical definition
- receivable aging persisted buckets
- treatment/tariff multi-branch boundary
- Patient Document policy
- expiry alert persistence
- pod_count POD module confirmation
- report/export lineage automation
- national dimension standard (region)

## Targeted Test Results

```bash
php artisan test --filter=DmoFoundation          # 12 passed
php artisan test --filter=CanonicalEntityInventory
php artisan test --filter=CanonicalMetricReconciliation
```

## Full Suite / Build

```bash
php artisan test
./vendor/bin/pint --dirty
npm run build
```

## Risk Assessment

| Risk | Mitigation |
| --- | --- |
| Read-only command only | No schema/migration |
| Large registry drift | Composes existing NSF-4/NSF-5 services |
| Privacy leak in JSON | Privacy flags + no DB row sampling |

## Rollback Plan

- Revert sprint commit(s) on base branch
- No DB rollback (no migrations)

## GO/NO-GO (pre-deploy)

**Decision: GO** — DMO foundation consolidates NSF-4/NSF-5; 2 blocked metrics deferred to DMO-2; no business logic changes.

## Post-Deploy Evidence

| Item | Value |
| --- | --- |
| PR | [#160](https://github.com/makemesick91-code/new_lab_app/pull/160) |
| Merge commit | `36484d62e34788675e2ca635225a00992bdeeda9` |
| GO tag | `dmo-1-data-model-ontology-canonical-metrics-foundation-go` |
| Local HEAD | `36484d62e34788675e2ca635225a00992bdeeda9` |
| VPS previous HEAD | `4feedfba1e296a97610ba688c7ecc2cd376fe91a` (NSF-5) |
| VPS deployed GO tag HEAD | `36484d62e34788675e2ca635225a00992bdeeda9` |
| VPS final stable HEAD | `36484d62e34788675e2ca635225a00992bdeeda9` |
| Backup path | `storage/app/backups/deploy/pre_dmo1_20260704-021140.sql` (564K) |
| Migration | Nothing to migrate |
| DMO foundation evidence | `storage/app/architecture/dmo1-vps-foundation.json` (210K) |
| Entity reference | `storage/app/architecture/dmo1-vps-entity-inventory-reference.json` (47K) |
| Metric reference | `storage/app/architecture/dmo1-vps-metric-reconciliation-reference.json` (147K) |
| Runtime observability | `storage/app/performance/dmo1-vps-runtime-query-observability.json` (13K) |
| Slow query audit | `storage/app/performance/dmo1-vps-slow-query-audit.json` (14K) |
| pg_stat_statements | available=true, v1.10, preloaded=true |
| Build assets | `app-DdSm4puC.css`, `app-JStlj-rZ.js` |
| php-fpm/nginx | restart OK, nginx -t OK |
| Smoke | `/login` 200; protected routes 302 |
| Log review | No new errors on deploy date |

### VPS Summary Counts (from dmo1-vps-foundation.json)

| Count | Value |
| --- | --- |
| Domains | 8 |
| Entities | 56 |
| Workflows | 9 |
| Metrics | 71 |
| Relationships | 26 |
| Dimensions | 11 |
| Backlog items | 17 |
| DMO-ready entities | 54 |
| DMO-ready metrics | 57 |
| Blocked metrics | 2 |

## Final GO/NO-GO

**GO** — PR #160 merged, GO tag deployed to VPS pilot, evidence captured, smoke green, no migrations, full suite 3631 passed locally.
