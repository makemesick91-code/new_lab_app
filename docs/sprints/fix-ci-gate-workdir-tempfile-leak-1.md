# FIX-CI-GATE-WORKDIR-TEMPFILE-LEAK-1

**R-22 closed. The allocation API is the ownership authority; a prefix list is not.**

Base authority `fix-test-tempfile-sibling-leaks-1-go` @ `a75d65ac7e93e183778afc7ef64b6be3d4cb11e6`
(verified peeled, and identical to production `VPS_HEAD` before implementation).

`RUNTIME_APPLICATION_FILES_CHANGED=0` · `RUNTIME_BEHAVIOR_CHANGE=false` ·
`TEST_SEMANTICS_CHANGED=true` · `FULL_SUITE_EXECUTION_COUNT=0`

---

## 1. What R-22 was

`FIX-TEST-TEMPFILE-SIBLING-LEAKS-1` built the temporary-artifact registry behind
`tempArtifactFile()` / `tempArtifactDir()` and closed ten prefixes with it. Two
call sites in `NsfReleaseGateExitPropagationTest` never went through that API at
all — they built a path from the process temporary root and called `mkdir()` on
it:

```php
$workdir = sys_get_temp_dir().'/ctl3b-'.bin2hex(random_bytes(6));   // line 109
$workdir = sys_get_temp_dir().'/fix6-fullsuite-'.bin2hex(random_bytes(6));  // line 322
```

No assertion about the registry's *behaviour* could see them, because they were
never registry allocations. Reproduced on clean base authority before any edit:

| measure | value |
|---|---|
| tests run | 42 |
| tests failed | **0** |
| directory trees stranded | **18** (17 `ctl3b`, 1 `fix6-fullsuite`) |

## 2. The systemic defect underneath it

The readiness audit that found R-22 also proved something more important than
the two call sites. Its own methodology — counting a **known** list of prefixes
before and after a run — reported `447 / 447 / delta 0` while an unknown prefix
leaked +18 somewhere else entirely.

A census can only report on names it was told about in advance. It is therefore
structurally incapable of reporting the one thing that matters: an allocation
nobody registered. That is not a tuning problem, it is the wrong authority.

So this sprint is **not** "add two prefixes to a list". It moves the authority:

| role | mechanism |
|---|---|
| **authoritative** | the canonical allocation API and its registry owner |
| diagnostic only | known-prefix counts, historical accounting |

A new prefix must never need a central registration to earn cleanup.
`tempArtifactDir('brand-new-thing-')` is protected the moment it is written.

## 3. The change

| file | change |
|---|---|
| `tests/Feature/Cicd/NsfReleaseGateExitPropagationTest.php` | both workdirs → `tempArtifactDir()`; 3 call-site lifecycle pins added |
| `tests/Feature/Cicd/CiGateWorkdirOwnershipContractTest.php` | **new** — prefix-independent guard (8 tests) |
| `config/ci_runner.php` | new suite declared in `critical_gate_mandatory_suites` |
| `.cursor/rules/123-test-temporary-file-ownership.mdc` | extended (no parallel rule system) |

The guard keys on the **shape** of an allocation, never its spelling. Its
detector needles are assembled from string fragments so the guard can police its
own file — a guard excluded from the surface it guards guards nothing.

Scope is deliberate. `tempnam()` files are **not** banned: that is the correct
atomic primitive and is already governed by the sibling-leak contract. Banning
it here would reopen call sites that measurement shows are closed.

## 4. Evidence

### Leak closed

| path | before | after |
|---|---:|---:|
| success | 18 stranded | **0** |
| failure (`producerExit: 1`) | leaked | **0** |
| exception after allocation | leaked | **0** |
| 10 repeated invocations | leaked | **0** |
| whole 323-test prior-corrective regression | — | **0 unattributed `/tmp` entries** |

### Mutation controls

