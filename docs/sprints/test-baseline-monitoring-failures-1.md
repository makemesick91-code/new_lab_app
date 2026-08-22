# TEST-BASELINE-MONITORING-FAILURES-1 — Monitoring baseline restored to zero known failures

Branch `feature/test-baseline-monitoring-failures-1`
Base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `6b56ad9ad84802bfb1895dbb76f8223ea954d1c8`
(peeled `legacy-odontogram-native-reference-cutoff-1-go`; VPS HEAD identical at sprint start)

Rule mirror `.cursor/rules/112-monitoring-baseline-zero-known-failures.mdc`.

## Outcome

Two Monitoring failures had been carried as "pre-existing". Both are closed. Neither
was a monitor defect: the production monitor was reporting the truth about the
machine it was running on, and the *tests* were asserting an aggregate that included
that machine. A third test in the same family was found to be passing for the wrong
reason and is now a genuine regression detector.

```
MONITORING_FAILURES  base 2  ->  0
KNOWN_MONITORING_FAILURES = 0
```

## The two failures

Reproduced on a pristine detached worktree at the base SHA, no local modifications:
`2 failed, 3 skipped, 107 passed`.

| | Failure 1 | Failure 2 |
|---|---|---|
| Test | `tests/Unit/Services/Monitoring/PilotPerformanceSnapshotLogAnalyzerTest.php:162` | `tests/Unit/Console/PilotPerformanceSnapshotCommandTest.php:227` |
| Name | keeps overall ok for historical-only grouped stack traces via service | returns zero fail-on-watch when only historical logs would have previously caused watch |
| Signature | `-'OK' +'WATCH'` | `-'OK' +'WATCH'` |
| Classification | D — environment dependency | D — environment dependency |

## Root cause

`PilotPerformanceSnapshotService::collect()` returns

```
overall_status = Classifier::worst(app, database, resources, http, logs)
```

Both tests pass `skip_db` and `skip_http`, deliberately neutralising the two
environment-dependent sections, and then assert `overall_status === 'OK'`. There was
no equivalent for `resources`, which reads the live host disk:

```php
$diskFreeBytes = @disk_free_space(storage_path());
$status = Classifier::classifyDiskFreeGb($diskFreeGb);   // <10 FIX, <20 WATCH, >=20 OK
```

Instrumenting `collect()` on the failing host showed the log analyser was innocent:

```
app=OK  database=OK  http=OK  logs=OK  resources=WATCH  ->  OVERALL=WATCH
```

`disk_free_gb` was **15.27** — under the 20 GB WATCH boundary. Causation was then
proven by changing nothing but the filesystem `storage_path()` points at:

| storage filesystem | disk_free_gb | resources | overall_status | exit | test |
|---|---|---|---|---|---|
| `/home` (81% used) | 15.27 | WATCH | WATCH | 1 | FAILS |
| `/` | 55.52 | OK | OK | 0 | PASSES |

Same commit, same inputs. The tests pass on any host with ≥20 GB free and fail below
it. CI hosts are spacious, so **no CI gate could ever have caught this** — and
`PilotPerformanceSnapshot` was not in the critical-gate filter at all.

## The third test — passing for the wrong reason

`PilotPerformanceSnapshotCommandTest.php:248`, *returns fail-on-watch exit code 1 for
fresh log watch status*, asserts `exitCodeForStatus($snapshot['overall_status']) === 1`.
On this host `resources=WATCH` supplied that 1 regardless of the log verdict, so the
assertion could not fail for its stated reason. Below 10 GB it would have gone the
other way and failed with exit 3 (FIX). It was in scope for the same root cause.

## Why the obvious fix was rejected

The tempting repair is to leave production alone and derive the expectation from the
observed sections — `worst(expectedLogStatus, app, database, resources, http)`. A
mutation test disproves it. Dropping `$sections['logs']['status']` from the `worst()`
call — a severe regression where no log verdict could ever reach the aggregate or the
exit code — was measured on the low-disk host:

| Approach | mutation: `logs` dropped from `worst()` |
|---|---|
| Derive-from-observed | **21 passed — undetected** |
| This sprint's fix | **1 failed — detected** |

When the environment floor is already WATCH, a recomputed expectation absorbs the
missing term and the assertion becomes a tautology precisely on the hosts where the
coupling bites. That is weakening coverage to obtain green, which governance forbids.

