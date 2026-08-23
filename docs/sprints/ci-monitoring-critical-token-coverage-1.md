# CI-MONITORING-CRITICAL-TOKEN-COVERAGE-1 — mandatory Monitoring suites are selected by an explicit registry

Branch `feature/ci-monitoring-critical-token-coverage-1`
Base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `5528ba97`
(= `cicd-critical-gate-file-get-contents-warn-1-go`, peeled; tag object `792d74ad`)

Rule mirror `.cursor/rules/120-critical-gate-mandatory-suite-coverage.mdc`.

`ROOT_CAUSE_CLASSIFICATION = CRITICAL-SELECTION COVERAGE-BY-COINCIDENCE DEFECT`
`CODE_CHANGE_REQUIRED = true`

---

## 1. The selector, as it actually works

```
classify (always GitHub-hosted)
   └─ resolve-gates.sh → run_critical_tests   (only `docs_only` may be false)
        └─ NSF-R011 Critical Test Gate, one of two mutually exclusive variants
             └─ php artisan test --filter='<fixed allowlist of ~34 tokens>'
```

Selection is **not** driven by the changed-file set. The classifier decides only
*whether* the gate runs; *what* it runs is a fixed `--filter` allowlist that is
identical in both variants.

`--filter` is a case-insensitive regex matched against the full test name. Pest
builds that name from the file path:

```
tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php
  → P\Tests\Unit\Services\Monitoring\MonitoringLogSourceResilienceTest::__pest_evaluable_<description>
```

Two consequences matter. The namespace carries the **directory path**, and the
suffix carries the **test description** — so a bare word token can select a file
for reasons that have nothing to do with its contract.

## 2. What was wrong

`config/ci_runner.critical_gate_required_filters` (CICD-CTRL-3D) already existed
and is enforced against both variants. It answers **"is this token present?"**.
That question cannot see the failure that matters: a suite whose name no token
matches is simply absent, and absence is what green looks like.

Measured with the real runner at the base SHA:

| Filter | Tests | Files | `MonitoringLogSourceResilienceTest` |
|---|---:|---:|---|
| BASE (as shipped) | 2252 | 130 | **0 selected** |

All eight suites in `tests/Unit/Services/Monitoring` ran **because their
filenames begin with `PilotPerformanceSnapshot`**. The one that does not carry
that prefix — the suite pinning that the monitor reads where the application
actually writes, and fails closed on a missing or unreadable source — matched no
token in either variant and ran only in the Full Suite, which is currently
deferred under the global temporary policy.

`RENAME_GAP_BEFORE = true`. Renaming any sibling out of the `PilotPerformanceSnapshot`
prefix would have removed that sibling's coverage exactly as silently.

`CRITICAL_SELECTION_FALSE_GREEN_BEFORE = true`. Nothing compared the required
contract against the selector's actual output, so the gate could be green while
a mandatory contract never executed — which is the state that shipped.

## 3. Why "add a token" was rejected as the fix

Measured, not assumed:

| Candidate | Tests | Files | Verdict |
|---|---:|---:|---|
| `+ Monitoring` (bare) | 2346 | 146 | **+15 unrelated files** — matched via the word inside test *descriptions*. Over-selection. |
| `+ MonitoringLogSource` (narrow) | 2276 | 131 | Bounded (+1 file), but coverage still rests on what the file is called. |

Neither closes the defect *class*. A token answers "is the word there", which is
the same question that already failed.

## 4. The fix

Extends the **existing** authority — `config/ci_runner.php` plus
`SelfHostedRunnerScanner`. No second registry was created.

- **`config/ci_runner.critical_gate_mandatory_suites`** — the test FILES that
  must actually run. Ten entries, each annotated with the contract it carries.
- **`SelfHostedRunnerScanner::criticalGateSuiteCoveragePosture()`** — derives the
  name PHPUnit filters against from each declared path and requires a token in
  **every** critical variant to match it. Fails on: no token matches, the file
  does not exist, only one variant covers it, the registry is empty, or the
  workflow is unreadable.
- **`SelfHostedRunnerGovernanceService`** — surfaces it as
  `CICDCTRL3-CRITICAL-SUITE-COVERAGE`; a break makes the decision `FAIL`.
- The narrow `MonitoringLogSource` token is added to **both** variants, so the
  declared suite is selected rather than merely required.

The match is a case-insensitive **literal** substring test. PHPUnit treats
`--filter` as a regex, so a literal hit guarantees a real selection, while a
token that could only match through regex syntax is reported as unselected. The
asymmetry is deliberate: the check errs toward FAIL, never toward a coverage
claim it cannot substantiate.

