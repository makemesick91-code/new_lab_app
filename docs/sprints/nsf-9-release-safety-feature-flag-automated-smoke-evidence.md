# NSF-9 — Release Safety, Feature Flag & Automated Smoke — Evidence

**Status:** IN PROGRESS (placeholder — filled after PR merge + GO tag + VPS deploy)

## Summary

Adds the release-safety foundation for future risky infrastructure sprints:
feature flag registry, release safety gate, automated smoke command/script,
governance summary integration, CI/CD gate, and deploy gate integration.

## PR / Merge / Tag

- PR: TBD
- Merge commit: TBD
- GO tag: `nsf-9-release-safety-feature-flag-automated-smoke-go` (TBD)

## VPS Deploy

- Previous HEAD: TBD
- Deployed HEAD: TBD
- Backup path/size: TBD
- Node/npm version: TBD
- composer/npm/build/migrate result: TBD

## Command Results (local)

- `php artisan foundation:feature-flags` → GO (16 flags, 0 risky enabled)
- `php artisan foundation:release-safety-check` → WATCH (local-only CI evidence not yet captured — expected in dev)
- `php artisan release:automated-smoke` → GO (command-readiness only)
- `php artisan architecture:foundation-roadmap-check` → GO, next recommended sprint NSF-10
- `php artisan architecture:foundation-governance-summary` → Combined GO

## DQ/DMO/NSF/ROADMAP/Combined

- DQ-1/2/3/3.1: GO
- DMO: GO
- NSF: GO
- ROADMAP: GO (NSF-9 completed, next NSF-10)
- Combined Foundation: GO

## Files changed

See PR diff — new: `config/feature_flags.php`, `config/release_safety.php`,
`config/automated_smoke.php`, `app/Services/Foundation/*`,
`app/Console/Commands/Foundation*`, `app/Console/Commands/ReleaseAutomatedSmokeCommand.php`,
`scripts/release/automated-smoke.sh`, tests under
`tests/Feature/Foundation/*` and `tests/Feature/Architecture/Nsf9ReleaseSafetyGovernanceTest.php`,
docs under `docs/architecture/*` and this evidence doc; modified:
`config/foundation_roadmap.php`, `config/foundation_governance.php`,
`app/Services/Architecture/FoundationGovernanceSummaryService.php`,
`app/Console/Commands/ArchitectureFoundationGovernanceSummaryCommand.php`,
`.github/workflows/foundation-evidence-gates.yml`,
`scripts/ci/foundation-evidence-gates.sh`, `scripts/deploy-vps.sh`.

## Warnings / risks

- TBD after full validation run.

## Next sprint

**NSF-10 — Observability, Backup & Release Safety Hardening.**