## The fix

Make the one genuinely non-deterministic input substitutable, and change nothing
about what it means.

- **New** `app/Services/Monitoring/PilotPerformanceSnapshotDiskProbe.php` — a
  pass-through to `disk_free_space()`, returning `null` where it returned `false`.
- **`PilotPerformanceSnapshotService`** — one added constructor argument and one
  changed line in `collectResourceHealth()`. `storage_path()`, the `disk_path`
  metric, `readMemInfo()`, `sys_getloadavg()` and every threshold are untouched.
- **`tests/Pest.php`** — `pilotSnapshotDiskProbe(?float $freeGb)`.
- The three tests pin the disk at 100 GB and keep their strongest assertions
  verbatim: `overall_status === 'OK'`, `exitCodeForStatus(...) === 1`.

Production is byte-identical: the command resolves the service from the container
with no binding, so it gets the real probe. `resources` remains an unconditional
argument to `worst()` — there is no skip branch and therefore no path that can emit
`STATUS_OK` for resources while the disk is at FIX. An unreadable disk still maps to
`null` → WATCH plus a warning.

A `collect()` option or a `--no-resources` flag was rejected for exactly that reason:
it would have put a lie-generating branch inside the monitor, one line from an
operator flag.

## Net-new coverage

`tests/Unit/Services/Monitoring/PilotPerformanceSnapshotResourceSectionTest.php` (6).
The disk monitor previously had **zero** test coverage because the syscall could not
be stubbed. It now pins the production escalation — 5 GB → FIX → exit 3, 15 GB →
WATCH → exit 1, 100 GB → OK → exit 0, unreadable → WATCH + warning — plus the wiring:
the container resolves the real probe, and no file outside the Monitoring service may
name the probe (which catches an environment-guarded binding a resolve-time assertion
in `APP_ENV=testing` could not see).

`PilotPerformanceSnapshot` was added to the NSF-R011 critical-gate filter on both
runner variants, so the fix and the disk alarm now have automated protection. Before
this sprint neither was executed by any required gate.

## Verification

| Gate | Result |
|---|---|
| Negative control (pristine base, this host) | 2 failed, 3 skipped, 107 passed |
| `tests/Unit/Services/Monitoring` + `tests/Unit/Console` | **115 passed**, 3 skipped, 597 assertions, 0 failed |
| `--filter=Monitoring` | **91 passed**, 509 assertions, 0 failed (base: 1 failed / 85 passed) |
| Mutation control (logs dropped from `worst()`) | **1 failed** — coverage proven live |
| `Cicd\|Devflow\|FullSuiteBaseline\|FoundationMonitoring\|MonitoringObservability\|HealthCheck` | 420 passed |
| `Cicd\|Devflow\|DedicatedSelfHostedRunner` (workflow edited) | 289 passed |
| PostgreSQL **16.14** (pinned container, matches production) | Unit 36 passed; Feature Monitoring/HealthCheck 40 passed, 133 tables migrated |
| Security review (adversarial) | CRITICAL 0, HIGH 0; 3 MEDIUM resolved |
| `pint --dirty --test`, `git diff --check`, `route:list` | clean |
| DEVFLOW `manifest-check` / `scope-audit` / `devflow-check` / `shared-service-audit` / `ci-runtime-control-check` | GO |

`sprint:test-plan` fail-closes to FULL REQUIRED SUITE — no regression-matrix category
matches `app/Services/Monitoring/**`. That escalation was deliberately **not**
softened by adding a category: weakening a fail-closed control to avoid an escalation
is the opposite of this sprint's point. Full Suite remains deferred under
`GLOBAL_TEMPORARY_FULL_SUITE_POLICY`; the escalation is recorded, not executed.

```
FULL_SUITE_EXECUTION_COUNT=0
FULL_SUITE_STATUS=DEFERRED_BY_GLOBAL_TEMPORARY_POLICY
```

## Residual findings (documented, not fixed)

A repo-wide scan traced every production site that reads live host capacity to its
asserting test. Beyond the family fixed here, three real couplings remain; all are
inert today, none is failing, and each is in a different module. They are tabulated
with their current margins in rule 112 §R6 so they are not rediscovered as new.
