# REVISION-RME-REPORTS-TODAY-DEFAULT-1 — RME Report Default Period = Clinical Today

**Branch** `revision/rme-reports-today-default-1`
**Base** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `1f80286`
(production runtime, GO tag `feature-daily-branch-context-lock-1-go`) — do NOT target `main`.

**Classification** FEATURE_REVISION · RME_REPORTING · DEFAULT_SCOPE ·
BRANCH_SECURITY_REGRESSION_SENSITIVE

---

## 1. What changed, in one line

Opening **Laporan Pasien RME** or **Laporan Pembayaran RME** now shows **only the
current clinical day**. History is opt-in and requires an explicit date filter.

## 2. The defect

Both reports applied their date predicate only when the operator had supplied
one:

```php
->when($request->filled('date_from'), fn ($q) => $q->whereDate('visit_date', '>=', ...))
->when($request->filled('date_to'),   fn ($q) => $q->whereDate('visit_date', '<=', ...))
```

With no filter there was no bound, so a bare report URL returned **every visit
and every payment the branch had ever recorded**. The same was true of the CSV
export and the print view, which share those query builders — so a single click
on *Export Excel* from a freshly opened report produced a full historical
extract of the branch.

An Admin Klinik or Kasir whose actual question is "who is here today" had to
filter their way down to the answer every time.

## 3. The contract now in force

```
no explicit date filter   → from = to = clinical today
explicit valid filter     → the requested period
reset                     → today   (never all history)
```

Priority is **explicit filter > default today**. The period is *always* bounded;
there is no input that turns an unfiltered report into an all-history report.

### Canonical report date — audited, not assumed

Both reports key off **`trx_clinic_visits.visit_date`**.

The payment report already reached that column through `whereHas('clinicVisit')`
before this sprint, so its date semantics are **unchanged** — only the default
changed. This sprint deliberately did **not** collapse payment date, invoice
date and visit date into one another, and did not switch the report to
`trx_rme_payments.paid_at`. "Pasien untuk hari ini" is a question about visits.

### "Today" is the clinical day

Resolved through `App\Support\Clinical\ClinicalClock` (Asia/Makassar), never
`now()`, `Carbon::today()` or `date()`. `config/app.php` is UTC on purpose:
between 16:00 and 24:00 UTC it is already tomorrow in the clinic, and a
UTC-anchored default would show the wrong day's patients for eight hours of
every day.

### Fail closed on bad input

A malformed bound (`not-a-date`, `29/08/2026`, `2026-13-45`, an empty string) is
**not** authorisation to widen the period. Each bound is validated
independently; an unusable one is treated as absent, and when that leaves no
bound at all the period collapses back to today.

Dates are parsed **strictly** through `!Y-m-d` with a round-trip check.
`Carbon::parse('2026-13-45')` silently rolls to 2027-02-14 — a rolled date is a
different clinical period than the one the operator typed, so it is rejected
rather than reinterpreted.

## 4. Branch authority is untouched

A date filter widens the **period**, never the **branch scope**. The two
predicates are ANDed in every report query:

```
authorised branch scope   AND   date period   AND   search / other filters
```

`RmeWorkingBranchScope` remains the single branch authority (FIX-04/FIX-09), a
request `branch_id` is still only ever *narrowing* and is dropped when outside
the viewer's scope, and the daily branch lock keeps deciding which branch a
context-bound Kasir or Admin Klinik is reading. Requesting a historical range
returns the viewer's own history and nobody else's — proven by
`never lets a historical date filter widen the Admin Klinik branch scope`.

## 5. Files

| File | Change |
|---|---|
| `app/Modules/RmeInvoice/Support/RmeReportDateScope.php` | **new** — the single normalization authority |
| `app/Modules/RmeInvoice/Support/RmeReportDateRange.php` | **new** — immutable period + Indonesian label |
| `app/Modules/RmeInvoice/Controllers/RmeReportController.php` | both query builders, both filter arrays, the print filter summary |
| `resources/views/rme/reports/patients.blade.php` | period indicator, *Reset ke Hari Ini*, today-aware empty state |
| `resources/views/rme/reports/payments.blade.php` | same |
| `tests/Feature/RME/RmeReportTodayDefaultTest.php` | **new** — 26 tests |
| `tests/Feature/RME/RmeReportFilterTest.php` | fixture clock fix (see §7) |
| `tests/Feature/RME/RmeReportExportTest.php` | fixture clock fix (see §7) |

**No migration. No new route. No new permission. No schema change.** No change to
registration, queue, consent, RME/odontogram, visit completion, invoice or
payment write behaviour.

## 6. One period per request — and why the memo does not live on the controller

All six surfaces (`patients`, `patientsExport`, `patientsPrint`, `payments`,
`paymentsExport`, `paymentsPrint`) read **one** `RmeReportDateRange` per request,
which is what makes the screen, the totals, the CSV, the print view and the
filter summary provably agree. The screen can no longer say "today" while the
export quietly returns the archive.

The memo is stored on the **`Request`**, not on the controller or the scope.

