# CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1

**Critical Gate warnings are attributable and judged; the environment-file read
state is declared, not left to a failed read.**

- Base: `60b4ce3746d6c90891f6b2625572ba260b73dc28`
  (`monitoring-undated-severity-escalation-1-go`)
- Branch: `feature/cicd-critical-gate-file-get-contents-warn-1`
- Type: `INFRA_RELEASE` · no migration · no permission · no product runtime change
- Full Suite: `DEFERRED_BY_GLOBAL_TEMPORARY_POLICY` (`FULL_SUITE_EXECUTION_COUNT=0`)

The objective was **not** "make CI output visually clean". It was to make the
gate's warnings attributable, actionable, deterministic and truthful.

---

## 1. The observation, reproduced from authoritative logs

Reference run `32616352306` (push, `success`), job `97137843814`
**NSF-R011 Critical Test Gate**, step *Run critical regression tests*:

| Measure | Value |
|---|---|
| Per-file status headers | **128 WARN, 1 PASS** (129 files) |
| Summary line | `Tests: 2222 warnings, 9 passed (9328 assertions)` |
| `critical_test_exit_status` | `0` |
| Lines mentioning the read failure | 263 |
| Longest surviving path fragment | `file_get_contents(/home/runner/work/new_lab_app…` |

Pest truncates the warning text to terminal width, which is why no earlier
investigation recovered a full path from CI logs alone.

## 2. Root cause — evidenced, not inferred

A local error-handler probe that boots the application recovered the untruncated
warning:

```
message:   file_get_contents(<base_path>/…): Failed to open stream: No such file or directory
raised_in: vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73
#2 Dotenv\Store\File\Reader::readFromFile
#3 Dotenv\Store\FileStore->read
#5 Dotenv\Dotenv->safeLoad
#6 Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables->bootstrap
#7 Illuminate\Foundation\Application->bootstrapWith
```

| Question | Answer |
|---|---|
| Emitting layer | PHP `E_WARNING`, surfaced by PHPUnit 11.5.50 as a **test warning** |
| Caller | `Dotenv\Store\File\Reader::readFromFile()` — `vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73` |
| Read target | The application's **environment file** at the repository root |
| Target contract | **Optional by framework design** (`safeLoad`) and deliberately never committed (INFRA-SEC-ENV-1) |
| Why it was absent | **By design.** No CI job provisions one; all six app-booting jobs configure the application through their own `env:` block |
| Why 128 of 129 | Every test that boots the application warns. The read happens once per `bootstrapWith()` |
| Why one file did not | `Tests\Unit\Services\Monitoring\PilotPerformanceSnapshotClassifierTest` has **no `uses(TestCase::class)`** — it never boots the application. Its 9 tests are exactly the `9 passed` |
| Why exit 0 | PHPUnit records a PHP warning as a warning, not a failure, and nothing in the workflow ever judged the warning count |

### 2.1 The retracted hypothesis stays retracted

The earlier "absent frontend build manifest" explanation is **not** the cause and
was correctly retracted. `tests/TestCase.php` calls `withoutVite()`, the Critical
Gate never builds the frontend, and no manifest path appears in any warning. No
`npm run build` was added.

### 2.2 The suppression operator is present and does not help

`phpdotenv` already writes `@\file_get_contents($path)`. PHPUnit's
`ErrorHandler` invokes the handler regardless and only honours suppression for
files on its own exclude list, so a third-party suppressed read still reaches the
test result. Turning on a global suppression toggle would have hidden every
vendor warning — rejected as broad suppression.

## 3. The fix — at the causal boundary

Every CI job that boots the application now writes an **empty** environment file
before running anything.

This is not a way to hide the warning. It is the accurate representation of the
state CI is already in: the read succeeds and yields **zero variables**, which is
exactly the configuration the job's `env:` block already resolved. The tracked
example file is deliberately **not** copied — that would inject values CI never
intended and change what is being tested.

Behaviour-neutrality was measured, not asserted:

| Condition | Resolved-config hash | Result |
|---|---|---|
| No environment file | `5397874e827131ef71d97b9d80d72159` | `6 warnings` |
| Empty environment file | `5397874e827131ef71d97b9d80d72159` | `6 passed` |

Faithful CI simulation over a real slice of the Critical Gate filter, with
`APP_KEY` supplied exactly as the job `env:` block supplies it:

