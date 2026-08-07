# CICD-FIX-1 — Vite Manifest Test Environment & Authoritative Gate Recovery

**Status:** PROPOSED — not started.
**Type:** Corrective CI sprint. Not a feature sprint, not a runner sprint.
**Blocks:** CICD-CTRL-3 closure (see `docs/sprints/cicd-ctrl-3-dedicated-self-hosted-ci-runner.md`).
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`).

---

## 1. Why this sprint exists

CICD-CTRL-3 fixed a gate-integrity defect: piped test steps returned `tee`'s exit
status, so a failing Pest run rendered as a green gate. With strict exit
propagation in place, CI now reports what was always true.

Two bodies of **pre-existing** CI debt became visible. Neither was introduced by
CICD-CTRL-3, and neither may be fixed inside it.

### 1.1 Evidence that this is pre-existing

The same failure set appears on the canonical GitHub-hosted path **before the
CICD-CTRL-3 branch existed**:

| Run | Date | Branch | Runner | Failures | Distinct | CI verdict |
|---|---|---|---|---|---|---|
| `30189642130` | 2026-07-26 | base | GitHub-hosted | **62** | 61 | **`success`** (masked) |
| `31143050283` | 2026-08-07 | CICD-CTRL-3 | GitHub-hosted | **62** | 61 | same set |
| runner-local | 2026-08-07 | CICD-CTRL-3 | self-hosted + PG 16 | **62** | 61 | same set |

The normalised failing-test-name sets are **identical across all three** — empty
diff in both directions. The CICD-CTRL-3 branch existed only from 2026-08-06, so
the 2026-07-26 run pre-dates it by eleven days.

---

## 2. Debt item A — the 62 failures were TWO classes, not one

> **Classification correction (2026-08-08).** The 62 failures were originally
> described as "62 Vite-manifest failures". That is wrong and must not be
> repeated. Re-reading the authoritative baseline log for run `30189642130`
> (2026-07-26) shows two distinct root causes:
>
> | Class | Baseline `30189642130` | Cause |
> |---|---|---|
> | Vite manifest | **254 error occurrences** | `@vite` in `layouts/app.blade.php` with no built manifest |
> | Governance JSON | **5 `JsonException`** | nested `Artisan::call` draining the outer command's output |
>
> The two are unrelated and were fixed separately. The governance JSON failures
> are **not** Vite failures and never were; they reproduce only on PostgreSQL,
> where the Vite failures are driver-independent.

### Observed — Vite manifest

```
Vite manifest not found at: .../public/build/manifest.json
  (View: resources/views/layouts/app.blade.php)
  in vendor/laravel/framework/src/Illuminate/Foundation/Vite.php:946
```

254 occurrences across the run. Affected suites: RME, LegacyRme, Inventory,
Architecture, DataQuality.

### Observed — governance JSON (separate cause, PostgreSQL only)

```
JsonException: Syntax error
  at json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)
  in NsfGovernanceCheckCommandTest, FoundationGovernanceSummaryCommandTest,
     Dmo3DeferredMetricBacklogClosureTest
