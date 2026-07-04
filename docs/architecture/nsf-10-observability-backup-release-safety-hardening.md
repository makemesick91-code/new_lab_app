# NSF-10 — Observability, Backup & Release Safety Hardening

## 1. Objective

Close the non-blocking `RELEASE_SAFETY: WATCH` left by NSF-9 by making release
safety evidence **real, repeatable, safe, and auditable** — a profile-aware
evidence capture/check standard, a read-only backup verification gate, and a
release safety decision that actually consumes that evidence instead of
checking a static local file list.

## 2. Baseline from NSF-9

- PR #172 merged, merge commit `ea45d3c`, GO tag
  `nsf-9-release-safety-feature-flag-automated-smoke-go`, VPS deployed at
  `ea45d3c`.
- Feature flags GO, automated smoke GO, DQ/DMO/NSF/ROADMAP/Combined GO.
- `RELEASE_SAFETY: WATCH` — the only non-blocking gap — because
  `config/release_safety.php`'s `local_evidence_candidates` check only
  verified static file paths (`storage/app/architecture/nsf6-governance-check.json`,
  `storage/ci-evidence/nsf-r011-critical-tests.txt`, etc.) existed on disk; it
  never captured evidence itself and never distinguished local vs CI vs VPS.
- Two pre-existing `NsfGovernanceCheckCommandTest` failures (unrelated to
  NSF-9 scope) — see §9.

## 3. Why RELEASE_SAFETY was WATCH

`ReleaseSafetyService` had no mechanism to *produce* the evidence it checked
for — it just looked for leftover files from previous ad-hoc CI/VPS runs.
Locally, those files never exist, so the decision was always WATCH, and the
same WATCH was indistinguishable between "nothing captured yet" and "capture
tooling doesn't exist". NSF-10 replaces that static check with a real
capture/check pipeline.

## 4. Release evidence artifact standard

- Config: [`config/release_evidence.php`](../../config/release_evidence.php)
  — declares, per profile (`local`/`ci`/`vps`), the evidence directory,
  required artifacts, optional artifacts, max artifact age, and a
  forbidden-pattern/regex safety scan (`.env`, `DB_PASSWORD`, `APP_KEY=`,
  16-digit KTP/NIK-shaped sequences, PEM headers).
- Service: `App\Services\Foundation\ReleaseEvidenceService`:
  - `capture(profile, baseUrl?, backupPath?)` — read-only; re-runs the
    existing governed commands (`architecture:foundation-roadmap-check`,
    `foundation:feature-flags`, `release:automated-smoke`,
    `architecture:foundation-governance-summary`,
    `architecture:nsf-governance-check`, and for `vps` also
    `foundation:backup-verify`, `foundation:release-safety-check
    --profile=vps`, `architecture:dmo-governance-check`, DQ audits, and an
    HTTP smoke variant) via `Artisan::call()` with a **dedicated
    `BufferedOutput`** per job (not the shared `Artisan::output()` facade
    buffer — see the implementation note below) and writes each command's
    `--json` output as a safe artifact file. Content is scanned against the
    forbidden-pattern list before it is ever written to disk; anything
    matching is dropped and reported, never persisted.
  - `check(profile)` — read-only; validates every required/optional artifact
    for the profile exists, is non-empty, passes the safety scan, and (for
    required artifacts) is not older than `max_age_seconds`. GO if all
    required artifacts pass; WATCH if only optional artifacts are
    missing/stale; FAIL if a required artifact is missing, empty, unsafe, or
    stale.
- Commands: `php artisan release:evidence-capture {--profile=local}
  {--base-url=} {--backup-path=} {--json}` and
  `php artisan release:evidence-check {--profile=local} {--json}`.
  `release:evidence-check` also self-persists its own decision as
  `release-evidence-check.json` (an *optional* artifact) so the check result
  itself becomes part of the evidence trail.
- **Implementation note (nested `Artisan::call` output draining):** Laravel's
  `Illuminate\Console\Application` tracks a single shared "last output"
  buffer across nested `Artisan::call()` invocations. Some governed commands
  (`architecture:foundation-governance-summary`, and
  `architecture:nsf-governance-check --include-observability` on a `pgsql`
  connection) themselves issue a further nested `Artisan::call()` for
  observability. If a caller relies on the shared `Artisan::output()` facade
  after calling one of these, it silently reads an already-drained inner
  buffer instead of the real output. `ReleaseEvidenceService` avoids this
  entirely by passing an explicit `Symfony\Component\Console\Output\BufferedOutput`
  as the third argument to every `Artisan::call()` and reading from that
  object directly. Real CLI invocations (`php artisan release:evidence-capture
  ...`) are never affected — the bug only manifests for in-process nested
  calls, which is also why the CI/VPS deploy scripts (real subprocess
  invocations) were never at risk, only a hypothetical future nested caller
  (and this sprint's own tests, which call the service directly for this
  reason).
