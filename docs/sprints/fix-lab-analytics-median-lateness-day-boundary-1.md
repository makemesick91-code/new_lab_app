# FIX-LAB-ANALYTICS-MEDIAN-LATENESS-DAY-BOUNDARY-1

**Status:** corrective sprint — test determinism.
**Base authority:** `fix-pdf-tempfile-leak-1-go` → `2c267002b4af4e39c8e100608f88b2fb75d55118`.
**Full Suite:** `NOT_AUTHORIZED_AFTER_PREVIOUS_FAILURE`, `FULL_SUITE_EXECUTION_COUNT=0`.
**Programme closure:** unchanged — `NO_GO_PENDING_NEW_AUTHORIZED_FULL_SUITE`.

---

## 1. The defect

`tests/Feature/LabWorkflow/LabOperationalAnalyticsMetricTest.php` asserted:

```php
expect($sla['median_lateness_days'])->toBeGreaterThan(1.0);
```

against this fixture:

```php
$late = opV2Order(['due_date' => now()->subDays(2)->toDateString()]);  // day granularity
opLog($late, LabWorkflowState::DELIVERED, now());                      // instant granularity
```

The runtime (`LabOperationalAnalyticsService::slaCompliance()`) measures lateness
as an **elapsed duration from the end of the due day**:

```php
$due = Carbon::parse($case->due_date)->endOfDay();
$delivered = Carbon::parse($case->delivered_at);
$lateness[] = round($due->diffInMinutes($delivered) / 1440, 2);
```

So the fixture's actual lateness was `1 + <time of day>` days — `1.00` just after
midnight, `2.00` just before it. The expected value was therefore decided by what
hour CI happened to start. It failed at `00:02:59Z` with `actual = 1.00` and
passed on a rerun of the same commit hours later.

**Classification: test fixture defect.** The metric is deterministic given its
inputs; only the inputs were not. `RUNTIME_APPLICATION_FILES_CHANGED = 0`.

## 2. Failure window — reproduced, not estimated

Pinned by binary search under a controlled clock. No real midnight was waited on,
and no CI rerun was used as evidence.

| Frozen instant (app tz = UTC) | `median_lateness_days` | `> 1.0` |
| --- | ---: | --- |
| `23:55:00` (previous day) | 2.0 | PASS |
| `23:59:59` (previous day) | 2.0 | PASS |
| `00:00:00` | 1.0 | **FAIL** |
| `00:02:59` ← reported CI failure | 1.0 | **FAIL** |
| `00:03:00` | 1.0 | **FAIL** |
| `00:07:10` | 1.0 | **FAIL** |
| `00:07:11` ← last failing instant | 1.0 | **FAIL** |
| `00:07:12` ← first passing instant | 1.01 | PASS |
| `00:15:00` | 1.01 | PASS |
| `12:00:00` | 1.5 | PASS |

**Window = `[00:00:00, 00:07:11]`** — 7m12s of every day, ~0.5% of runs. The prior
"~7 minutes" estimate was accurate; this pins it to the second.

The edge is arithmetic, not arbitrary. Rounding to 2 dp needs
`raw ≥ 1.005` → `≥ 1447.2` minutes → `≥ 432s` past midnight → `00:07:12`.

## 3. Time authority — established from the code, not from CI's clock

Lab operational analytics has **no domain clock**:

- `resolvePeriod()` uses `today()`;
- `slaCompliance()` uses `Carbon::parse(...)->endOfDay()`;
- both resolve in the application timezone, and `config/app.php` hardcodes
  `'timezone' => 'UTC'`;
- Graphify traversal of the service found no `ClinicalClock` edge in the
  `LabOrder` module.

`LAB_ANALYTICS_TIME_AUTHORITY = APPLICATION_TIMEZONE (UTC)`.

`ClinicalClock` (Asia/Makassar) was deliberately **not** borrowed: it is the
clinical-date authority, and nothing in this module's dates is clinical. That
decision is pinned behaviourally — a boundary case frozen at `16:00Z`
(Asia/Makassar midnight) asserts the reporting window still ends on the UTC date.

## 4. The false fix, rejected with evidence

Widening `> 1.0` to `>= 1.0` would have gone green without touching the defect.
That it is a false fix was **proven**, not asserted: with the runtime mutated to
return a mean instead of a median, the weakened assertion passes **12/12** while
the new boundary suite fails **10/14**.

The repaired assertion is exact — `toBe(2.0)` — because the fixture establishes
the value arithmetically rather than by threshold.

## 5. The fix

**`tests/Pest.php`** — canonical reference-clock helpers `pinTestClock()` /
`freeTestClock()`, interpreted in the application timezone. Small and shared on
purpose; no system-wide temporal framework was built for one flaky test.

**`LabOperationalAnalyticsMetricTest`** — the SLA case is pinned *at the instant
it used to fail* (`2026-05-15 00:02:59`), and the late delivery is anchored to its
own **due day** rather than to `now()`:

```php
$dueDay = now()->copy()->subDays(3)->startOfDay();
$late = opV2Order(['due_date' => $dueDay->toDateString()]);
opLog($late, DELIVERED, $dueDay->copy()->addDays(3)->startOfDay());   // exactly 2.00 days late
```

Both were done. Pinning alone removes host dependence; anchoring makes the
expected value intrinsic to the fixture rather than to the chosen instant.