| | File headers | Summary | Read-failure mentions |
|---|---|---|---|
| **Before** | 28 WARN, 1 PASS | `511 warnings, 9 passed (4040 assertions)` | 28 |
| **After** | 29 PASS | `520 passed (4040 assertions)` | 0 |

Identical assertion count — no test was narrowed, skipped or altered; warnings
became passes and nothing else moved.

## 4. The second defect — the gate had no opinion about warnings

Failures were always enforced, but a 2222-warning baseline made a genuinely NEW
warning indistinguishable from noise. The gate now declares its expectation:

- `config/ci_runner.php` → `critical_gate_warning_contract.expected_warning_count = 0`
- `App\Support\Cicd\CriticalGateWarningContract` — a plain, unit-testable reader
- `php artisan ci:assert-critical-gate-warning-contract` — exits non-zero on any
  unexplained warning

There is **no warning-text allowlist**. An expected condition is represented at
its causal boundary, never by matching the text of a warning still being emitted.

### 4.1 Real failure detection keeps strict precedence

In both variants the step exits on a non-zero test status **before** the contract
is consulted, so the contract can never turn a red gate green. Pinned by test in
both variants.

### 4.2 Resource-state matrix

The contract itself reads a file, so it models the states this sprint exists to
separate:

| State | Old gate behaviour | New contract | Exit |
|---|---|---|---|
| Evidence missing | not checked at all | `LOG_MISSING` | non-zero |
| Evidence unreadable | not checked at all | `LOG_UNREADABLE` | non-zero |
| Read failed | would fold into `''` | `LOG_READ_FAILED` | non-zero |
| Valid but empty | would fold into `''` | `LOG_EMPTY` | non-zero |
| No summary line | not checked at all | `summary_found=false` | non-zero |
| Zero tests reported | `success` | fails closed | non-zero |
| Failures present | red (unchanged) | red (unchanged) | non-zero |
| 2222 warnings | **`success`** | 2222 unexplained | non-zero |
| One new warning | invisible | 1 unexplained | non-zero |
| Clean run | `success` | `GO` | 0 |

## 5. Audited, not rewritten

Three application files predate this sprint and use a suppressed read. None
contributes to the Critical Gate baseline — the empty environment file alone
takes it to zero — so they were audited rather than rewritten, and pinned as an
**exact set** so a new adopter fails the guard:

| File | Verdict |
|---|---|
| `PilotPerformanceSnapshotService::readMemInfo()` | **Correct.** `is_readable()` guard, then explicit `=== false`. Reference shape |
| `RestoreDrillEvidenceService` | Casts a failed read to a string, so an unreadable file reports "invalid JSON" rather than "unreadable". **Verified to fail closed** — decision correct, only the reason is less specific. Left unchanged: RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1 is a preserved foundation and the Critical Gate warning does not involve it |
| `ArchitectureUiGovernanceCheckCommand` | 74 presence probes over optional design-system files, where "unreadable" and "absent" are deliberately the same outcome |

## 6. Verification

- New suite `tests/Feature/Cicd/CriticalGateWarningContractTest.php` — **21 passed**
- **6 mutation controls, all red**, tree restores clean: provisioning removed ·
  contract command removed · failure precedence removed · suppression marker
  added · baseline raised to 1 · read failure folded into empty
- Dependency-aware regression: `tests/Feature/Cicd` **220 passed**;
  Foundation consumers **43 passed / 2 skipped**
- Governance: CI runtime control (strict) · CI/CD enterprise gate · enterprise
  documentation · security compliance · roadmap (strict) — all **GO**
- Release evidence capture + check (ci profile) — exit 0
- Workflow YAML valid (8 jobs) · `bash -n` clean on all CI helpers ·
  `pint --dirty --test` passed · `git diff --check` clean

## 7. Durable rules

Mirrored in `.cursor/rules/119-critical-gate-warning-contract.mdc` (R1–R8):
warning origin must be evidenced before it is normalised · zero unexplained
warnings on required gates · fix at the causal boundary, never suppress · five
distinct resource states, fail closed · diagnostics never obscure the exit code ·
the environment-file state is declared, never populated from the example file in
CI · the Critical Gate does not require frontend build artifacts · coverage must
hold on both runner variants.

## 8. Explicitly left open

`CI-MONITORING-CRITICAL-TOKEN-COVERAGE-1` (critical-gate filter token coverage)
is **not** absorbed here. The root cause was the application bootstrap's
environment read, not the token classifier, so that debt remains open and
separate.