- Never committed: `storage/ci-evidence/*` and `storage/release-evidence/*`
  are gitignored (only `storage/ci-evidence/.gitkeep` is tracked) — CI
  uploads them as a workflow artifact instead.

## 5. CI evidence capture

- New job `nsf10_release_evidence_gate` in
  [`.github/workflows/foundation-evidence-gates.yml`](../../.github/workflows/foundation-evidence-gates.yml)
  (needs `release_safety_gate`): runs `release:evidence-capture --profile=ci`,
  `release:evidence-check --profile=ci`, and `foundation:release-safety-check
  --profile=ci`, then uploads `storage/ci-evidence` as artifact
  `nsf-10-release-evidence`. `config/foundation_governance.php` gains an
  `NSF-10` entry under `ci_evidence_gates.gates` documenting this job and its
  artifacts, alongside the existing `NSF-R011`/`NSF-R012` entries.

## 6. VPS evidence capture

- `scripts/deploy-vps.sh` runs `foundation:backup-verify --path="$BACKUP"`
  immediately inside the existing "Foundation deploy governance gates" block
  (after the new code + migrations are live, so the command exists), then —
  after the existing DQ/DMO/NSF/roadmap/flags/smoke/summary gates (all
  unchanged) — runs `release:evidence-capture --profile=vps
  --base-url=http://127.0.0.1 --backup-path="$BACKUP"`, `release:evidence-check
  --profile=vps`, and `foundation:release-safety-check --profile=vps`, before
  the cache rebuild/restart steps. Nothing existing was removed.
- The `vps` profile additionally captures `backup-verify.json`,
  `release-safety-check.json` (captured **last**, once every sibling artifact
  already exists, so its own embedded evidence-chain snapshot only shows a
  self-reference gap — never a sibling ordering gap — see note below),
  `deploy-runtime.json` (node/npm version, PHP/Laravel version, `git describe`
  GO tag, commit, backup path + size — no secrets), `dmo-governance-check.json`,
  `dq-audits.txt`, and (when `--base-url` is given) `automated-smoke-http.json`.
- **Known first-run self-reference note:** because `release-safety-check.json`
  and `release-evidence-check.json` are themselves required/optional
  artifacts, the *first* time they are captured/checked in a fresh evidence
  directory they cannot yet contain themselves, so their own embedded
  snapshot is conservatively FAIL/WATCH about that one artifact. This is
  cosmetic — the **authoritative** decision is the standalone
  `release:evidence-check --profile=vps` / `foundation:release-safety-check
  --profile=vps` run executed immediately after capture (exactly what the
  deploy script and CI both do), which reads the now-complete directory and
  reaches the correct GO.

## 7. Backup verification

- Config: [`config/backup_governance.php`](../../config/backup_governance.php)
  — allowed directories (`storage/app/backups/deploy`), allowed extension
  (`sql`), minimum size (1 KiB), staleness window (90 days), optional
  plain-SQL header markers.
