# FIX-PDF-TEMPFILE-LEAK-1 — PDF temp-file ownership contract

**Status:** GO candidate
**Base authority:** `9576af9f3cb12357fea192fde48f7a83b19643ee` (`fix-receipt-pdf-text-contiguity-1-go`)
**Classification:** `TEST_INFRASTRUCTURE_RESOURCE_LIFECYCLE_DEFECT`
**Runtime behaviour change:** none

---

## 1. The defect

`tests/Pest.php:1032`, at the base authority:

```php
function pdfWithTempFile(string $bytes, callable $callback): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'dms-pdf-').'.pdf';
    file_put_contents($path, $bytes);

    try {
        return $callback($path);
    } finally {
        @unlink($path);
    }
}
```

`tempnam()` does not merely *reserve* a name — it **creates the file**. The call
above therefore produced two filesystem artifacts:

| Path | Created by | Cleaned by `finally` |
|---|---|---|
| `/tmp/dms-pdf-ABC123` | `tempnam()` | **no — leaked** |
| `/tmp/dms-pdf-ABC123.pdf` | `file_put_contents()` | yes |

The helper is called by *every* PDF assertion in the suite, so the leak was one
zero-byte orphan per assertion rather than one per run.

## 2. Reproduction — measured, not inferred

Baseline census on the clean base authority:

| Measure | Value |
|---|---|
| `dms-pdf-*` artifacts in `/tmp` | 55 |
| …zero-byte | 55 (100%) |
| …carrying the `.pdf` suffix | 0 |

Zero suffixed survivors is the proof: the derived file was **always** cleaned and
the allocation was **never** cleaned. The orphans are the `tempnam()` allocations.

Running the exact base-authority helper once per path, measuring the
`dms-pdf-*` set difference rather than a global `/tmp` count:

| Path | Task delta (before fix) | Leaked artifact |
|---|---|---|
| callback succeeds | **1** | base allocation, 0 bytes, no suffix |
| callback throws | **1** | base allocation, 0 bytes, no suffix |

## 3. The fix

The allocation **is** the document path. The second artifact is removed rather
than a second `unlink` added, so there is no path left to forget:

```php
$path = tempnam(sys_get_temp_dir(), 'dms-pdf-');
file_put_contents($path, $bytes);

try {
    return $callback($path);
} finally {
    @unlink($path);
}
```

### Why dropping the suffix is safe

`PDF_EXTENSION_REQUIRED = false`, verified empirically rather than assumed.
Poppler dispatches on the file header, not the filename. Against the same
bytes at a path with and without a `.pdf` suffix (poppler 26.01.0):

| Tool | No extension | `.pdf` extension |
|---|---|---|
| `pdftotext -layout` | `TEMPFILECONTRACT` | `TEMPFILECONTRACT` |
| `pdfinfo` | `Pages: 1` | `Pages: 1` |

The only two callers, `pdfExtractText` and `pdfPageCount`, pass the path
straight to those binaries.

Two properties improve as a side effect:

- the file keeps the `0600` mode `tempnam()` assigns, instead of the
  umask-derived mode `file_put_contents()` gave the derived path;
- the TOCTOU window closes. The derived name was never atomically reserved, so
  another process could in principle have created it between derivation and
  write. The allocation cannot be raced.

### Approaches rejected

| Approach | Why not |
|---|---|
| `rm -f /tmp/dms-pdf-*` after each test | Hides continued leakage and destroys pre-existing evidence. |
| `uniqid()` instead of `tempnam()` | Discards atomic collision-safe allocation. |
| A fixed filename | Collision and race risk between concurrent test processes. |
| Keep `.pdf`, unlink both paths | Two owned paths is the defect shape; one is strictly safer. |

## 4. Verification

| Path | Task temp delta after fix |
|---|---|
| callback succeeds | **0** |
| callback throws (exception preserved) | **0** |
| PDF unreadable — Poppler fails, page count 0 | **0** |
| 10 repeated invocations | **0** |

### Mutation controls

| Mutation | Expected | Actual |
|---|---|---|
| Restore the old `.'.pdf'` derivation | FAIL | **5 failed**, 1 passed |
| Clean on success only (no `finally`) | FAIL | **1 failed**, 5 passed — the exception test alone |
| Remove artifact cleanup entirely | FAIL | **4 failed**, 2 passed |

Mutation 2 is the sharpest signal: it reddens *exactly* the exception-path test
and leaves the success path green, so the two cleanup paths are independently
pinned.

> **Note on mutation 3.** In the corrected single-owner design the "derived PDF
> cleanup" and the "allocation cleanup" are the *same* `unlink` — that is the
> point of the fix. Mutation 3 therefore collapses into "remove the only
> cleanup" rather than remaining a distinct third case. Recorded as a collapse
> rather than presented as two independent controls.

### Regression

`73 passed / 400 assertions`, zero failures, across the new guard,
`CriticalGateSuiteCoverageTest`, `FullSuiteBaselineContractTest`,
`RmeReceiptOnePageTest`, `MedicalRecordPrintOdontogramSeparationTest` and
`CriticalGateWarningContractTest`. The FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1
wrap-tolerant reading contract is preserved unchanged.

## 5. The guard

