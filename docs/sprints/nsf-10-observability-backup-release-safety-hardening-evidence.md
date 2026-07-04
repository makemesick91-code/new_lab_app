# NSF-10 — Observability, Backup & Release Safety Hardening — Evidence

**Status:** IN PROGRESS (to be finalized to COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS after PR merge + VPS deploy)

## Summary

Closes the NSF-9 non-blocking `RELEASE_SAFETY: WATCH` by adding a real,
profile-aware (`local`/`ci`/`vps`) release evidence capture/check standard
and a read-only backup verification gate, then wiring `ReleaseSafetyService`
and `FoundationGovernanceSummaryService` to consume that evidence instead of
a static local file-existence list. Zero migrations, zero permission/route
changes, zero business-behavior changes.

## PR / Merge / Tag

- PR: _TBD_
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Feature branch: `feature/nsf-10-observability-backup-release-safety-hardening`
- Merge commit: _TBD_
- GO tag: `nsf-10-observability-backup-release-safety-hardening-go` → _TBD_

## CI Checks

_TBD after PR is opened — expect `NSF-R012 Quality Gate`, `NSF-R011 Critical
Test Gate`, `NSF-9 Release Safety & Automated Smoke Gate`,
`NSF-10 Release Evidence Gate` (new), `NSF-R011 Full Suite Gate` (push-only)._

## CI evidence artifact result

_TBD — `nsf-10-release-evidence` GitHub Actions artifact from the
`nsf10_release_evidence_gate` job (`release:evidence-capture --profile=ci`,
`release:evidence-check --profile=ci`, `foundation:release-safety-check
--profile=ci`)._

## VPS Deploy

- Previous HEAD: _TBD_ (expected `ea45d3c` / tag `nsf-9-release-safety-feature-flag-automated-smoke-go`)
- Deployed HEAD: _TBD_ (tag `nsf-10-observability-backup-release-safety-hardening-go`)
- Backup path/size: _TBD_
- Node/npm: _TBD_

## Command Results (VPS, post-deploy)

_TBD — to include:_

- `php artisan foundation:backup-verify --path="$BACKUP"` → _TBD_
- `php artisan architecture:foundation-roadmap-check` → _TBD_ (expect next
  recommended sprint **CACHE-1**)
- `php artisan foundation:feature-flags` → _TBD_
- `php artisan release:automated-smoke` / `--base-url=http://127.0.0.1` → _TBD_
- `php artisan data-quality:dq1-audit --fail-on=error` → _TBD_
- `php artisan inventory:batch-governance-audit --fail-on=error` → _TBD_
- `php artisan inventory:source-document-batch-audit --fail-on=error` → _TBD_
- `php artisan inventory:ambiguous-batch-review-pack` → _TBD_
- `php artisan architecture:dmo-governance-check` → _TBD_
- `php artisan architecture:nsf-governance-check --include-observability` → _TBD_
- `php artisan architecture:foundation-governance-summary` → _TBD_
- `php artisan release:evidence-capture --profile=vps --base-url=http://127.0.0.1 --backup-path="$BACKUP"` → _TBD_
- `php artisan release:evidence-check --profile=vps` → _TBD_
- `php artisan foundation:release-safety-check --profile=vps` → _TBD_ (expect **GO**, closing the NSF-9 WATCH)
- `curl -I http://127.0.0.1` → _TBD_
- `tail -n 150 storage/logs/laravel.log` → _TBD_

## Backup verification result

_TBD — GO/WATCH/FAIL from `foundation:backup-verify` on the real deploy backup._

## Release evidence result

_TBD — GO/WATCH/FAIL from `release:evidence-capture`/`release:evidence-check` for `ci` and `vps` profiles._

## Release safety result

_TBD — GO/WATCH/FAIL from `foundation:release-safety-check` for `local`, `ci`, and `vps` profiles (local is expected to remain WATCH honestly; ci/vps expected GO)._

## Observability result

_TBD — `NSF-R009` status + `pg_stat_database`/`pg_stat_statements` readability from `architecture:nsf-governance-check --include-observability` on the VPS._

## Automated smoke result

_TBD._

## DQ/DMO/NSF/ROADMAP/Combined (local + VPS)

| Gate | Local | CI | VPS |
| --- | --- | --- | --- |
| DQ-1/2/3/3.1 | GO | _TBD_ | _TBD_ |
| DMO | GO | _TBD_ | _TBD_ |
| NSF (raw/effective) | GO | _TBD_ | _TBD_ |
| ROADMAP | GO (next: CACHE-1) | _TBD_ | _TBD_ |
| FEATURE_FLAGS | GO | _TBD_ | _TBD_ |
| RELEASE_EVIDENCE | WATCH (local, not required) | _TBD_ | _TBD_ |
| BACKUP_VERIFICATION | NOT_APPLICABLE (local) | NOT_APPLICABLE | _TBD_ |
| RELEASE_SAFETY | WATCH (honest, local has no required evidence) | _TBD_ | _TBD_ |
| AUTOMATED_SMOKE | GO | _TBD_ | _TBD_ |
| Combined Foundation | GO | _TBD_ | _TBD_ |

