# NSF-6 — National Scale Foundation Application Rules & Guardrail Enforcement

**Branch:** `feature/nsf-6-national-scale-foundation-application-rules`  
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`  
**GO tag:** `nsf-6-national-scale-foundation-application-rules-go`  
**Status:** **GO** — merged, tagged, VPS deployed

## Objective

Convert NSF foundation (NSF-1–NSF-5) and deploy/observability guardrails into application-level rules validated by read-only commands and tests — aligned with DMO application rules. **No schema changes, no dashboard formula/UI changes, no distributed technology.**

## Pre-flight

| Item | Value |
| --- | --- |
| Starting HEAD | `55d2643d6138d679d1bb357a264055e760b9e474` (includes DMO-2 merge `61d52c0`) |
| Laravel | 12.61.0 |
| PHP | 8.5.4 |
| DB driver | pgsql |
| App env | local |

## Graphify Summary

- Updated locally: `graphify-out/graph.json`, `GRAPH_REPORT.md` (gitignored)
- Nodes: 18664 | Edges: 25290
- Mapped: `NsfApplicationRulesService`, `FoundationGovernanceSummaryService`, DMO governance commands, `BranchContext`, inventory ledger services, observability commands, deploy gates

## Changed Files

| File | Change |
| --- | --- |
| `config/nsf.php` | NSF-R001–R021 rule registry |
| `app/Services/Architecture/NsfApplicationRulesService.php` | NSF governance validation |
| `app/Services/Architecture/FoundationGovernanceSummaryService.php` | Combined NSF+DMO summary |
| `app/Console/Commands/ArchitectureNsfGovernanceCheckCommand.php` | `architecture:nsf-governance-check` |
| `app/Console/Commands/ArchitectureFoundationGovernanceSummaryCommand.php` | `architecture:foundation-governance-summary` |
| `docs/architecture/nsf-application-rules.md` | NSF rules doc |
| `docs/architecture/nsf-governance-deploy-gates.md` | Deploy gates doc |
| `docs/architecture/dmo-application-rules.md` | NSF alignment section |
| `docs/architecture/data-lineage-governance.md` | NSF governance lineage row |
| `tests/Unit/Console/NsfGovernanceCheckCommandTest.php` | 12 tests |

## Commands

```bash
php artisan architecture:nsf-governance-check [--json] [--output=...] [--strict] [--include-dmo] [--include-deploy-gates] [--include-observability] [--include-privacy]
php artisan architecture:foundation-governance-summary [--json] [--output=...]
```

## Local Evidence

| Path | Notes |
| --- | --- |
| `storage/app/architecture/nsf6-governance-check.json` | NSF governance |
| `storage/app/architecture/nsf6-governance-check-strict.json` | Strict + DMO + deploy + observability + privacy |
| `storage/app/architecture/nsf6-foundation-governance-summary.json` | Combined summary |

## NSF Application Rules Summary

| Count | Value |
| --- | --- |
| Rules | 21 |
| Passed | 19 |
| Warnings | 5 (manual gates, deferred backlog, pg_stat local) |
| Errors | 0 |
| Decision | **WATCH** (warnings only — acceptable for GO) |

## DMO Alignment Summary

| Count | Value |
| --- | --- |
| Available | yes |
| Governance errors | 0 |
| Governance warnings | 4 (deferred DMO backlog) |
| Decision | WATCH |

## Observability Summary

| Item | Status |
| --- | --- |
| slow-query-audit | available |
| runtime-query-observability | available |
| pg_stat (local) | not available (expected — validate on VPS) |

## Deploy Gate Summary

| Gate | Status |
| --- | --- |
| pre_deploy_db_backup | documented in `scripts/deploy-vps.sh` |
| composer_install | documented |
| npm_build | documented |
| migrate_force | documented |
| cache_rebuild | documented |
| service_restart | documented |
| smoke_check | documented |

## NDA Readiness Summary

- NSF-R001–R021 machine-readable in `config/nsf.php`
- Read-only validation via `architecture:nsf-governance-check`
- Combined summary via `architecture:foundation-governance-summary`
- DMO alignment enforced (NSF-R016)
- No distributed tech in composer (NSF-R015)
- Ready for NDA-1 planning after VPS evidence

## Targeted Tests

| Filter | Result |
| --- | --- |
| NsfGovernance | 12 passed |
| FoundationGovernance | (included in NsfGovernance) |
| DmoGovernance | 9 passed |
| OwnerKpiRegistry | 7 passed |
| DmoFoundation | 12 passed |

## Full Suite / Build

| Gate | Result |
| --- | --- |
| `php artisan test` | 3659 passed, 7 skipped |
| `./vendor/bin/pint --dirty` | passed |
| `npm run build` | passed |

## Risk Assessment

- Low risk: read-only governance commands, config/docs only, no migrations
- pg_stat warnings expected locally; must validate on VPS
- Manual full-suite/build gates remain warning-level by design

## Rollback Plan

- Revert sprint commit(s) on stable branch
- No DB rollback expected (no migrations)
- VPS: checkout previous GO tag `dmo-2-canonical-owner-kpi-registry-metric-alias-resolution-go` if needed

## Pre-Deploy GO Decision

**GO** — zero error-level NSF/DMO rule failures; warnings are documented deferred/manual gates only.

## Post-Deploy Evidence

| Item | Value |
| --- | --- |
| PR | [#162](https://github.com/makemesick91-code/new_lab_app/pull/162) |
| Merge commit | `df1f74576dad7dfa2e6fd2d15d3013e019b6fd95` |
| GO tag | `nsf-6-national-scale-foundation-application-rules-go` → `df1f745` |
| Local HEAD | `df1f74576dad7dfa2e6fd2d15d3013e019b6fd95` |
| VPS previous HEAD | `61d52c052509f1ba1a3a02a0a4873ef862dbb9b8` (DMO-2) |
| VPS deployed GO tag HEAD | `df1f74576dad7dfa2e6fd2d15d3013e019b6fd95` |
| VPS final stable HEAD | `df1f74576dad7dfa2e6fd2d15d3013e019b6fd95` |
| Backup | `storage/app/backups/deploy/pre_nsf6_20260704-035830.sql` (569K) |
| Migration | Nothing to migrate |
| php-fpm/nginx | restarted/reloaded OK |

### VPS Governance Evidence

| Path | Size |
| --- | --- |
| `storage/app/architecture/nsf6-vps-governance-check.json` | 15K |
| `storage/app/architecture/nsf6-vps-foundation-governance-summary.json` | 3.9K |
| `storage/app/architecture/nsf6-vps-dmo-governance-reference.json` | 144K |
| `storage/app/performance/nsf6-vps-runtime-query-observability.json` | 13K |
| `storage/app/performance/storage/app/performance/nsf6-vps-slow-query-audit.json` | (pre-existing output path quirk) |

### VPS NSF Governance

| Metric | Value |
| --- | --- |
| Rules | 21 |
| Passed | 20 |
| Warnings | 4 |
| Errors | 0 |
| Decision | **WATCH** (manual/deferred gates only) |

### VPS DMO Alignment

| Metric | Value |
| --- | --- |
| Errors | 0 |
| Warnings | 4 |
| Decision | WATCH |

### VPS pg_stat_statements

| Field | Value |
| --- | --- |
| available | true |
| extension_version | 1.10 |
| preloaded | true |
| shared_preload_libraries | pg_stat_statements |

### VPS Smoke

| URL | HTTP |
| --- | --- |
| `/` | 302 |
| `/login` | 200 |
| `/inventory/dashboard` | 302 |
| `/rme/visits` | 302 |
| `/dashboard` | 302 |

No Laravel 500 observed.

### Build Assets (VPS)

- `app-DdSm4puC.css`
- `app-JStlj-rZ.js`

## Final GO Decision

**GO** — NSF foundation rules are application-level; governance reports 0 errors on VPS; DMO alignment 0 errors; pg_stat active; smoke green. WATCH due to documented manual gates and deferred DMO/NSF backlog warnings only.

> GO tag `nsf-6-national-scale-foundation-application-rules-go` points to merge commit `df1f745`. Post-deploy evidence updates live on stable branch after this doc commit.
