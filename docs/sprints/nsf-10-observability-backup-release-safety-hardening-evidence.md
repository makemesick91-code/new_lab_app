# NSF-10 — Observability, Backup & Release Safety Hardening — Evidence

**Status:** COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS — **GO**

## Summary

Closes the NSF-9 non-blocking `RELEASE_SAFETY: WATCH` by adding a real,
profile-aware (`local`/`ci`/`vps`) release evidence capture/check standard
and a read-only backup verification gate, then wiring `ReleaseSafetyService`
and `FoundationGovernanceSummaryService` to consume that evidence instead of
a static local file-existence list. Zero migrations, zero permission/route
changes, zero business-behavior changes.

## PR / Merge / Tag

- PR: [#173](https://github.com/makemesick91-code/new_lab_app/pull/173)
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Feature branch: `feature/nsf-10-observability-backup-release-safety-hardening`
- Merge commit: `8fdc92e` (fast-forward merge of PR #173)
- GO tag: `nsf-10-observability-backup-release-safety-hardening-go` → `8fdc92e`

## CI Checks (PR #173, run 28712421830)

- NSF-R012 Quality Gate — success (1m6s)
- NSF-R011 Critical Test Gate — success (3m0s)
- NSF-9 Release Safety & Automated Smoke Gate — success (38s)
- **NSF-10 Release Evidence Gate — success (46s)** — new job running
  `release:evidence-capture --profile=ci`, `release:evidence-check
  --profile=ci`, `foundation:release-safety-check --profile=ci`
- NSF-R011 Full Suite Gate — skipped (only runs on push/schedule/dispatch to
  base branch, by design)

## CI evidence artifact result

Downloaded and inspected the `nsf-10-release-evidence` GitHub Actions
artifact: 10 files (`automated-smoke.json`, `feature-flags.json`,
`foundation-governance-summary.json`, `foundation-roadmap-check.json`,
`nsf-10-evidence-capture.json`, `nsf-10-evidence-check.json`,
`nsf-10-release-evidence-gate.txt`, `nsf-10-release-safety-check.json`,
`nsf-governance-check.json`, `release-evidence-check.json`). No
`APP_KEY=`/`DB_PASSWORD` substrings found. `nsf-10-release-safety-check.json`
→ `{"decision":"GO","checks":10,"passed":10,"warnings":0,"errors":0}`;
`nsf-10-evidence-check.json` (first run, before self-persist) →
`{"decision":"WATCH","checks":6,"passed":5,"warnings":1,"errors":0}` —
expected first-run self-reference behavior (see architecture doc §6).

## VPS Deploy

- Previous HEAD: `ea45d3c` (tag `nsf-9-release-safety-feature-flag-automated-smoke-go`)
- Deployed HEAD: `8fdc92e` (tag `nsf-10-observability-backup-release-safety-hardening-go`)
- Backup path/size: `storage/app/backups/deploy/pre_nsf10_20260704-163515.sql` (591K / 604168 bytes), verified non-empty before checkout
- Node: v20.20.2, npm: 10.8.2 (>=20 requirement met)

## Command Results (VPS, post-deploy)

- `composer install --no-dev --prefer-dist --optimize-autoloader` → "Nothing to install, update or remove" (lock unchanged), autoload regenerated OK
- `npm ci` → 160 packages added, no EBADENGINE
- `npm run build` → built in 1.63s, manifest + assets emitted OK
- `php artisan migrate --force` → "Nothing to migrate." (NSF-10 ships zero migrations)
- `php artisan foundation:backup-verify --path="$BACKUP"` → **GO** (9/9 checks: path allowed, exists, not empty, min size, extension, not world-writable, mtime reasonable, SQL header matched)
- `php artisan architecture:foundation-roadmap-check` → **GO** (12/12 checks), next recommended sprint **CACHE-1**
- `php artisan data-quality:dq1-audit --fail-on=error` → GO
- `php artisan inventory:batch-governance-audit --fail-on=error` → GO
- `php artisan inventory:source-document-batch-audit --fail-on=error` → GO
- `php artisan inventory:ambiguous-batch-review-pack` → GO (0 ambiguous rows)
- `php artisan architecture:dmo-governance-check` → GO (15 rules, 446 passed, 0 errors)
- `php artisan architecture:nsf-governance-check --include-observability` → GO (23 rules, 22 passed, 0 warnings/errors)
- `php artisan foundation:feature-flags` → GO (16 flags, 0 risky-enabled)
- `php artisan foundation:release-safety-check` (default local profile) → **WATCH** (`RELEASE-SAFETY-EVIDENCE-CHAIN` — local profile has no required artifacts yet; honest, not faked)
- `php artisan release:automated-smoke` → GO (6/6 checks, command-readiness only)
- `php artisan architecture:foundation-governance-summary` → **Combined: GO** (1 non-blocking watch item — local RELEASE_SAFETY/RELEASE_EVIDENCE)
- `php artisan release:evidence-capture --profile=vps --base-url=http://127.0.0.1 --backup-path="$BACKUP"` → **GO** (11/11 artifacts written: foundation-roadmap-check.json, feature-flags.json, automated-smoke.json, foundation-governance-summary.json, nsf-governance-check.json, backup-verify.json, deploy-runtime.json, dmo-governance-check.json, dq-audits.txt, automated-smoke-http.json, release-safety-check.json)
- `php artisan release:evidence-check --profile=vps` (1st run) → WATCH (`release-evidence-check.json` not yet self-persisted); (2nd run) → **GO** (12/12 artifacts present, safe, fresh)
- `php artisan foundation:release-safety-check --profile=vps` → **GO** (11/11 checks) — `evidence_chain.decision=GO`, `backup_verification.decision=GO` — **this closes the NSF-9 RELEASE_SAFETY WATCH**
- `php artisan optimize:clear` / `config:cache` / `route:cache` / `view:cache` / `event:cache` → all succeeded
- Permissions reset (`www-data:www-data`, dirs 775/files 664), `php8.3-fpm` restarted, `nginx -t` passed + reload OK
- `php artisan about` → Laravel 12.61.0, PHP 8.3.6, env `pilot`, debug OFF, config/routes/views/events all CACHED
- `php artisan migrate:status` → all migrations `Ran`, none new (NSF-10 additive-only, zero migrations)
- `curl -I http://127.0.0.1` → HTTP 302 → `/login` (healthy, unauthenticated redirect)
- `tail -n 150 storage/logs/laravel.log` → no new ERROR/CRITICAL entries in the deploy window (2026-07-04 16:35–16:39); one pre-existing `2026-06-30 05:21:10` permission error unrelated to this deploy

## Backup verification result

**GO** — `storage/app/backups/deploy/pre_nsf10_20260704-163515.sql`, 604168 bytes, extension `.sql`, not world-writable, mtime reasonable, SQL dump header matched (`-- PostgreSQL database dump`). Dump contents never read beyond the 4 KiB header sniff.

## Release evidence result

- `ci` profile (local dev + CI): capture GO, check GO after self-persist run.
- `vps` profile (VPS): capture GO (11 artifacts), check GO after self-persist run (12 artifacts incl. `release-evidence-check.json`).

## Release safety result

- `local`: WATCH (honest — no required local artifacts, never fakes GO).
- `ci`: GO (verified locally and in the CI `NSF-10 Release Evidence Gate`).
- `vps`: **GO** — evidence chain GO, backup verification GO. **NSF-9 WATCH closed.**

## Observability result

`architecture:nsf-governance-check --include-observability` on the VPS
Postgres instance: 23 rules, 22 passed, 0 warnings, 0 errors, decision GO.
`NSF-R009` (pg_stat guardrail) present and passing; `pg_stat_database`
readable; observability evidence captured safely in
`nsf-governance-check.json` (vps profile) with no credentials/PII.

## Automated smoke result

Command-readiness: GO (6/6). HTTP smoke (`--base-url=http://127.0.0.1` via
the vps evidence capture's `automated-smoke-http.json`): healthy.

## DQ/DMO/NSF/ROADMAP/Combined (local + CI + VPS)

| Gate | Local | CI | VPS |
| --- | --- | --- | --- |
| DQ-1/2/3/3.1 | GO | GO | GO |
| DMO | GO | GO | GO |
| NSF (raw/effective) | GO | GO | GO |
| ROADMAP | GO (next: CACHE-1) | GO (next: CACHE-1) | GO (next: CACHE-1) |
| FEATURE_FLAGS | GO | GO | GO |
| RELEASE_EVIDENCE | WATCH (no required local artifacts) | GO (after capture) | GO (after capture) |
| BACKUP_VERIFICATION | NOT_APPLICABLE | NOT_APPLICABLE | GO |
| RELEASE_SAFETY | WATCH (honest) | GO | **GO** |
| AUTOMATED_SMOKE | GO | GO | GO |
| Combined Foundation | GO | GO | GO |

## Pre-existing test triage result

`NsfGovernanceCheckCommandTest` had 2 pre-existing failures (confirmed
present before this sprint):

1. `it runs and outputs valid JSON with governance summary` — stale
   expectation `summary.rules === 21`; actual rule count is 23
   (`NSF-R001`–`NSF-R021` + `NSF-R023`/`NSF-R024` from later sprints).
   **Fixed** — expectation updated to `23` (also fixed the same stale value
   in `it foundation governance summary command runs`).
2. `it foundation governance summary command runs` — `QueryException: no
   such table: trx_inventory_movements`. Root cause: this Unit-suite test
   file never had `RefreshDatabase` (Pest only attaches it to `tests/Feature`
   by default), but the command it exercises transitively runs a live DB
   audit. **Fixed** — added `RefreshDatabase` directly in the test file.

Both restored to green; no command behavior changed. Full file:
`php artisan test --filter=NsfGovernanceCheckCommandTest` → 12 passed (188
assertions). See §12 of
[`nsf-10-observability-backup-release-safety-hardening.md`](../architecture/nsf-10-observability-backup-release-safety-hardening.md).

## Local test suite results

- `php artisan test --filter='ReleaseEvidence|BackupVerification|ReleaseSafety|AutomatedSmoke|FeatureFlag|Nsf10|NsfGovernanceCheckCommandTest|FoundationRoadmap|FoundationGovernance'` → **105 passed** (1030 assertions)
- `php artisan test` (full suite) → **3796 passed, 7 skipped** (16839 assertions), 0 failed
- `./vendor/bin/pint --dirty` → auto-fixed 2 files (cosmetic import ordering/spacing only), clean on re-run
- `git diff --check` → clean
- `graphify update .` → rebuilt (19,421 nodes, 26,419 edges, 2,314 communities) — gitignored output, not committed

## Files changed

30 files, +2249/-51. New: `config/{release_evidence,backup_governance}.php`,
`app/Services/Foundation/{ReleaseEvidenceService,BackupVerificationService}.php`,
`app/Console/Commands/{ReleaseEvidenceCaptureCommand,ReleaseEvidenceCheckCommand,FoundationBackupVerifyCommand}.php`,
`tests/Feature/Foundation/{ReleaseEvidenceFoundationTest,BackupVerificationFoundationTest,ReleaseSafetyEvidenceClosureTest}.php`,
`tests/Feature/Architecture/Nsf10ObservabilityBackupReleaseSafetyTest.php`,
`docs/architecture/nsf-10-observability-backup-release-safety-hardening.md`,
this evidence doc. Modified:
`config/{release_safety,foundation_roadmap,foundation_governance}.php`
(NSF-10 → completed, next sprint CACHE-1, evidence chain wired in, NSF-10 CI
gate registered), `app/Services/Foundation/ReleaseSafetyService.php`
(profile-aware evidence + backup consumption),
`app/Services/Architecture/FoundationGovernanceSummaryService.php` +
`app/Console/Commands/{FoundationReleaseSafetyCheckCommand,ArchitectureFoundationGovernanceSummaryCommand}.php`
(RELEASE_EVIDENCE/BACKUP_VERIFICATION sections + `--profile` option),
`.github/workflows/foundation-evidence-gates.yml` (`nsf10_release_evidence_gate`
job), `scripts/deploy-vps.sh` (backup-verify + vps evidence chain gates),
`.gitignore` (ignore generated evidence artifacts — never committed),
`tests/Unit/Console/NsfGovernanceCheckCommandTest.php` (pre-existing failure
fixes), `tests/Feature/Architecture/{Nsf9ReleaseSafetyGovernanceTest,
FoundationRoadmapGovernanceTest}.php` (stale CACHE-1 expectation fixes),
`docs/architecture/{national-foundation-expansion-roadmap,nsf-application-rules,
nsf-governance-deploy-gates,nsf-9-release-safety-feature-flag-automated-smoke}.md`.

## Governance rules added

- Release evidence must be captured by real, already-governed commands and
  safety-scanned (`.env`/`DB_PASSWORD`/`APP_KEY=`/16-digit KTP-shaped
  patterns) before being written (`config/release_evidence.php`).
- Backup verification is read-only, path-confined to
  `storage/app/backups/deploy`, and never reads dump contents beyond a
  header sniff (`config/backup_governance.php`).
- Release safety GO for `ci`/`vps` profiles requires the real evidence chain
  (and, for `vps`, a GO/WATCH backup verification artifact) — never
  config-only; `local` profile stays honestly WATCH.
- Generated evidence artifacts (`storage/ci-evidence/*`,
  `storage/release-evidence/*`) are never committed to git — gitignored,
  uploaded as CI artifacts instead.

## Warnings / risks

- The `release-safety-check.json` artifact captured mid-sequence by
  `release:evidence-capture --profile=vps` reflects a self-referential gap
  in its own embedded evidence snapshot the first time it's generated in a
  fresh directory (it cannot contain itself yet). Cosmetic — the
  authoritative decision is the standalone `foundation:release-safety-check
  --profile=vps` run executed immediately after capture, which reached
  **GO** both times it was run in this deploy.
- `RELEASE_SAFETY`/`RELEASE_EVIDENCE` on the `local` profile intentionally
  remain WATCH by default (no required local artifacts) — honest reporting,
  not a regression.
- `core.fileMode=false` is set repo-wide; `scripts/deploy-vps.sh` and
  `scripts/release/automated-smoke.sh` executable bits were explicitly
  re-staged via `git update-index --chmod=+x` in the commit.
- Discovered and fixed (in `ReleaseEvidenceService`) a general Laravel
  `Artisan::call`/`Artisan::output()` nested-buffer-draining gotcha that
  would otherwise have silently produced empty evidence artifacts for
  commands that themselves nest a further `Artisan::call` (governance
  summary, and nsf-governance-check with observability on `pgsql`) — see
  architecture doc §4 for the technical explanation.

## Next sprint

**CACHE-1 — Cache Strategy, Redis Readiness & Invalidation Governance.**
