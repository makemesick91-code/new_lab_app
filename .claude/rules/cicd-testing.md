---
paths:
  - ".github/**"
  - "scripts/ci/**"
  - "config/ci_*.php"
  - "config/cicd_enterprise_gate.php"
  - "app/Support/Cicd/**"
  - "phpunit.xml"
  - "tests/**"
---

# CI/CD gates, Full Suite policy & test determinism — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

## Invariants

### Gate selection and routing

- **AIR-CIT-01** Gate selection comes only from `scripts/ci/resolve-gates.sh`, governed by
  `config/ci_runtime_control.php`. Uncertainty (no diff, unreachable base, unknown path, a `workflow_dispatch` with no
  `github.event.before`) resolves to `unknown_high_risk` and runs every gate. `docs_only` (every changed file is
  `*.md` or under `.cursor/rules/`, any extension) is the only profile that may skip the critical Pest step; never widen
  `skip_critical_profiles`. Because `CLAUDE.md`, `.claude/rules/*.md` and `.cursor/rules/*` classify as `docs_only`,
  `DevflowAiInstructionSystemTest` also runs in the always-on NSF-R012 quality gate (step `AI instruction system
  contract`); never remove it from there. Source:
  `.cursor/rules/67-safe-ci-runtime-control.mdc`, archive `CICD-CTRL-1`.
- **AIR-CIT-02** Resolve the profile with the real tool before pushing
  (`scripts/ci/resolve-gates.sh --base origin/<base> --head HEAD`). `.sprint/current.yml` matches no pattern, so a real
  sprint is never `docs_only`; never manufacture a docs-only change set to obtain a weaker profile. Source:
  `.cursor/rules/107-global-temporary-full-suite-policy.mdc`.
- **AIR-CIT-03** The `classify` job diffs against the immutable `github.event.pull_request.base.sha`, never the moving
  `origin/<base_ref>`, and fails closed when the base cannot be resolved. In shell, `git rev-parse --verify -- <rev>` is
  wrong (`--` turns the rev into a path); block option injection by validating the ref. Source: archive
  `DEVFLOW-FIX-BASE-REF-1`.
- **AIR-CIT-04** `quality_gate`, `release_safety_gate` (NSF-9) and `nsf10_release_evidence_gate` are never gated by the
  classifier. NSF-9 and NSF-10 depend on **both** critical variants and require one to have genuinely succeeded; a
  skipped sibling never satisfies them. Source: `67-safe-ci-runtime-control.mdc`, archive `CICD-CTRL-3`.
- **AIR-CIT-05** `critical_test_gate` and `critical_test_gate_self_hosted` share the check name `NSF-R011 Critical
  Test Gate` with mutually exclusive `if`s: exactly one runs, never both, never neither. Their `--filter` strings,
  provisioning steps and warning assertions must stay byte-identical. Fork PRs are redirected to GitHub-hosted, a
  runner outage queues the gate (no failover), and fallback is the repository variable `CI_RUNNER_MODE` or the
  `runner_mode` input (default `github-hosted`). Source: `.cursor/rules/91-dedicated-self-hosted-ci-runner.mdc`.
- **AIR-CIT-06** Self-hosted jobs target the full label set `[self-hosted, linux, x64, daengtisia-ci]` (never a bare
  `self-hosted`); the classifier stays GitHub-hosted; every DB-heavy job runs `php artisan
  ci:assert-non-production-database` before migrating; the runner is the unprivileged `github-runner` systemd service
  (never root, never in the `docker` group) and holds no production material. `deploy-vps.sh`/`rollback-vps.sh` never
  appear in a CI workflow. Source: `91-dedicated-self-hosted-ci-runner.mdc`.
- **AIR-CIT-07** Every gate step that pipes into `tee` runs under `shell: bash` (pipefail) and is registered in
  `config/ci_runner.php` `strict_pipeline_steps`; `NsfReleaseGateExitPropagationTest` mutation-proves that removing the
  strict shell restores a false green. Never pipe a test command without it. Source:
  `.cursor/rules/92-full-suite-baseline-failure-debt.mdc`, archive `CICD-CTRL-3`.

### Selection registry