- Service: `App\Services\Foundation\BackupVerificationService::verify(?path)`
  — read-only. Checks: path resolves inside an allowed directory (path
  traversal safe via `realpath` prefix match), file exists, non-empty, meets
  minimum size, has the expected extension, is not world-writable, has a
  reasonable mtime (future mtime is FAIL, stale mtime is WATCH), and
  optionally sniffs the first 4 KiB for a known dump header (WATCH, not
  FAIL, if absent — some dump formats don't have a text header). **Never**
  reads or prints the dump body, never restores it, never uploads it.
- Command: `php artisan foundation:backup-verify {--path=} {--json}`.
- Integrated into `release:evidence-capture --profile=vps` (writes
  `backup-verify.json`) and into `ReleaseSafetyService` (vps profile reads
  that artifact's decision — see §8).

## 8. Release safety GO/WATCH/FAIL criteria

`ReleaseSafetyService::collect(string $profile = 'local')` now:

1. Runs the original NSF-9 structural checks unchanged (config exists, gate
   list defined/covers DQ/DMO/NSF/ROADMAP/summary, gate commands registered,
   evidence/rollback/safety-rule fields defined, deploy gate files exist,
   feature-flag governance safe).
2. Adds `RELEASE-SAFETY-EVIDENCE-CHAIN`: consumes
   `ReleaseEvidenceService::check($profile)` — GO/WATCH/FAIL passes straight
   through.
3. For `profile=vps` only, adds `RELEASE-SAFETY-BACKUP-VERIFIED`: reads the
   already-captured `backup-verify.json` artifact's decision (never
   re-reads the backup file itself) — missing artifact or FAIL/WATCH inside
   it is reflected directly.

| Profile | GO | WATCH | FAIL |
| --- | --- | --- | --- |
| `local` (default) | n/a — no required artifacts | Optional local artifacts not captured (expected — this is honest, not fake GO) | Structural config/gate problem only |
| `ci` | All 5 required `storage/ci-evidence/*` artifacts present, safe, fresh | `release-evidence-check.json` (optional) not yet self-persisted | Any required artifact missing/empty/unsafe/stale before capture |
| `vps` | All 8 required `storage/release-evidence/latest/*` artifacts present, safe, fresh, and the backup verification artifact is GO | Backup verification artifact is WATCH (e.g. stale mtime), or an optional artifact missing | Backup verification is FAIL, or a required artifact missing/empty/unsafe/stale |

`foundation:release-safety-check` gains `--profile=local|ci|vps` (default
`local`, fully backward compatible with the NSF-9 no-argument call).
`architecture:foundation-governance-summary` gains the same `--profile`
option, threaded through to `ReleaseSafetyService` and
`ReleaseEvidenceService`.

## 9. Artifact safety policy

- Every artifact is the `--json` output of an already-governed, read-only
  command — nothing is invented.
- Before being written, content is scanned against
  `config('release_evidence.forbidden_patterns')` (`.env`, `DB_PASSWORD`,
  `DB_USERNAME`, `APP_KEY=`, `PGPASSWORD`, `-----BEGIN`) and
  `forbidden_regex` (16-digit runs, i.e. KTP/NIK-shaped). A match means the
  artifact is **not written** and is reported as `skipped_unsafe`.
- `foundation:backup-verify` never reads the dump body beyond a 4 KiB header
  sniff and never prints file contents.
- `dq-audits.txt` reuses the existing privacy-safe DQ audit commands
  (already NSF/DQ-governed to exclude PII/row-level data).
- Nothing under `storage/ci-evidence/` or `storage/release-evidence/` is
  committed to git — both are gitignored (`.gitignore`), and CI uploads
  `storage/ci-evidence` as a workflow artifact instead.

## 10. Observability evidence

NSF-10 deliberately does **not** duplicate NSF-R009. Observability evidence
is already produced by `architecture:nsf-governance-check
--include-observability` (captured as `nsf-governance-check.json` for the
`vps` profile, and available for `ci`/`local` without the observability flag
since CI/local don't run against the production Postgres instance) and by
`architecture:foundation-governance-summary` (which always requests
`include_observability`). Both report, without secrets or PII: whether the
observability check ran, whether `pg_stat_database` is readable, whether
`pg_stat_statements` is installed/preloaded (when the runtime-observability
command is available), and the `NSF-R009` rule status.

## 11. Deploy gate changes

- Added (both `scripts/deploy-vps.sh` and CI): `foundation:backup-verify`,
  `release:evidence-capture --profile=vps|ci`, `release:evidence-check
  --profile=vps|ci`, `foundation:release-safety-check --profile=vps|ci`.
- Removed: nothing. Every NSF-9/NSF-8/NSF-7/DQ/DMO gate remains in place.

## 12. Known pre-existing test triage (NSF-9 carry-over)

`NsfGovernanceCheckCommandTest` had 2 pre-existing failures, verified present
before NSF-9:

1. **`it runs and outputs valid JSON with governance summary`** expected
   `summary.rules === 21`. `config/nsf.php` has legitimately grown to 23
   rules (`NSF-R001`–`NSF-R021` plus `NSF-R023`/`NSF-R024`, added by later
   sprints) — a stale expectation, not a bug. Fixed: expectation now `23`.
   Same stale `21` fixed in `it foundation governance summary command runs`.
2. **`it foundation governance summary command runs`** threw
   `QueryException: no such table: trx_inventory_movements`. Root cause: this
   test file lives under `tests/Unit/Console/`, and this project's
   `tests/Pest.php` only attaches `RefreshDatabase` to tests under
   `tests/Feature/` (`pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')`).
   The test itself calls `architecture:foundation-governance-summary`, which
   transitively runs the DQ-2 batch governance audit against real DB tables
   — a genuine test-infrastructure gap once the summary command started
   including live DQ checks, not something NSF-10 introduced. Fixed by
   adding `RefreshDatabase` directly in the test file
   (`uses(TestCase::class, RefreshDatabase::class)`) rather than moving the
   file or weakening the assertion.

Both fixes restore the file to fully green without changing command
behavior. See `tests/Unit/Console/NsfGovernanceCheckCommandTest.php`.

## 13. Next sprint

Roadmap next recommended sprint after NSF-10: **CACHE-1 — Cache Strategy,
Redis Readiness & Invalidation Governance** (`config/foundation_roadmap.php`
marks `NSF-10` `status: completed`, so
`architecture:foundation-roadmap-check` now reports `CACHE-1` as
`next_recommended_sprint`). RC-1 remains locked as the final item after the
full expansion track.