`NEW_CRITICAL_AUTHORITY = config/ci_runner.critical_gate_mandatory_suites`,
enforced by `criticalGateSuiteCoveragePosture()`.
`RENAME_GAP_AFTER = false` — a rename either keeps the suite selected or fails
the gate. It can no longer vanish.

## 5. Selection matrix

All ten mandatory suites, verified against the real workflow (`matched_by` shows
the token that selects it in variant 0 / variant 1):

| Mandatory contract | Suite | Direct before | Direct after | Selecting token |
|---|---|---|---|---|
| Log-source resolution, fail-closed | `MonitoringLogSourceResilienceTest` | **NO** | yes | `MonitoringLogSource` |
| Log-source (snapshot side) | `PilotPerformanceSnapshotLogSourceTest` | yes (coincidence) | yes | `PilotPerformanceSnapshot` |
| Timestamp faithfulness | `PilotPerformanceSnapshotTimestampRolloverTest` | yes (coincidence) | yes | `PilotPerformanceSnapshot` |
| Physical scan coverage | `PilotPerformanceSnapshotCoverageAnchorTest` | yes (coincidence) | yes | `PilotPerformanceSnapshot` |
| Undated severity ladder | `PilotPerformanceSnapshotUndatedSeverityTest` | yes (coincidence) | yes | `PilotPerformanceSnapshot` |
| Log analyzer | `PilotPerformanceSnapshotLogAnalyzerTest` | yes (coincidence) | yes | `PilotPerformanceSnapshot` |
| Classifier | `PilotPerformanceSnapshotClassifierTest` | yes (coincidence) | yes | `PilotPerformanceSnapshot` |
| Resource section | `PilotPerformanceSnapshotResourceSectionTest` | yes (coincidence) | yes | `PilotPerformanceSnapshot` |
| Production entry point | `PilotPerformanceSnapshotCommandTest` | yes (coincidence) | yes | `PilotPerformanceSnapshot` |
| Restore-drill timestamps | `RestoreDrillTimestampFaithfulnessTest` | yes | yes | `RestoreDrill` |

"Coincidence" means the suite was selected only because of its filename prefix,
with nothing asserting that it must be. After this sprint every row is selected
because the registry declares it, and drift fails closed.

**Deliberately NOT promoted.** `tests/Feature/Foundation/FoundationMonitoring*`
and the `Sprint28/29/42/43` monitoring suites cover the read-only MON-1 console
and historical planning documents rather than the monitor's own verdict. They
are not in the §33 contract list and are not promoted here. This is a recorded
decision, not an omission — promoting them is a governance choice for a sprint
that argues it, not a reflex.

## 6. Selection cost

| | Before | After | Delta |
|---|---:|---:|---:|
| Tests selected | 2252 | 2290 | **+38** |
| Files selected | 130 | 132 | **+2** |
| Files removed | — | — | **0** |

Both added files are accounted for: the mandatory suite that was missing (24
tests) and this sprint's own contract suite (14 tests). `OVER_SELECTION_DETECTED
= false`.

## 7. Negative and mutation controls

| Mutation | Expected | Actual |
|---|---|---|
| Workflow reverted to its pre-fix filter | FAIL on both variants | FAIL, both variants named |
| Mandatory suite renamed beyond every token | FAIL | FAIL — `does not select mandatory suite` |
| One variant drops the token, the other keeps it | FAIL | FAIL — `filter #1 does not select…` |
| Declared file does not exist | FAIL | FAIL — `the registry is stale` |
| Registry empty | FAIL | FAIL — `no mandatory critical suite is declared` |
| Workflow unreadable | FAIL closed | FAIL, `exists=false` |
| Duplicate entry | collapse, never pad, never mask | 3 entries → 1 declared; the real gap still surfaced |
| Unrelated suite vs the new token | not promoted | not matched |
| Governance report with coverage broken | decision FAIL | `CICDCTRL3-CRITICAL-SUITE-COVERAGE = failed`, decision FAIL |

## 8. Preserved

- Warning contract (CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1): expected
  warning count stays 0, no allowlist, no suppression, test exit status keeps
  strict precedence.
- `KNOWN_MONITORING_FAILURES = 0` (rule 112).
- Restore-drill timestamp authority (rule 117) untouched.
- Clinical foundations untouched: consent, odontogram, explicit
  *Selesai Pemeriksaan*, cashier transitions, Legacy RME, ClinicalClock, RM
  numbering.
- `FULL_SUITE_EXECUTION_COUNT = 0`, `DEFERRED_BY_GLOBAL_TEMPORARY_POLICY`.

## 9. Residual — recorded, not fixed

`RestoreDrillEvidenceService` casts a failed read to string and then reports it
as invalid JSON. It fails closed, so it is not a correctness risk. Tracked as
`RESTORE-DRILL-EVIDENCE-READ-STATE-1` and deliberately not absorbed here.