- **AIR-CIT-08** Two registries answer two questions: `critical_gate_required_filters` ("is this token present?") and
  `critical_gate_mandatory_suites` ("does this suite run?"). A suite that protects a production property needs a token
  in **both** variants **and** a registry entry; `SelfHostedRunnerScanner::criticalGateSuiteCoveragePosture()` and
  `CriticalGateSuiteCoverageTest` fail on an unselected, stale (file gone), single-variant or empty registry. Equivalent
  coverage in another incidentally-matched suite is never the authority. Source:
  `.cursor/rules/120-critical-gate-mandatory-suite-coverage.mdc`, `.cursor/rules/130-raw-sql-driver-parity-and-gate-selection.mdc`.
- **AIR-CIT-09** `--filter` is a case-insensitive regex over the full `Class::method` name, including Pest
  descriptions. Prove selection with `pest --list-tests` against the gate's exact filter, never by reading it: `LegacyRme`
  does not select `LegacyMassUploadRmeTest`, and a bare `Monitoring` token pulled in 15 unrelated files. Prefer the
  narrowest token; a new suite under `tests/Unit/Services/Monitoring` must be declared. Source:
  `120-critical-gate-mandatory-suite-coverage.mdc`, `.cursor/rules/121-final-stabilization-residual-audit.mdc` (R13),
  archive `FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1`.
- **AIR-CIT-10** The Selective Module Gate runs only `Inventory`, `Lab`, `Ui` and `Permission|AccessControl`; no job
  consumes the classifier's `run_rme_tests`. Neither it nor the deferred Full Suite substitutes for the critical gate.
  `Permission` matches by name across directories, so role exact-list pins outside `AccessControl` fire there too.
  `tests/Feature/Cicd` itself must be selected (the `Cicd` token) so CI's own tooling is tested in a required gate.
  Source: `120-…`, `91-…` (rule 15), `121-…` (R13).

### Warning contract

- **AIR-CIT-11** `config/ci_runner.php` `critical_gate_warning_contract.expected_warning_count` is `0` with no
  warning-text allowlist; `php artisan ci:assert-critical-gate-warning-contract` runs only after the test exit status
  has been checked, so it can never turn a red gate green. Never raise the baseline to absorb a warning — name its
  emitter, its resource and its exit-code effect, then fix the cause. Source:
  `.cursor/rules/119-critical-gate-warning-contract.mdc`.
- **AIR-CIT-12** Never suppress: no `@` added to silence a diagnostic, `error_reporting(0)`, `2>/dev/null`,
  `grep -v warning`, `ignoreSuppressionOfPhpWarnings` or `restrictWarnings`; this is scanned across every workflow,
  `scripts/ci/*.sh` and `phpunit.xml`. Do not add `npm run build` to the critical gate (`tests/TestCase.php` calls
  `withoutVite()`). Source: `119-critical-gate-warning-contract.mdc`.
