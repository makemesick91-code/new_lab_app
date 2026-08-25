# FIX-TEST-TEMPFILE-SIBLING-LEAKS-1

**Every temporary artifact a test creates has exactly one owner that can reach it.**

- Base authority: `baecf7c4808ba49d4f228e828fc5a435592f64df`
  (`fix-lab-analytics-median-lateness-day-boundary-1-go`, peeled)
- Branch: `feature/fix-test-tempfile-sibling-leaks-1`
- Type: `RUNTIME_FIX` / module `TEST_INFRASTRUCTURE`
- `RUNTIME_APPLICATION_FILES_CHANGED=0` · `RUNTIME_BEHAVIOR_CHANGE=false`
- `FULL_SUITE_EXECUTION_COUNT=0` — not authorised; the previous authorisation was
  consumed by run `32700184849` and it FAILED.
- Stabilization programme closure remains **NO-GO pending a new authorised Full
  Suite**. This sprint does not close it.

---

## 1. What this closes

`FIX-PDF-TEMPFILE-LEAK-1` fixed `pdfWithTempFile` — the one call site every PDF
assertion routes through — and recorded the rest of the family as a follow-up
candidate rather than fixing it. This sprint closes that backlog.

The handover listed four prefixes and roughly 188 orphans. Re-measured on this
base authority the surface was **392 orphans across ten prefixes in eight test
files**, and six of those call sites had no cleanup written on any path at all.

| Prefix | Call site | Shape | Orphans | Classification |
|---|---|---|---:|---|
| `ffcache` | `FeatureFlagRuntimeOverrideTest` | `tempnam(...).'.php'` | 79 | REAL_LEAK |
| `lrme-poppler-` | `LegacyRmePopplerIntegrationTest` | `tempnam(...).'.pdf'` | 20 | REAL_LEAK |
| `leg` | `LegacyPatientBatchImportTest` | `tempnam(...).'.csv'` | 12 | REAL_LEAK ×2 |
| `bad` | `LegacyPatientBatchImportTest` | `tempnam(...).'.csv'` | 0 | REAL_LEAK |
| `ctl3a-home-` | `SelfHostedHealthFailClosedTest` | `mkdir`, never removed | 51 | REAL_LEAK |
| `ctl3a-bin-` | `SelfHostedHealthFailClosedTest` | `mkdir`, never removed | 36 | REAL_LEAK |
| `ctl3d-bin-` † | `SelfHostedHealthFailClosedTest` | `mkdir`, never removed | 11 | REAL_LEAK |
| `ctl3c-` † | `RuntimeEvidenceTruthfulnessTest` | `mkdir`, never removed | 40 | REAL_LEAK |
| `rdrs1-` † | `RestoreDrillEvidenceReadStateTest` | `mkdir`, never removed | 124 | REAL_LEAK |
| `ci-bare-host-` † | `DedicatedSelfHostedRunnerTest` | `mkdir`, never removed | 16 | REAL_LEAK |
| `infra-sec-env-1-` † | `SecretFilePermissionHardeningTest` | cleaned on the passing path only | 3 | REAL_LEAK (exception path) |
| `ctl3a-evidence-` | `SelfHostedHealthFailClosedTest` | `tempnam`, unlink outside `finally` | 0 | REAL_LEAK (exception path) |
| `ctl3a-wf-` | `SelfHostedHealthFailClosedTest` ×3 | project tree, unlink outside `finally` | — | REAL_LEAK (exception path) |
| `lrme-render-` | `LegacyRmePopplerIntegrationTest` | `mkdir` + `finally` rmdir | 0 | **SAFE** |
| `unit`, `cicd-ctrl-`, `tfs-policy-`, `tfs-wf-`, `pilot-log-`, `devflow-fix-base-ref-1-`, `dh1-`, `dh1lock-`, `infra-sec-runtime-1-`, `stress-bench-` | various | scoped, cleaned | 0 | **SAFE** |

† Not in the handover. Found by the repository-wide same-class census, which is
why the census was run instead of trusting the list.

`bad` had zero files in `/tmp` at census time and is shape-identical to `leg`;
rather than classify it HISTORICAL_ONLY from an absence it was **reproduced** —
the base authority run created `badlomlc8gf5p4918NAnsC.csv`. The temporary
directory had simply been reaped since the last run of that suite.

The one call site the handover said "never cleans at all" is confirmed and
located: it is the `ctl3a-` / `ctl3d-` family in `SelfHostedHealthFailClosedTest`,
and the same shape turned out to hold for `ctl3c-`, `rdrs1-` and `ci-bare-host-`.

## 2. Baseline reproduced, not inferred

The standing `/tmp` census is contaminated by every earlier run on this machine,
so it proves accumulation but not that the current code still leaks. Five suites
were therefore run at the unmodified base authority:

```
BASELINE_LEAK_TOTAL = 131 orphans from a single pass
Tests:               98 passed (370 assertions)
```

**All 98 tests passed while stranding 131 artifacts.** That is the measurement
the fix is judged against.

## 3. The fix

Two different lifecycles needed two different owners, which is why they were not
refactored into one helper until they had been measured.