## Pre-existing test triage result

`NsfGovernanceCheckCommandTest` had 2 pre-existing failures (confirmed
present before this sprint):

1. `it runs and outputs valid JSON with governance summary` — stale
   expectation `summary.rules === 21`; actual rule count is 23
   (`NSF-R001`–`NSF-R021` + `NSF-R023`/`NSF-R024` from later sprints).
   **Fixed** — expectation updated to `23`.
2. `it foundation governance summary command runs` — `QueryException: no
   such table: trx_inventory_movements`. Root cause: this Unit-suite test
   file never had `RefreshDatabase` (Pest only attaches it to `tests/Feature`
   by default), but the command it exercises transitively runs a live DB
   audit. **Fixed** — added `RefreshDatabase` directly in the test file.

Both restored to green; no command behavior changed. See §12 of
[`nsf-10-observability-backup-release-safety-hardening.md`](../architecture/nsf-10-observability-backup-release-safety-hardening.md).

## Local test suite results

_TBD — to include:_

- `php artisan test --filter='ReleaseEvidence\|BackupVerification\|ReleaseSafety\|AutomatedSmoke\|FeatureFlag\|Nsf10\|NsfGovernanceCheckCommandTest\|FoundationRoadmap\|FoundationGovernance'` → _TBD_
- `php artisan test` (full suite) → _TBD_
- `./vendor/bin/pint --dirty` → _TBD_
- `git diff --check` → _TBD_
- `graphify update .` → _TBD_ (gitignored output, not committed)

## Files changed

_TBD — final list at PR time. Expected: new
`config/{release_evidence,backup_governance}.php`,
`app/Services/Foundation/{ReleaseEvidenceService,BackupVerificationService}.php`,
`app/Console/Commands/{ReleaseEvidenceCaptureCommand,ReleaseEvidenceCheckCommand,FoundationBackupVerifyCommand}.php`,
4 new test files, this evidence doc, and
`docs/architecture/nsf-10-observability-backup-release-safety-hardening.md`.
Modified: `config/{release_safety,foundation_roadmap,foundation_governance}.php`,
`app/Services/Foundation/ReleaseSafetyService.php`,
`app/Services/Architecture/FoundationGovernanceSummaryService.php`,
`app/Console/Commands/{FoundationReleaseSafetyCheckCommand,ArchitectureFoundationGovernanceSummaryCommand}.php`,
`.github/workflows/foundation-evidence-gates.yml`, `scripts/deploy-vps.sh`,
`.gitignore` (ignore generated evidence artifacts),
`tests/Unit/Console/NsfGovernanceCheckCommandTest.php`,
`tests/Feature/Architecture/{Nsf9ReleaseSafetyGovernanceTest,FoundationRoadmapGovernanceTest}.php`,
`docs/architecture/{national-foundation-expansion-roadmap,nsf-application-rules,nsf-governance-deploy-gates,nsf-9-release-safety-feature-flag-automated-smoke}.md`._

## Governance rules added

- Release evidence must be captured by real, already-governed commands and
  safety-scanned before being written (`config/release_evidence.php`).
- Backup verification is read-only, path-confined to
  `storage/app/backups/deploy`, and never reads dump contents beyond a
  header sniff (`config/backup_governance.php`).
- Release safety GO for `ci`/`vps` profiles requires the real evidence chain
  (and, for `vps`, a GO/WATCH backup verification artifact) — never
  config-only.
- Generated evidence artifacts (`storage/ci-evidence/*`,
  `storage/release-evidence/*`) are never committed to git.

## Warnings / risks

- The `release-safety-check.json` artifact captured mid-sequence by
  `release:evidence-capture --profile=vps` is deliberately captured **last**
  but still reflects a self-referential gap in its own embedded evidence
  snapshot (it cannot contain itself). This is cosmetic — the authoritative
  decision is the standalone `foundation:release-safety-check --profile=vps`
  run executed immediately after capture, which both the deploy script and
  CI already do as a separate step.
- `RELEASE_SAFETY`/`RELEASE_EVIDENCE` on the `local` profile intentionally
  remain WATCH by default (no required local artifacts) — this is honest
  reporting, not a regression; CI/VPS profiles are the ones expected to
  reach GO.

## Next sprint

**CACHE-1 — Cache Strategy, Redis Readiness & Invalidation Governance.**