```

`architecture:nsf-governance-check --json --include-observability` emitted zero
bytes and still exited 0, so the decode ran against an empty string. Cause:
`NsfApplicationRulesService::inspectPgStatStatements()` called
`Artisan::call(...)` then `Artisan::output()`;
`Illuminate\Console\Application::call()` reassigns the shared `lastOutput` even
when an explicit buffer is passed, and reading it drains the buffer, so the
inner call destroyed the outer command's output. PostgreSQL-only because the
`pg_stat_statements` branch does not run on other drivers.

### Observed contributing condition

`critical_test_gate` installs Composer dependencies only. It does not run
`npm ci` or `npm run build`, so `public/build/manifest.json` does not exist when
tests render Blade views that extend `layouts/app.blade.php`.

**This is an observation, not a conclusion.** Do not assume the fix.

### Required root-cause analysis (do this FIRST)

Answer, with evidence, before changing anything:

1. **Why do these tests need built assets at all?** A backend feature test
   asserting authorisation, privacy, or workflow state arguably should not
   depend on a compiled frontend bundle.
2. **Is the dependency intentional or incidental?** Determine whether each test
   genuinely asserts rendered output, or merely renders a page as a side effect
   of asserting an HTTP status or a privacy invariant.
3. **When did it start?** Identify the commit/sprint where these tests began
   requiring the manifest. Was a layout changed to always `@vite`?
4. **Does it affect the full suite too**, or only the critical filter? The full
   suite gate runs after `npm ci`/`npm run build` in some jobs and not others —
   establish which gates are and are not affected.
5. **Do the tests pass locally with assets built?** Confirm the manifest is the
   only cause and there is no second latent failure behind it.

### Candidate solutions — evaluate, do not presume

| Option | Consideration |
|---|---|
| Build assets in the critical gate (`npm ci && npm run build`) | Simplest, but adds minutes to every run and makes a backend gate depend on the frontend toolchain. |
| Share a built-asset artifact from the quality gate | Avoids rebuilding; adds inter-job coupling and an artifact download. |
| Test-level Vite bypass (e.g. `withoutVite()`) | Laravel supports disabling Vite in tests. Correct if the tests never meant to assert bundled assets. Must not mask real rendering regressions. |
| Fix test bootstrapping | If a base TestCase should disable Vite for non-frontend tests. |
| Fix stale view/rendering tests | If the tests assert on a layout they no longer need. |
| Commit a manifest stub | **Rejected on sight** — would fake a build artifact. |

The chosen fix must be justified by the root-cause analysis, not by whichever is
fastest to make CI green.

---

## 3. Debt item B — 3 files failing `pint --test`

Also exposed by strict propagation (the quality gate's pint step was piped too):

| File | Fixers | Origin |
|---|---|---|
| `routes/web.php` | `ordered_imports` | pre-existing |
| `tests/Feature/Satusehat/Satusehat4dMultiBranchMatrixTest.php` | `no_unused_imports` | SATUSEHAT-4D |
| `app/Console/Commands/StressSeedFoundationCommand.php` | `class_attributes_separation`, `concat_space`, `unary_operator_spaces`, `not_operator_with_successor_space`, `blank_line_before_statement` | pre-existing |

None belong to CICD-CTRL-3. Note the trap that hid these: `pint --dirty --test`
(used in local pre-commit checks) inspects only changed files, while CI runs
`pint --test` across the whole repository. A local `--dirty` pass does not imply
CI will pass.

---

## 3b. Debt item C — 5 governance `JsonException` failures (PostgreSQL only)

Part of the same 62, but a wholly separate cause from the Vite manifest. See the
classification correction in section 2.

| | |
|---|---|
| Symptom | `JsonException: Syntax error` decoding `Artisan::output()` |
| Affected | `NsfGovernanceCheckCommandTest`, `FoundationGovernanceSummaryCommandTest`, `Dmo3DeferredMetricBacklogClosureTest` |
| Trigger | `--include-observability` on PostgreSQL |
| Cause | nested `Artisan::call` reassigns and drains the shared `lastOutput` |
| Secondary defect | `json_encode` failure returned exit 0 with empty stdout |

Ruled out by a recursive pre-encode diagnostic on PostgreSQL 16: no invalid
UTF-8, NAN, INF, resource or closure anywhere in the report, and `json_encode`
succeeded. `Dq1AuditService` was a victim of the aborted transaction, not the
cause — its `audit()` completes cleanly inside a transaction.

**Trap worth recording:** passing an explicit `BufferedOutput` to
`Artisan::call()` does **not** protect the caller. `Illuminate\Console\Application::call()`
is `$this->run($input, $this->lastOutput = $outputBuffer ?: new BufferedOutput)`
— it assigns whatever buffer you hand it to the shared `lastOutput`. The nested
command must be run via `find(...)->run($input, $buffer)` instead.

---

## 4. Acceptance criteria

1. Root-cause analysis for debt item A is documented **before** any fix.
2. The previously failing tests are run **first**, in isolation, and pass.
3. The **complete** critical gate then runs and proves the whole 62 → 0, counted
   per class: **254 Vite manifest errors → 0** and **5 governance JSON
   failures → 0**.
4. `pint --test` (whole repository, not `--dirty`) passes.
5. No assertion weakened, no test skipped, no coverage reduced, no manifest
   faked. If a test genuinely should not render a layout, that must be argued
   explicitly and reviewed — not applied silently.
6. Strict exit propagation stays in place; it is not rolled back to obtain green.
7. Both runners are re-verified after the fix and must still produce **identical
   failure sets** (now empty), preserving the CICD-CTRL-3 equivalence guarantee.

---

## 5. Explicitly out of scope

- Any change to the self-hosted runner, its pinned PHP 8.3 image, the pinned
  `postgres:16` CI database, routing, or fallback. CICD-CTRL-3 owns those.
- Enabling Pest `--parallel` on the authoritative gate.
- Merging or GO-tagging CICD-CTRL-3.
- LEGACY-RME-PDF-1B.

---

## 6. Relationship to CICD-CTRL-3

CICD-CTRL-3 is **IMPLEMENTED / RUNNER OPERATIONAL / EQUIVALENCE PROVEN**, held at
**WATCH — PRE-EXISTING AUTHORITATIVE CI FAILURES**. Its runner result equivalence
is PASS; the application test gate is RED for reasons that pre-date it.

Once CICD-FIX-1 is green, CICD-CTRL-3 closure resumes: authoritative CI on the
exact candidate, outage-queueing validation, final timing comparison, merge,
post-merge runner validation, revoke the temporary `NOPASSWD` entry, cleanup, and
only then the immutable GO tag.