The first implementation used a `private ?RmeReportDateRange $dateRange` property
on the controller. The clinical-midnight test caught it: Laravel caches the
controller instance on the `Route` object, and the `Router` is a singleton, so
that property survives between requests wherever the application is not torn
down per request — every multi-request test, and any long-lived worker. It pinned
the report to the first request's day and kept serving a stale "today" after the
clinical midnight had passed. The request is the only object whose lifetime is
exactly one report render.

This is the STATELESS-1 rule applied to a controller property, and it was found
by running the test, not by reading the code.

## 7. Test-fixture correctness (a real flake this sprint had to close)

`RmeReportFilterTest` and `RmeReportExportTest` stamped their "today" fixtures
with `now()->toDateString()` — a **UTC** day. `ClinicVisitFactory` already used
`ClinicalClock` for exactly this reason (FIX-06), and those two helpers were
overriding it back to UTC.

Harmless while the reports were unbounded. The moment the default became "today",
a fixture dated on the UTC day would be *clinical yesterday* for the eight hours
between 16:00 and 24:00 UTC, and both suites would have failed for a third of
every day. Both helpers now use the existing `clinicalToday()` test helper.

This is the same failure class as the lab SLA midnight flake: a fixture at
date-granularity compared against a clock at clinical-day granularity.

## 8. Surfaces that do not exist

Reported honestly rather than claimed:

- **Pagination** — none. Both list queries use `->limit(100)`; there is no
  paginator and no `page` parameter, so there is nothing for a date scope to be
  lost across.
- **User-facing sorting** — none. Order is fixed (`latest('visit_date')` /
  `latest('paid_at')`); no `sort`/`direction` parameter exists.
- **Server-side PDF** — none. *Cetak / PDF* opens a Blade print view and uses the
  browser's own print dialog. It shares the report query builder, so it inherits
  the same period; there is no separate dompdf route for these two reports.

Adding any of them later must preserve the normalized period.

## 8b. Blast radius outside the two reports

Only two call sites reference these routes, and neither needed a change:

- `OwnerDashboardKpiService::moduleShortcuts()` links to the **bare** route, so a
  drilldown from the Owner dashboard now lands on today. Intended.
- `StressBenchmarkRmePagesCommand` hits both reports with `?branch_id=` only, so
  the benchmark now measures the today-scoped page rather than a full-archive
  render. That is a real change in what the benchmark measures, and it is left
  as-is deliberately: the benchmark should measure the page operators actually
  open. Pinning it to a historical range would be a new benchmark decision, not
  part of this revision. (`tests/Unit/Console/StressBenchmarkRmePagesCommandTest`
  — 3 passed.)

## 9. Adversarial validation

12 mutations attempted, **10 killed, 0 real survivors**, 2 not applicable.

| # | Mutation | Verdict |
|---|---|---|
| M1 | patient report loses the today default | KILLED (9 failed) |
| M2 | payment report loses the today default | KILLED (6 failed) |
| M3 | default becomes all-history | KILLED (16 failed) |
| M4 | search escapes the date scope | KILLED (2 failed) |
| M5 | pagination drops the date scope | **N/A** — no pagination exists |
| M6 | export bypasses the today default | KILLED (5 failed) |
| M7 | export ignores an explicit filter | KILLED (2 failed) |
| M8 | request `branch_id` becomes authority | KILLED (1 failed) |
| M9 | reset returns all-history | **equivalent to M3** — a reset *is* the bare URL, same code path |
| M10 | host UTC date replaces `ClinicalClock` | KILLED (1 failed) |
| M11 | working-branch scope widened to all RME branches | KILLED (4 failed) |
| M12 | explicit date filter ignored | KILLED (8 failed) |

Two findings came out of this rather than being assumed:

**M4's first verdict was invalid, not a survivor.** Its mutation regex never
matched, so the "SURVIVED" result was the harness failing to mutate. Re-run with
a mutation that actually applied (verified by diffing the file), it was killed.
A mutation that does not apply must be reported as an invalid run, never as
coverage.

**M8 was a genuine coverage gap and produced a new test.** Removing the
controller's `allows()` guard survived the first run because
`RmeWorkingBranchScope::narrow()` independently re-checks membership — row data
is protected twice over, so no data assertion could see the difference. But
`resolveBranchId()` also feeds the print filter summary, which is the one place a
crafted `branch_id` is *not* re-checked downstream: without the guard, an Admin
Klinik passing `?branch_id=<foreign>` would print `Cabang: <foreign branch name>`
above correctly-scoped rows. Branch name only, no patient data — but the guard is
load-bearing there, so it is now pinned by
`never echoes a foreign branch name into the printed filter summary`.

## 10. Durable rules

1. RME Patient Report and RME Payment Report default to the **clinical today**.
2. Historical data requires an **explicit** date filter.
3. **Reset returns to today**, never to all history.
4. Search, and any future pagination or sorting, stays **inside** the selected
   period — search must never silently reach back through the archive.
5. Export and print use the **same normalized period** as the screen.
6. A date filter widens the period, **never** the branch authorization.
7. `RmeWorkingBranchScope` stays the branch authority; a request `branch_id` is
   never authority, only a narrowing filter.
8. `ClinicalClock` is the only authority for "today" in a report.
9. An invalid or partial date filter **fails closed to today**, never to
   all-history.
10. The resolved period is memoized **per request**, never on a controller.
11. The canonical report date for both reports is
    `trx_clinic_visits.visit_date`.
