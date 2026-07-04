# NSF-9 — Release Safety, Feature Flag & Automated Smoke — Evidence

**Status:** COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS — **GO**

## Summary

Adds the release-safety foundation for future risky infrastructure sprints:
feature flag registry, release safety gate, automated smoke command/script,
governance summary integration, CI/CD gate, and deploy gate integration.
Zero migrations, zero permission/route/business-behavior changes.

## PR / Merge / Tag

- PR: [#172](https://github.com/makemesick91-code/new_lab_app/pull/172)
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Merge commit: `ea45d3c` (merge of `a02fba1` "NSF-9 add release safety feature flags and automated smoke")
- GO tag: `nsf-9-release-safety-feature-flag-automated-smoke-go` → `ea45d3c`

## CI Checks (PR #172, run 28710568826)

- NSF-R012 Quality Gate — success (1m10s)
- NSF-R011 Critical Test Gate — success (3m4s)
- NSF-9 Release Safety & Automated Smoke Gate — success (47s) — new job running
  `foundation:feature-flags`, `foundation:release-safety-check`,
  `release:automated-smoke --json`, `architecture:foundation-roadmap-check`
- NSF-R011 Full Suite Gate — skipped (only runs on push/schedule/dispatch to base branch, by design)

## VPS Deploy

- Previous HEAD: `b3b3858` (ROADMAP-1 merge, tag `roadmap-1-national-foundation-expansion-source-lock-go`)
- Deployed HEAD: `ea45d3c` (tag `nsf-9-release-safety-feature-flag-automated-smoke-go`)
- Backup path/size: `storage/app/backups/deploy/pre_nsf9_20260704-152626.sql` (590K), verified non-empty before checkout
- Node: v20.20.2, npm: 10.8.2 (>=20 requirement met)
- `composer install --no-dev --prefer-dist --optimize-autoloader` → nothing to install/update/remove (lock unchanged), autoload regenerated OK
- `npm ci` → 160 packages added, no EBADENGINE
- `npm run build` → built in 1.70s, manifest + assets emitted OK
- `php artisan migrate --force` → "Nothing to migrate." (NSF-9 ships zero migrations)
- Cache rebuild: config/route/view/event cache all rebuilt successfully
- Permissions reset (`www-data:www-data`, dirs 775/files 664) and `php8.3-fpm` restarted, `nginx -t` + reload OK

## Command Results (VPS, post-deploy)

- `php artisan architecture:foundation-roadmap-check` → **GO** (12/12 checks passed), next recommended sprint **NSF-10**
- `php artisan foundation:feature-flags` → **GO** (16 flags, 0 risky-enabled)
- `php artisan foundation:release-safety-check` → **WATCH** (local CI/VPS evidence artifacts — `nsf6-governance-check.json`, `foundation-governance-summary.json`, `nsf-r011-critical-tests.txt`, `nsf-r012-build-pint.txt` — not yet captured on this host; non-blocking, honestly reported, not faked as GO)
- `php artisan release:automated-smoke` → **GO** (6/6 checks, command-readiness only)
- `php artisan release:automated-smoke --base-url=http://127.0.0.1` → **GO** (7/7 checks incl. `SMOKE-HTTP-HEALTH` → `/login` returned healthy HTTP 200)
- `php artisan data-quality:dq1-audit --fail-on=error` → GO
- `php artisan inventory:batch-governance-audit --fail-on=error` → GO
- `php artisan inventory:source-document-batch-audit --fail-on=error` → GO
- `php artisan inventory:ambiguous-batch-review-pack` → GO (0 ambiguous rows)
- `php artisan architecture:dmo-governance-check` → GO (15 rules, 446 passed, 0 errors)
- `php artisan architecture:nsf-governance-check --include-observability` → GO (23 rules, 22 passed, 0 warnings/errors)
- `php artisan architecture:foundation-governance-summary` → **Combined: GO** (1 non-blocking watch item documented — RELEASE_SAFETY local evidence)
- `curl -I http://127.0.0.1` → HTTP 302 (unauthenticated root redirect — healthy per NSF smoke standard)
- `tail -n 150 storage/logs/laravel.log` → no new ERROR/CRITICAL entries in the deploy window (`2026-07-04 15:2x`–`15:3x`); one pre-existing `2026-06-30` permission error unrelated to this deploy

## DQ/DMO/NSF/ROADMAP/Combined (local + VPS)

| Gate | Local | VPS |
| --- | --- | --- |
| DQ-1/2/3/3.1 | GO | GO |
| DMO | GO | GO |
| NSF (raw/effective) | GO | GO |
| ROADMAP | GO (next: NSF-10) | GO (next: NSF-10) |
| FEATURE_FLAGS | GO | GO |
| RELEASE_SAFETY | WATCH (local evidence not captured) | WATCH (same reason) |
| AUTOMATED_SMOKE | GO (command-readiness) | GO (command-readiness + HTTP 200) |
| Combined Foundation | GO | GO |

## Local test suite results

- `php artisan test --filter='FeatureFlag\|ReleaseSafety\|AutomatedSmoke\|Nsf9\|FoundationRoadmap\|FoundationGovernance'` → 85 passed
- `php artisan test` (full suite) → 3794 passed, 7 skipped, **2 failed** — both in `Tests\Unit\Console\NsfGovernanceCheckCommandTest`, confirmed via `git stash` to fail identically on the base branch *before* any NSF-9 change (pre-existing: hardcoded rule count 21 vs actual 23; missing-table QueryException in an unrelated inventory backfill service). Out of NSF-9 scope; not introduced by this sprint.
- `./vendor/bin/pint --dirty` → passed
- `git diff --check` → clean
- `graphify update .` → graph rebuilt (19,332 nodes, 26,288 edges) — gitignored output, not committed

## Files changed

30 files, +1931/-16. New: `config/feature_flags.php`, `config/release_safety.php`,
`config/automated_smoke.php`, `app/Services/Foundation/{FeatureFlagService,ReleaseSafetyService,AutomatedSmokeService}.php`,
`app/Console/Commands/{FoundationFeatureFlagsListCommand,FoundationReleaseSafetyCheckCommand,ReleaseAutomatedSmokeCommand}.php`,
`scripts/release/automated-smoke.sh`, `tests/Feature/Foundation/*` (3 files),
`tests/Feature/Architecture/Nsf9ReleaseSafetyGovernanceTest.php`,
`docs/architecture/nsf-9-release-safety-feature-flag-automated-smoke.md`,
this evidence doc. Modified: `config/foundation_roadmap.php` (NSF-9 → completed),
`config/foundation_governance.php` (sprint marker, nsf-9 evidence doc path),
`app/Services/Architecture/FoundationGovernanceSummaryService.php` +
`app/Console/Commands/ArchitectureFoundationGovernanceSummaryCommand.php` (FEATURE_FLAGS/RELEASE_SAFETY/AUTOMATED_SMOKE sections),
`.github/workflows/foundation-evidence-gates.yml` (`release_safety_gate` job),
`scripts/ci/foundation-evidence-gates.sh` (`run_release_safety`),
`scripts/deploy-vps.sh` (NSF-9 gates + post-restart smoke),
`docs/architecture/{national-foundation-expansion-roadmap,nsf-application-rules,nsf-governance-deploy-gates}.md`,
`docs/ai-knowledge/25_DaengtisiaMS_AI_Workflow_Prompts.md`,
3 pre-existing tests updated for the advanced sprint marker
(`FoundationRoadmapGovernanceTest`, `Nsf8ObservabilityRawGoClosureTest`,
`Dmo3DeferredMetricBacklogClosureTest`).

## Warnings / risks

- `RELEASE_SAFETY` reports **WATCH**, not GO, both locally and on VPS — local
  CI-evidence artifacts (`storage/ci-evidence/nsf-r01*`,
  `storage/app/architecture/*.json`) are only produced by an actual CI run or
  a manual `--output=` capture; this is reported honestly rather than faked
  as GO, per the "must not fake GO" rule.
- Two pre-existing, NSF-9-unrelated test failures remain in
  `NsfGovernanceCheckCommandTest` (confirmed present before this sprint via
  `git stash` on the base branch) — flagged here for visibility, not fixed,
  to avoid unscoped changes.
- `core.fileMode=false` is set repo-wide, so git does not auto-detect
  executable-bit drift; the three NSF-9 shell scripts were explicitly staged
  executable via `git update-index --chmod=+x` to survive future edits/checkouts.

## Next sprint

**NSF-10 — Observability, Backup & Release Safety Hardening.**
