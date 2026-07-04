# DMO-2 — Canonical Owner KPI Registry & Metric Alias Resolution

**Branch:** `feature/dmo-2-canonical-owner-kpi-registry-metric-alias-resolution`  
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`  
**GO tag:** `dmo-2-canonical-owner-kpi-registry-metric-alias-resolution-go`  
**Status:** Implementation complete — pending PR merge / VPS deploy

## Objective

Resolve Owner KPI duplicate aliases into a canonical registry and convert DMO foundation rules into application-level governance validated by read-only commands and tests — **no schema changes, no dashboard formula/UI changes**.

## Pre-flight

| Item | Value |
| --- | --- |
| Starting HEAD | `f1436283fc4a6987a00e039c7b9feaa401564e80` (includes DMO-1 evidence) |
| Laravel | 12.61.0 |
| PHP | 8.5.4 |
| DB driver | pgsql |
| App env | local |

## Graphify Summary

- Updated locally: `graphify-out/graph.json`, `GRAPH_REPORT.md` (gitignored)
- Nodes: 18400 | Edges: 24959
- Mapped: `OwnerDashboardKpiService`, `HomeDashboardController`, `OwnerKpiRegistryService`, `DmoApplicationRulesService`, NSF-4/5/DMO-1 commands, dashboard KPI consumers

## Changed Files

| File | Change |
| --- | --- |
| `config/dmo.php` | DMO-R001–R015 rule registry |
| `app/Services/Architecture/OwnerKpiRegistryService.php` | Canonical Owner KPI + alias map |
| `app/Services/Architecture/DmoApplicationRulesService.php` | Governance validation |
| `app/Console/Commands/ArchitectureOwnerKpiRegistryCommand.php` | `architecture:owner-kpi-registry` |
| `app/Console/Commands/ArchitectureDmoGovernanceCheckCommand.php` | `architecture:dmo-governance-check` |
| `app/Services/Architecture/DmoOntologyRegistry.php` | Extended governance rules R011–R015 |
| `docs/architecture/canonical-owner-kpi-registry.md` | Owner KPI doc |
| `docs/architecture/dmo-application-rules.md` | Application rules doc |
| `docs/architecture/dmo-foundation.md` | DMO-M005 closure reference |
| `docs/architecture/canonical-metrics-foundation.md` | DMO-M005 closed |
| `docs/architecture/data-lineage-governance.md` | Owner KPI lineage update |
| `tests/Unit/Console/OwnerKpiRegistryCommandTest.php` | 7 tests |
| `tests/Unit/Console/DmoGovernanceCheckCommandTest.php` | 9 tests |
| `tests/Unit/Console/DmoFoundationCommandTest.php` | 15 governance rules |

## Commands

```bash
php artisan architecture:owner-kpi-registry [--json] [--output=...] [--only=all|canonical|aliases|blocked|needs_review]
php artisan architecture:dmo-governance-check [--json] [--output=...] [--strict] [--domain=all|owner|...]
```

## Local Evidence

| Path | Notes |
| --- | --- |
| `storage/app/architecture/dmo2-owner-kpi-registry.json` | Owner KPI registry |
| `storage/app/architecture/dmo2-governance-check.json` | Governance validation |

## Owner KPI Registry Summary

| Count | Value |
| --- | --- |
| Canonical Owner KPIs | 12 |
| Aliases | 22 |
| Duplicates resolved | 22 |
| Blocked | 2 (`net_revenue`, `pod_count`) |
| Needs review | 3 |

## DMO Application Rules Summary

| Count | Value |
| --- | --- |
| Rules | 15 |
| Passed | 442 |
| Warnings | 4 (deferred backlog) |
| Errors | 0 |
| Decision | WATCH (deferred items only) |

## DMO-M005 Closure

**CLOSED.** Owner KPI aliases unified in `OwnerKpiRegistryService`; UI keys unchanged.

## net_revenue / pod_count

Remain **blocked** — documented in registry `blocked[]`; not used as Owner KPI sources (DMO-R013 passes).

## Targeted Tests

| Filter | Result |
| --- | --- |
| OwnerKpiRegistry | 7 passed |
| DmoGovernance | 9 passed |
| DmoFoundation | 12 passed |

## Full Suite / Build

| Gate | Result |
| --- | --- |
| Full suite | 3647 passed, 7 skipped (15552 assertions) |
| Pint | passed |
| npm run build | passed (`app-BF9piW1U.css`, `app-JStlj-rZ.js`) |

## Risk Assessment

Low — read-only registries/commands/docs; no migrations; no business logic changes.

## Rollback Plan

Revert sprint commit(s). No DB rollback required.

## GO/NO-GO (pre-deploy)

**GO** — 0 governance errors; WATCH due to documented deferred backlog (DMO-M001, M003, M006, M007).

## Test Plan

- [ ] `php artisan architecture:owner-kpi-registry --json`
- [ ] `php artisan architecture:dmo-governance-check --strict` (exit 0)
- [ ] Owner dashboard `/dashboard` smoke (auth)
- [ ] VPS evidence JSON files present

---

## Post-Deploy Evidence

| Item | Value |
| --- | --- |
| PR | [#161](https://github.com/makemesick91-code/new_lab_app/pull/161) |
| Merge commit | `61d52c052509f1ba1a3a02a0a4873ef862dbb9b8` |
| GO tag | `dmo-2-canonical-owner-kpi-registry-metric-alias-resolution-go` → `61d52c0` |
| Local HEAD | `61d52c052509f1ba1a3a02a0a4873ef862dbb9b8` |
| VPS previous HEAD | `f1436283fc4a6987a00e039c7b9feaa401564e80` |
| VPS deployed GO tag HEAD | `61d52c052509f1ba1a3a02a0a4873ef862dbb9b8` |
| VPS final stable HEAD | `61d52c052509f1ba1a3a02a0a4873ef862dbb9b8` |
| Backup | `storage/app/backups/deploy/pre_dmo2_20260704-025718.sql` (567K) |
| Migration | Nothing to migrate |
| php-fpm/nginx | restarted/reloaded OK |
| Build assets (VPS) | `app-DdSm4puC.css`, `app-JStlj-rZ.js` |

### VPS Evidence Paths

| Path | Size |
| --- | --- |
| `storage/app/architecture/dmo2-vps-owner-kpi-registry.json` | 30K |
| `storage/app/architecture/dmo2-vps-governance-check.json` | 144K |
| `storage/app/architecture/dmo2-vps-foundation-reference.json` | 211K |
| `storage/app/architecture/dmo2-vps-metric-reconciliation-reference.json` | 147K |
| `storage/app/architecture/dmo2-vps-entity-inventory-reference.json` | 47K |
| `storage/app/performance/dmo2-vps-runtime-query-observability.json` | 13K |
| `storage/app/performance/dmo2-vps-slow-query-audit.json` | 14K |

### VPS Governance / KPI Counts

Same as local: 12 canonical KPIs, 22 aliases, 0 errors, 4 warnings, decision WATCH.

### pg_stat_statements

`available=true`, extension v1.10, `preloaded=true`.

### Smoke

| URL | Status |
| --- | --- |
| `/login` | 200 |
| `/`, `/dashboard`, `/inventory/dashboard`, `/rme/visits` | 302 (unauthenticated) |

### Log review

No new Laravel errors on deploy date.

## Final GO/NO-GO

**GO** — DMO-2 deployed; governance 0 errors; DMO-M005 closed; deferred backlog remains WATCH-only.

> GO tag points to deployed code commit `61d52c0`. Post-deploy evidence doc updates may follow on stable branch without moving the tag.
