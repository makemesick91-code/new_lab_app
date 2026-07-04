# Sprint NSF-4 — Canonical Entity & Workflow Inventory

## Pre-flight

| Item | Value |
| --- | --- |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Sprint branch | `feature/sprint-nsf-4-canonical-entity-workflow-inventory` |
| Base HEAD (start) | `2c92cab7b0f55eaad015b8a119e130ace9b40cf1` |
| Includes NSF-3 evidence | yes (`2c92cab`) |
| Laravel | 12.61.0 |
| PHP (local) | 8.5.4 |
| DB driver (local) | pgsql |
| Test DB driver | sqlite (in-memory via phpunit.xml) |

## Graphify map

| Area | Finding |
| --- | --- |
| Routes | inventory (127), settings (108), rme (54), reports (17), lab/production/delivery |
| Models | 64+ under `app/Modules/*/Models` |
| Branch scope | BranchContext pattern across operational modules |
| Workflows | Visit lifecycle, billing, procurement, stock, lab order |
| Privacy | PII/PHI on patient/RM/odontogram; financial on invoices |
| Graphify update | 17707 nodes, 24180 edges (AST refresh) |

## Objective

Canonical entity and workflow inventory bridge before DMO-1. Read-only — no schema or business logic changes.

## Command

```bash
php artisan architecture:canonical-entity-inventory
php artisan architecture:canonical-entity-inventory --json
php artisan architecture:canonical-entity-inventory --domain=inventory
php artisan architecture:canonical-entity-inventory --json --output=nsf4-canonical-entity-inventory.json
php artisan architecture:canonical-entity-inventory --include-routes
```

## Privacy rules

- No row-level data, patient names, KTP/NIK, clinical content, sample values
- Sensitivity from domain/table metadata only

## Files added/changed

- `app/Services/Architecture/CanonicalEntityRegistry.php`
- `app/Services/Architecture/CanonicalEntityInventoryService.php`
- `app/Console/Commands/ArchitectureCanonicalEntityInventoryCommand.php`
- `tests/Unit/Console/CanonicalEntityInventoryCommandTest.php`
- `docs/architecture/canonical-entity-workflow-inventory.md`
- `docs/sprints/sprint-nsf-4-canonical-entity-workflow-inventory.md`
- `graphify-out/graph.json`, `graphify-out/GRAPH_REPORT.md`

## Inventory summary

| Metric | Value |
| --- | --- |
| Entities | 56 |
| Workflows | 9 |
| Gaps | 6 |
| DMO ready | 54 |
| DMO needs review | 2 |

## Sensitive classifications

- **PII:** Patient, Clinic Visit (demographics), Legacy Import staging
- **PHI:** Medical Record, Odontogram, Handwriting, Prescription
- **financial:** RME Invoice/Payment, Receivable, Tariff, Lab Invoice
- **internal:** User/Role/Permission, Inventory master, telemetry

## DMO readiness

GO for ontology seed — 54/56 entities ready. Deferred: Patient Document policy, unified Owner KPI metric registry (DMO-1).

## Rollback plan

- Revert sprint commit(s)
- No DB rollback (no migrations)

## Risk assessment

- Low: read-only inventory, documentation, no business logic

## GO/NO-GO (pre-deploy)

| Check | Status |
| --- | --- |
| Command implemented | GO |
| Architecture doc | GO |
| Graphify updated | GO |
| Targeted tests | GO (9 passed) |
| Full suite | GO — 3609 passed, 7 skipped |
| Build/style | GO (pint, npm build) |
| Local evidence | GO |

### Local evidence

| Item | Value |
| --- | --- |
| Architecture inventory | `storage/app/architecture/nsf4-canonical-entity-inventory.json` (47 KB) |
| Entities / workflows / gaps | 56 / 9 / 6 |
| Runtime observability | `storage/app/performance/nsf4-local-runtime-query-observability.json` |
| Slow query audit | `storage/app/performance/nsf4-local-slow-query-audit.json` |

---

## Post-merge evidence

| Item | Value |
| --- | --- |
| PR | [#158](https://github.com/makemesick91-code/new_lab_app/pull/158) |
| Merge commit | `800b5a16c2abd33f29cec5eea41380e2bdd3233b` |
| Sprint commit | `0f283c3` |
| GO tag | `sprint-nsf-4-canonical-entity-workflow-inventory-go` → `800b5a1` |
| Local HEAD | `800b5a16c2abd33f29cec5eea41380e2bdd3233b` |

### VPS deploy

| Item | Value |
| --- | --- |
| VPS path | `/var/www/asia-dental-lab-v2` |
| Previous HEAD | `2c92cab7b0f55eaad015b8a119e130ace9b40cf1` (NSF-3 evidence) |
| Deployed GO tag HEAD | `800b5a16c2abd33f29cec5eea41380e2bdd3233b` |
| Final VPS stable HEAD | `800b5a16c2abd33f29cec5eea41380e2bdd3233b` |
| Backup | `storage/app/backups/deploy/pre_nsf4_20260704-005707.sql` (559 KB) |
| Migration | Nothing to migrate |
| Architecture evidence | `storage/app/architecture/nsf4-vps-canonical-entity-inventory.json` (47 KB) |
| Runtime observability | `storage/app/performance/nsf4-vps-runtime-query-observability.json` (13 KB) |
| Slow query audit | `storage/app/performance/nsf4-vps-slow-query-audit.json` (14 KB) |
| pg_stat_statements | available=true, extension=1.10, preloaded=true |
| Build assets | `app-DdSm4puC.css`, `app-JStlj-rZ.js` |
| php-fpm/nginx | restarted, nginx -t OK |
| Smoke | `/login` 200; `/`, `/dashboard`, `/rme/visits`, `/inventory/dashboard` → 302 (unauthenticated) |
| Logs | No new errors today |
| Final GO/NO-GO | **GO** |

> GO tag points to deployed code commit `800b5a1`. Post-deploy evidence doc updates are on stable branch after tag.