`tests/Feature/Cicd/PdfTempFileLifecycleContractTest.php`, declared in
`config/ci_runner.php` → `critical_gate_mandatory_suites`, so
`CriticalGateSuiteCoverageTest` enforces its selection instead of leaving it to
a filter token that a future edit could silently drop. All six of its tests were
confirmed selected by running the critical gate's exact filter through
`pest --list-tests`; that filter is byte-identical in both runner variants.

**Behavioural, not textual.** The defect is filesystem lifecycle, so the guard
interrogates the filesystem. Its primary assertions are concurrency-immune: they
name the exact path handed to the callback and the exact sibling the old
derivation would have stranded beside it, in both directions. A global temp
directory count is *not* authoritative on a shared machine — any other process
may add or remove files in the same window — so the owned-set delta is asserted
only as a secondary net.

The strongest single assertion observes from *inside* the callback that exactly
one artifact is live and that it **is** the path handed out. Under the old code
two were live.

## 6. Scope boundary — sibling defects measured, not refactored

The same `tempnam(...).SUFFIX` shape exists elsewhere, and a separate
never-cleaned leak exists at `ctl3a-`. Census at the base authority:

| Prefix | Call site | Shape | Orphans |
|---|---|---|---|
| `dms-pdf-` | `tests/Pest.php` | derived `.pdf` | 55 — **fixed here** |
| `ffcache` | `FeatureFlagRuntimeOverrideTest` | derived `.php` | 77 |
| `ctl3a-` | `SelfHostedHealthFailClosedTest` | no cleanup at all | 87 |
| `lrme-poppler-` | `LegacyRmePopplerIntegrationTest` | derived `.pdf` | 20 |
| `leg` / `bad` | `LegacyPatientBatchImportTest` | derived `.csv` | 4 |

These are **out of scope by design**. This sprint fixes the shared helper every
PDF assertion routes through; opportunistically refactoring every temp helper
would widen a lifecycle fix into a test-suite rewrite. They are recorded here as
a follow-up candidate.

This is also why the guard is not a repo-wide textual ban on the shape: such a
guard would fail on those unfixed siblings, and allowlisting them would bless a
known leak.

## 7. Pre-existing orphans

The 55 pre-existing `dms-pdf-*` orphans were **measured, not deleted**. Deleting
them would have manufactured a zero count without proving the helper stopped
leaking. The contract this sprint asserts is that the helper's own delta is
zero, not that the temp directory is empty.

## 8. Full Suite

**Not authorised.** `FULL_SUITE_EXECUTION_COUNT=0`. The previous authorisation
was consumed by run `32700184849`, which failed; that evidence stands unaltered.
A new explicit user authorisation is required before any further complete-suite
execution. This sprint carries its own GO tag and does **not** close the
stabilization programme, which remains
`NO_GO_PENDING_NEW_AUTHORIZED_FULL_SUITE`.

---

## 9. Discovered during this sprint — a pre-existing SLA flake (NOT fixed here)

The Selective Module Gate reddened on run `32790351218`. The cause is **not this
sprint's change** and is recorded here so it is not rediscovered from scratch.

**Failing test:** `tests/Feature/LabWorkflow/LabOperationalAnalyticsMetricTest.php:129`

```php
->and($sla['median_lateness_days'])->toBeGreaterThan(1.0);
```

**Reported as:** `Failed asserting that 1.0 is greater than 1.0.`

### Mechanism

`LabOperationalAnalyticsService` computes lateness as:

```php
$lateness[] = round($due->diffInMinutes($delivered) / 1440, 2); // days late
```

The `late` fixture uses `due_date = now()->subDays(2)->toDateString()` — a
**date**, compared against the **end** of that due day — and delivers at `now()`.
Just after midnight the gap is only marginally over one day, and `round(…, 2)`
collapses it onto the boundary:

| Wall-clock `now()` | Gap (min) | Rounded value | `> 1.0` |
|---|---|---|---|
| 00:00 | 1441 | 1.00 | **FAIL** |
| 00:03 | 1444 | 1.00 | **FAIL** |
| 00:05 | 1446 | 1.00 | **FAIL** |
| 00:07 | 1448 | 1.01 | pass |
| 00:30 | 1471 | 1.02 | pass |

So the test fails **only when it executes within roughly the first seven minutes
of a day** — about 0.5% of runs. CI executed it at `00:02:59Z`, inside that
window. The base-branch run of the same gate passed at `21:59Z`, outside it.

### Why it is not this sprint's change

- This branch touches no file under `tests/Feature/LabWorkflow/` or
  `app/Modules/LabOrder/` — `git diff --name-only 9576af9f..HEAD` over both paths
  is empty.
- The only behavioural change is the PDF temp-file helper, which that suite does
  not call.
- The failure is fully explained by wall-clock time and reproduces from the
  service's own formula without involving any temporary file.

### Why it was not fixed here

Out of the declared surface for a test-infrastructure lifecycle fix, and the
correct repair is a judgement call in the Lab domain rather than a mechanical
one: relaxing the assertion to `>=` would silently drop the "median lateness is
meaningfully more than one day" property it exists to assert, so the fixture
should be made deterministic instead (a wider `late` offset, or a frozen clock).
That belongs to a sprint that owns the Lab analytics contract.

### Why it still matters

The stabilization programme is blocked on a **single** authorised Full Suite
run. A test with a ~0.5% time-of-day failure rate can redden that run for a
reason unrelated to whatever it is meant to certify. Recommended as a small,
self-contained follow-up before the next authorisation is spent.