- **AIR-CIT-13** Every app-booting CI job runs the step `Provision the CI environment file`, writing an **empty**
  environment file (truncating) before any test; the historical 2222 warnings were the framework's optional read of the
  missing file. Never populate it from the tracked example file. (supersedes the retracted Vite-manifest theory and
  rule 92's "cause unidentified") Source: `119-…` (R6), archive `CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1`.
- **AIR-CIT-14** Missing, unreadable, read-failed, empty and valid are five states: check `is_file()`, then
  `is_readable()`, then the result `=== false`; never `file_get_contents(...) ?: ''` or a `(string)` cast; every
  non-readable state fails closed. `CriticalGateWarningContractTest` pins an exact set of suppressed reads — moving one
  means updating it, and the comment explaining the avoidance must not contain the operator it avoids. Source:
  `119-…` (R4), archive `RESTORE-DRILL-EVIDENCE-READ-STATE-1`.

### Full Suite

- **AIR-CIT-15** The policy state lives only in `.github/ci-policy/full-suite-policy.json`, read by
  `scripts/ci/resolve-full-suite-policy.sh` and `App\Support\Cicd\CiRuntimeControlScanner`; resolution fails closed to
  ACTIVE. `full_suite_gate` additionally requires `needs.classify.outputs.full_suite_authorized == 'true'`; `schedule`
  and `push` to base are deferred, and only `workflow_dispatch` with both `run_full_suite=true` and
  `full_suite_policy_override=true` authorizes. Never delete the job or its triggers. Source: `107-…`, archive
  `CI-TEMP-FULL-SUITE-SCHEDULE-GATE`.
- **AIR-CIT-16** One explicit authorization is one attempt, consumed at trigger. FAIL, TIMEOUT, CANCELLED, PARTIAL,
  runner loss, wrong SHA and misroute are terminal: no automatic rerun. The tested tree is frozen from trigger and must
  equal the merged, deployed and GO-tag tree; run evidence goes in the annotated tag and the CI run, never a post-PASS
  commit. Source: `.cursor/rules/126-authoritative-full-suite-and-final-closure.mdc`.
- **AIR-CIT-17** Never retire the temporary policy inside a closure candidate (a RETIRED status makes the merge push an
  authorized automatic Full Suite). Never dispatch twice on one ref: the workflow's `concurrency:
  foundation-evidence-${{ github.ref }}` has `cancel-in-progress: true`. Source: `126-…`, archive
  `AUTHORITATIVE-CONSOLIDATED-FULL-SUITE-2`.
- **AIR-CIT-18** Cancel a post-merge push run only when the candidate was green on an identical tree, the cancel lands
  before the Full Suite job starts tests, and the `startedAt == completedAt` proof is recorded; never cancel a
  scheduled run or one already executing. Verify execution from the step:
  `gh run view <id> --json jobs --jq '.jobs[]|select(.name=="NSF-R011 Full Suite Gate")|.steps[]|select(.name|test("full Pest suite"))|.conclusion'`.
  Source: `107-…`.
- **AIR-CIT-19** The Full Suite runs only on `ubuntu-latest` with a `postgres:16` service (never self-hosted). The
  baseline is `EXPECTED_FULL_SUITE_FAILURE_BASELINE = 0`, so no subtraction path may exist; a baseline names exact
  tests, signatures, run and SHA; deleting or skipping a failing test is not a fix; compare test and assertion
  inventory to rule out filter drift; wrong checkout, runner outage and orphaned workers are environment faults, not
  debt. Source: `92-full-suite-baseline-failure-debt.mdc`.
- **AIR-CIT-20** A readiness audit measures and hands off: `RUNTIME_APPLICATION_FILES_CHANGED = 0`, a defect becomes
  NO-GO plus a named corrective sprint, and readiness GO only permits *asking* for a Full Suite. Coverage is bounded —
  running every directory is a Full Suite under another alias. Source:
  `.cursor/rules/125-final-pre-full-suite-readiness-gate.mdc`, `121-…` (R4).

### Test determinism and traps

- **AIR-CIT-21** Never assert a faker value against the raw body (`content())->toContain($model->name)`); use
  `assertSee()` (it escapes) or `assertSee('…'.e($value).'…', false)`. Pin fixture values, including one with an
  escapable character. A computed value must be proven real first: `assertSee(null)` passes against anything. Source:
  `92-…` (rule 13), `121-…` (R6).
- **AIR-CIT-22** `pdftotext -layout` output is a layout serialization: never assert a wrap-capable value is a
  contiguous substring. Read it from its column with `pdfLayoutFieldValue()`, `pdfLayoutColumnText()`,
  `pdfLayoutFullWidthText()` and `pdfNormalizeText()` (`tests/Pest.php`); never flatten the page; pin a fixture that is
  proven to wrap; never change runtime layout to satisfy a test. Columns are placed per page. Source:
  `.cursor/rules/122-pdf-text-contiguity.mdc`.
- **AIR-CIT-23** `tempnam()` creates the file it names: use the allocation as the payload path and clean it in
  `finally` (pattern: `pdfWithTempFile()`), preserving the original exception. Artifacts that outlive their creator go
  through `tempArtifactFile()`/`tempArtifactDir()`, drained by the global `afterEach` (`releaseTempArtifacts()`). Raw
  `mkdir` under the temp root in governed CI tests is forbidden. Source:
  `.cursor/rules/123-test-temporary-file-ownership.mdc`.
- **AIR-CIT-24** Cleanup removes exactly recorded paths, never a prefix glob or `/tmp` sweep; the remover tests
  `is_link()` before `is_dir()` and stays inside `sys_get_temp_dir()`; never swap `tempnam()` for `uniqid()`. Measure a
  leak by giving a child process a private `TMPDIR` and counting everything left — a known-prefix census is diagnostic
  only. Source: `123-…`, `125-…` (§3).
- **AIR-CIT-25** Pin time with `pinTestClock('Y-m-d H:i:s')` and pair it with `freeTestClock()`; anchor both endpoints
  of a duration (deliver relative to the deadline, not to `now()`); cover both edges of the window that broke; never
  widen `>` to `>=`; reproduce at the failing instant. Clinical dates use `clinicalToday()`, other modules their own
  authority. Source: `.cursor/rules/124-time-dependent-test-determinism.mdc`.
- **AIR-CIT-26** A Monitoring assertion on `overall_status` or an exit code pins every section it is not testing:
  `skip_db`, `skip_http`, and `pilotSnapshotDiskProbe($freeGb)` injected as the `diskProbe` constructor argument. Never
  recompute `worst()` in the test, and never expose the probe as a flag, option, config key or binding. Source:
  `.cursor/rules/112-monitoring-baseline-zero-known-failures.mdc`, archive `TEST-BASELINE-MONITORING-FAILURES-1`.
- **AIR-CIT-27** Before diagnosing a storage or evidence failure, confirm one Pest process per worktree
  (`ps -eo pid,cmd | grep pestphp/pest/bin/pest`): `Storage::fake` deletes the shared fake disk, and
  `pkill -f 'artisan test'` leaves the Pest child running. Shared-evidence suites run serially. Source: `123-…`,
  `126-…` (rules 12–13).
- **AIR-CIT-28** PostgreSQL aborts the whole transaction on any failed statement (`SQLSTATE 25P02`): wrap an intended
  constraint violation in a nested `DB::transaction()` (savepoint). `lockForUpdate()` compiles to nothing on SQLite and
  `RefreshDatabase` never commits, so a row-lock proof needs its own connection on PostgreSQL 16 and must skip loudly
  elsewhere. Verify on a pinned `postgres:16`, not a newer local server. Source: archive `CICD-CTRL-3`, `130-…` (R2).
- **AIR-CIT-29** A raw SQL literal must never end on a backslash: PDO's placeholder rewriter on PHP 8.3 + pdo_pgsql
  treats `ESCAPE '\'` as escaping the quote and binds too few parameters (HY093). Use `!` from one named constant read
  by both the clause and the escaper. Source: `130-…` (R1).
