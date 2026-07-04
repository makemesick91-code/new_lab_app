# NSF-6 — National Scale Foundation Application Rules & Guardrail Enforcement

**Branch:** `feature/nsf-6-national-scale-foundation-application-rules`  
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`  
**GO tag:** `nsf-6-national-scale-foundation-application-rules-go`  
**Status:** Implementation complete — pending PR merge / VPS deploy

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

*(Updated after VPS deploy)*

| Item | Value |
| --- | --- |
| PR | TBD |
| Merge commit | TBD |
| GO tag | `nsf-6-national-scale-foundation-application-rules-go` |
| VPS previous HEAD | TBD |
| VPS deployed HEAD | TBD |
| Backup path | TBD |
| Smoke | TBD |
| Final decision | TBD |