**`LabOperationalAnalyticsDayBoundaryTest`** (new, 14 tests) — an invariance
regression: the same completed records must yield the same SLA block at every
instant across the boundary, including both edges of the reproduced window and
the reported failure instant. It also pins median semantics (1/2/4 → `2.0`, the
middle value, not the `2.33` mean), the even-count convention (1/4 → `2.5`), the
"due day itself is never late" contract, the timezone authority, and that no
pinned clock is left behind.

## 6. Sibling of the same class — found and fixed

The QC first-pass test placed its first attempt 10 minutes before a live `now()`
inside a **calendar-month** window:

```php
opLog($reworked, QC_FAILED, now()->subMinutes(10));
```

During the first 10 minutes of every 1st, those 10 minutes fell into the previous
month, the failed attempt dropped out of period, and the test silently became a
2/2 first-pass assertion. Reproduced at `2026-06-01 00:05:00`:
`first_pass=2, rework=0` — the assertion fails. Same causal defect, one-line blast
radius, fixed here by pinning.

## 7. Siblings audited and cleared

| Test | Clock pattern | Verdict |
| --- | --- | --- |
| orders received | `order_date => now()` | SAFE — always in period |
| throughput | `now()`, `now()->subDay()`, 7d window | SAFE — both inside window |
| SLA excludes no-due | `now()` | SAFE |
| internal vs external | filtered on `analyzed_at => now()` | SAFE |
| external turnaround | filtered on `returned_at => now()`; endpoints move together | SAFE |
| technician KPI | assignments not period-filtered; endpoints move together | SAFE |
| branch scope | no clock | SAFE |
| **SLA median lateness** | deadline by date, delivery by clock | **SAME CLASS — fixed** |
| **QC first pass** | `subMinutes(10)` across a month window | **SAME CLASS — fixed** |

Classified from the repository's actual filter columns, not assumed.

## 8. Mutation controls

| Mutation | Expected | Actual |
| --- | --- | --- |
| Restore the live-clock fixture | metric test FAILS | 1 failed / 11 passed ✓ |
| Un-anchor delivery from the due day | boundary suite FAILS | 12 failed / 2 passed ✓ |
| App timezone → `Asia/Makassar` | authority test FAILS | 1 failed / 13 passed ✓ |
| Runtime median → mean | boundary suite FAILS | 10 failed / 4 passed ✓ |
| Weakened `>= 1.0` **and** wrong metric | weak passes, boundary catches | 12 passed / **10 failed** ✓ |
| Remove the `afterEach` clock release | leak guard fails | **NOT DETECTED** — see below |

**Honest negative.** The last control did *not* fail. Laravel's
`InteractsWithTestCaseLifecycle::tearDownTheTestEnvironment()` already calls
`Carbon::setTestNow()` (framework lines 155–160), so the framework guarantees the
reset and the guard cannot observe the explicit `afterEach` being removed. The
explicit release is kept anyway — it is the documented pairing for
`pinTestClock()` and it survives a framework change — but it is redundancy, not
the thing the guard proves. The guard's real value is catching a pinned clock
that escapes the test lifecycle altogether.

Mutation residue: **0** — every mutation was reverted and verified by `git diff`.

## 9. Host-clock independence

The whole repaired suite was re-run with a global clock pinned at each hostile
instant. Control runs on the base authority are shown beside it.

| Frozen instant | Base | Fixed |
| --- | --- | --- |
| `2026-05-15 00:00:00` | — | 12 passed |
| `2026-05-15 00:02:59` (CI failure) | **1 failed** | 12 passed |
| `2026-06-01 00:05:00` (month start) | **2 failed** | 12 passed |
| `2026-03-01 00:00:30` (month start) | — | 12 passed |
| `2026-12-31 23:59:59` (year end) | — | 12 passed |
| `2026-05-15 12:00:00` (midday) | 12 passed | 12 passed |

The midday row is the point: it is the "convenient midday fixture" that hid the
defect for the life of the test. `HOST_WALL_CLOCK_DEPENDENCY = false`.

## 10. CI coverage — enumerated, not inferred

The classifier maps `*/Lab*` → `run_lab_tests`, and the Selective Module Gate runs
`php artisan test --filter='Lab'`. Enumerated with `--list-tests`:

```
TOTAL_SELECTED=626
LabOperationalAnalyticsMetricTest=12
LabOperationalAnalyticsDayBoundaryTest=14
```

The new suite is **not** added to `config/ci_runner.php`
`critical_gate_mandatory_suites`. That registry is scoped by its own doctrine to
the monitor-truthfulness contract, and the defect surfaced in Selective — Critical
is not broadened because Selective caught it.

## 11. Deliberately not done

- Runtime not changed — the metric was already correct.
- `> 1.0` not weakened to `>= 1.0`; rounding not adjusted; the assertion not skipped.
- No test marked flaky, no timezone changed to hide it, no rerun used as proof.
- No universal clock framework built; two helpers, at the existing helper site.
- Tempfile sibling leaks (`ffcache-`, `ctl3a-`, `lrme-poppler-`, `leg/bad`) left
  to FIX-TEST-TEMPFILE-SIBLING-LEAKS-1 — out of scope, not touched.
- No Full Suite executed.

## 12. Durable rules

Recorded in `.cursor/rules/124-time-dependent-test-determinism.mdc` and CLAUDE.md:
pin an explicit reference clock; anchor both endpoints of a duration; CI's UTC
clock is not business time; cover the boundary that actually broke; never widen a
threshold to stabilise a clock flake; release pinned clock state; reproduce before
fixing. `now()` in tests is **not** banned — a fixture whose endpoints move
together is duration-stable.