| # | mutation | expected | actual |
|---|---|---|---|
| M1 | restore raw `ctl3b` mkdir | FAIL | **FAIL** |
| M2 | restore raw `fix6-fullsuite` mkdir | FAIL | **FAIL** |
| M3 | **never-registered prefix, non-canonical** | FAIL | **FAIL** |
| M4 | **never-registered prefix, through the API** | PASS, delta 0 | **PASS, delta 0** |
| M5 | allocation no longer registers | FAIL | **FAIL** |
| M6 | owned only on the success path | FAIL | **FAIL** (both layers) |
| M7 | drain follows a symlink out of the owned root | FAIL | **FAIL** |
| M8 | detector's fail-closed throw removed | FAIL | **FAIL** |

M3 and M4 together are the whole sprint: an unknown prefix is caught when it
bypasses the API, and welcomed when it uses it.

### The detector fails closed, and the claim is scoped honestly

`preg_*` returns an empty match set on an engine fault, which is
indistinguishable from "this file is clean" — the same fail-open shape the
monitoring correctives kept finding. The detector therefore raises on
`preg_last_error()` rather than absorbing it (M8).

Scope stated plainly: the detector's own patterns are **linear**. Driving
`pcre.backtrack_limit` down to 20 does not fault them (measured), which is a
stronger property than failing closed. The check is defence in depth for a
future pattern edit that is not linear, and its test says so rather than
pretending to exercise a live path.

### A defect in this sprint's own guard, found by mutation

M6 initially reported **PASS**. The mutation put a canonical call and a raw
allocation in the same ternary — `$exit === 0 ? tempArtifactDir(…) : <raw>` —
and the detector skipped the whole expression because it *contained*
`tempArtifactDir(`. Containing an owner is not the same as having one, and the
path it exempted was the failing one, which is exactly the path a leak guard
exists for.

Fixed by stripping canonical calls from the expression *before* asking whether
it still reaches for the temporary root, and pinned permanently by
`it('detects a raw allocation hidden beside a canonical one in the same expression')`.

### Historical orphans

Measured, preserved, never deleted to make a metric look clean:

| family | pre-existing |
|---|---:|
| `ctl3b` | 104 |
| `fix6-fullsuite` | 7 |
| **total** | **111** |

`HISTORICAL_R22_ORPHANS_DELETED_BY_SPRINT=0`. The 18 trees this sprint's own
reproduction created are its own residue and were removed by exact path — never
by a glob sweep. Correctness is a zero task-created delta, not a clean census.

The other families the previous sprint closed (`ctl3c-` 40, `ctl3a-bin-` 36,
`ctl3a-home-` 51, `ctl3d-bin-` 11, `ci-bare-host-` 16, `rdrs1-` 124,
`dms-pdf-` 55) were re-measured and did **not** grow — those fixes hold and were
not reopened.

### Cicd temporary-allocator inventory

Every raw temporary-**directory** allocation in `tests/Feature/Cicd`:

| call site | classification |
|---|---|
| `NsfReleaseGateExitPropagationTest` ×2 | **REAL_LEAK → fixed, canonical** |
| `CiClassifierBaseAuthorityTest` | EXPLICIT_SAFE — own `$GLOBALS` registry drained in `afterEach`, 0 standing orphans |
| all others | CANONICAL_OWNED or NOT_TEMP_RESOURCE |

`UNKNOWN_CICD_TEMP_ALLOCATORS=0`.

## 5. CI selection

The guard is selected by the critical filter in **both** runner variants (the
filter string appears twice in the workflow and selects all 8 tests), and is
declared in `critical_gate_mandatory_suites` so the scanner fails the gate if a
future filter edit ever drops it. A control that exists but is never selected is
not a control.

## 6. Full Suite

**Not authorised.** The previous one-off authorisation was consumed by run
`32700184849`, which FAILED. `FULL_SUITE_EXECUTION_COUNT=0`.

This sprint carries its own corrective GO tag. It does **not** close the
stabilization programme, which remains
`NO_GO_PENDING_NEW_AUTHORIZED_FULL_SUITE`. The next step is to repeat the Final
Pre-Full-Suite Readiness Check — not to jump to a Full Suite.