- **AIR-CIT-30** Adding a rule to a governance service means repinning every exact-list `toBe([...])` **and** every
  `toHaveCount(N)` for that family, swept by service file (not id prefix), then running the suite — a count never names
  what it counts. Source: `121-…` (R14).
- **AIR-CIT-31** Mutation harnesses revert by file copy, never `git checkout --` (it destroys untracked and uncommitted
  work); a mutant that did not change the file is NOT-APPLIED, never KILLED; after restoring a Blade file bump its mtime
  and `view:clear`. Local real-disk tests can pass on stale artifacts from an earlier run. Source: `121-…` (R11),
  archive `BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1`.
- **AIR-CIT-32** Workflow-parsing tests read `$workflow['on']` (Symfony YAML keeps `on:` as a string); runtime probes
  build an explicit PATH (`stubRuntimeBin()`), never the host's bin directories; local `pint --dirty --test` checks
  only changed files while the quality gate checks the whole repository. Source: archive
  `CI-TEMP-FULL-SUITE-SCHEDULE-GATE`, `91-…` (rule 16), archive `CICD-CTRL-3`.
- **AIR-CIT-33** Critical Gate parallelism is governed by the committed policy
  `.github/ci-policy/critical-parallel-policy.json` (`OFF`): pull_request/push/schedule run sequentially with the exact
  pre-CI-PERF-04 command and ignore `CI_CRITICAL_TEST_WORKERS`; the only parallel path is a labelled evaluation
  `workflow_dispatch` (`critical_test_workers`); every uncertainty fails toward 1 worker; both critical variants carry
  identical steps; worker databases are dropped before a parallel run; the warning contract strips terminal styling in
  memory (ParaTest's summary is ANSI-styled). Activation = reviewed `ACTIVE` change + explicit owner approval. Source:
  `212-…`.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- GLOBAL TEMPORARY FULL-SUITE POLICY: `status: ACTIVE` in `.github/ci-policy/full-suite-policy.json`. The consolidated
  closure (tag `authoritative-consolidated-full-suite-2-final-program-closure-go`) did **not** retire it. Fix sprints
  report `FULL_SUITE_STATUS = SKIPPED_BY_GLOBAL_TEMPORARY_POLICY` and `FULL_SUITE_EXECUTION_COUNT = 0`.
- Critical filter measured 2026-10-26: 84 tokens, byte-identical in both variants; `critical_gate_mandatory_suites`
  holds 163 entries; `expected_warning_count` = 0.
- Runner routing defaults to `github-hosted`. The self-hosted runner in service runs native mode (host PHP 8.3, native
  PostgreSQL 16) and measured ~3× slower than GitHub-hosted; never quote the retired laptop's 1.34× figure.
- The critical gate took ~93 minutes on 2026-10-03; an `unknown_high_risk` change also runs four module suites
  (~53 minutes). Duration is not a health signal — check which step is running; fast runs are usually docs-only skips.
- Accepted residual host couplings (rule 112 R6): `LegacyRmeIngestionCapacityTest` disk floor,
  `HealthCheckService` free-percent, `self-hosted-runner-health.sh` free-disk. Do not rediscover them as new.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/67-safe-ci-runtime-control.mdc` | Classifier profiles, always-on gates, no path filtering |
| `.cursor/rules/91-dedicated-self-hosted-ci-runner.mdc` | Two critical variants, runner security, fallback routing |
| `.cursor/rules/92-full-suite-baseline-failure-debt.mdc` | Zero baseline, escaping contract, Full Suite topology |
| `.cursor/rules/107-global-temporary-full-suite-policy.mdc` | Full Suite deferral, accidental triggers, evidence wording |
| `.cursor/rules/119-critical-gate-warning-contract.mdc` | Zero warnings, no suppression, five read states |
| `.cursor/rules/120-critical-gate-mandatory-suite-coverage.mdc` | Mandatory suite registry and token selection |
| `.cursor/rules/121-final-stabilization-residual-audit.mdc` | Residual classification, gate-selection proof, rule pins |
| `.cursor/rules/122-pdf-text-contiguity.mdc` | Column-aware PDF text assertions |
| `.cursor/rules/123-test-temporary-file-ownership.mdc` | `tempnam`, artifact registry, symlink-safe cleanup |
| `.cursor/rules/124-time-dependent-test-determinism.mdc` | Pinned clocks and duration fixtures |
| `.cursor/rules/125-final-pre-full-suite-readiness-gate.mdc` | Readiness audits measure, never repair |
| `.cursor/rules/126-authoritative-full-suite-and-final-closure.mdc` | One attempt per authorization, tree identity |
| `.cursor/rules/130-raw-sql-driver-parity-and-gate-selection.mdc` | Backslash literals on pgsql, SQLite ≠ PostgreSQL |
| `.cursor/rules/212-critical-gate-controlled-parallel-testing.mdc` | Critical Gate parallel policy (OFF), evaluation dispatch, worker DBs |
| `.cursor/rules/testing-expert.mdc` | General test standards |
| `.cursor/rules/112-monitoring-baseline-zero-known-failures.mdc` | Host-capacity-independent monitoring tests |
| `.cursor/rules/59-cicd-enterprise-gate.mdc` | ENT-10 enterprise CI/CD gate |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/13-cicd-testing.md`, plus `15-monitoring.md`
(`TEST-BASELINE-MONITORING-FAILURES-1`) and `08-lab.md` (`FIX-LAB-ANALYTICS-MEDIAN-LATENESS-DAY-BOUNDARY-1`). Grep by
sprint id; never read a whole file.