**Scoped** — created, used and destroyed inside one call. A `finally` is the
whole contract, and the only defect was owning two artifacts while cleaning one.
`ffcache` and `lrme-poppler-` drop the derived suffix and keep the allocation
itself as the document, exactly as `pdfWithTempFile` now does. The suffix was
proven unnecessary rather than assumed: `require` executes a file with no `.php`
extension, and `pdfinfo`/`pdftoppm` read an extensionless file identically —
both this module's inspector and Poppler dispatch on the `%PDF-` header.

**Deferred** — must outlive the function that created it, so no `finally` can
reach it: a stub PATH directory a child process still has to read, an
`UploadedFile` whose HTTP request has not run yet. These go through a registry
in `tests/Pest.php`:

```php
tempArtifactFile(string $prefix): string      // tempnam, recorded, ONE artifact
tempArtifactDir(string $prefix, int $mode)    // mkdir, recorded, atomic
releaseTempArtifacts(): int                   // removes exactly what was recorded
```

drained by a global `afterEach` so a call site cannot forget it, and so it runs
on the failing path — which is precisely where `SecretFilePermissionHardeningTest`'s
existing explicit `removeFixtureDir()` calls were being skipped. Those calls are
left in place; the drain is the net beneath them, and is idempotent.

The drain removes **exactly the recorded paths**. It is never a prefix glob, so
a concurrent test process's artifacts and the historical orphans of earlier runs
are safe by construction rather than by luck. `tempArtifactRemove()` additionally
refuses any path outside `sys_get_temp_dir()`, and tests `is_link()` before
`is_dir()` on every entry.

`ctl3a-wf-` fixtures live in the project tree, not `/tmp`, so the confined
registry cannot own them; each got its own `finally` instead.

## 4. Two defects in the guard itself, found by mutation

Mutation testing was not a formality here — it found two real defects in the new
guard.

**The symlink test was testing the benign case.** It linked to a *file*.
`unlink()` never follows a symlink, so even a remover with the `is_link()` check
deleted away it destroys only the link: the test passed with the guard removed.
The catastrophic shape is a link to a *directory*, which `is_dir()` follows —
an unguarded remover then `scandir`s INTO the target and deletes its contents.
Proven directly (`is_link=1 is_file=0 is_dir=1` → `would delete: precious.txt`).
The test now builds the dangerous shape and covers the benign one as well.

**The confinement test failed by causing the damage it forbids.** It asserted
`tempArtifactRemove(base_path('composer.json'))` returns false — and when the
confinement was mutated away to check the assertion could fail, it failed *by
deleting `composer.json`*. The probe is now a fixture the test creates itself.

| Mutation | Expectation | Result |
|---|---|---|
| M1 restore `tempnam(...).SUFFIX` (ffcache) | shape check fails | **failed** ✓ |
| M2 remove the global `afterEach` drain | handoff pair fails | **failed** ✓ |
| M3 remove the `is_link()` guard | symlink test fails | passed → **guard repaired** → **failed** ✓ |
| M4 remove `/tmp` confinement | confinement test fails | **failed** ✓ (and no longer destructive) |
| M5 drain becomes a prefix glob sweep | foreign-artifact test fails | **failed** ✓ |
| M6 allocations no longer registered | single-artifact test fails | **failed** ✓ |
| M7 exception-path drain removed | throw test fails | **failed** ✓ |

`MUTATION_RESIDUE=0`.

## 5. Verification

```
Modified suites, all 11 together     216 passed (837 assertions)
tests/Unit                           246 passed, 7 skipped (2662 assertions)
New guard                             15 passed (98 assertions)

TASK_CREATED_TEMPFILES_AFTER_CLEANUP = 0
SUCCESS_PATH_LEAKS       = 0
EXCEPTION_PATH_LEAKS     = 0
FAILURE_PATH_LEAKS       = 0
REPEAT_DELTA (10 cycles) = 0

Historical baseline before = 447
Historical baseline after  = 447
HISTORICAL_ORPHANS_DELETED_BY_SPRINT = 0
```

Correctness is proven by a **zero task-created delta**, not by deleting the
historical orphans to make the census look clean. The 131 artifacts the baseline
reproduction itself created were removed by exact path from the recorded diff —
never by `rm -f /tmp/<prefix>*`.

## 6. CI

`tests/Feature/Cicd/TempFileSiblingLeakContractTest.php` is declared in
`config/ci_runner.php` → `critical_gate_mandatory_suites`, so
`CriticalGateSuiteCoverageTest` enforces its selection instead of leaving it to a
filter token a future edit could drop. All **15** of its tests were confirmed
selected by running each critical filter through `pest --list-tests`; the two
filters are byte-identical, so both runner variants execute it.

`TEMPFILE_SIBLING_LEAK_GUARD_EXECUTED_IN_REQUIRED_CI=true`.

## 7. Scope

`app/Console/Commands/StressBenchmarkRmePagesCommand.php` allocates a
`stress-bench-` cookie jar and is the only `tempnam()` outside `tests/`. It is
application code, showed **zero** orphans in the census, and was therefore
classified and left alone: this sprint does not change runtime behaviour.

`git diff --name-only` against the base is `tests/`, `config/ci_runner.php`,
`.cursor/rules/`, `.sprint/`, `docs/` and `CLAUDE.md` only.
