# SECURITY-F4A3-CLINIC-CALENDAR-SATUSEHAT-ADOPTION-1

**Status:** implemented and verified locally; not merged, not deployed.

**Type:** `RUNTIME_FIX`. **Base:** `f9134aac` (the deployed production authority). Audit item F-4a
(clinic calendar for RME and financial screens), CG-12. No migration, no route, no permission. No
stored data is rewritten.

## 1. Why this is one of three pull requests

The original fix (local commit `9c342edf`, SECURITY-F4A-CLINIC-CALENDAR-RME-FINANCIAL-1) changed
ten sites in seven modules. `sprint:scope-audit` refuses a `RUNTIME_FIX` touching more than three
modules, so the fix is split by module instead of being reclassified to pass the gate. Each part is
independent — no part changes a signature another part calls — and carries its own tests:
`SECURITY-F4A1-CLINIC-CALENDAR-CLINICAL-RECORDS-1`, `SECURITY-F4A2-CLINIC-CALENDAR-FINANCIAL-DASHBOARDS-1` are the other two.

## 2. Finding

The application timezone is UTC; the clinic runs on Asia/Makassar (UTC+8). `Carbon::today()` and
`now()->toDateString()` therefore name the previous clinic day between 00:00 and 08:00 WITA.
`App\Support\Clinical\ClinicalClock` is the canonical clinic calendar and was not used at:

`SatusehatDiagnosisAdoptionService` (default period end).

## 3. Change

- `ClinicalClock` injected where a site needs the clinic date; DATE columns compare clinic dates.
- Timestamp columns are compared against the UTC instants that bound the clinic day or month
  (half-open, the existing `ClinicOversight\Support\ClinicalDayWindow`); the column is never
  converted, so indexes stay usable.
- `tests/Feature/RME/RmeClinicCalendarBoundarySatusehatAdoptionTest.php` — the cases of the original `RmeClinicCalendarBoundaryTest`
  that cover these sites, frozen at instants where the UTC and clinic dates differ (and a 23:59 WITA
  control where they agree). Declared mandatory; selected by the existing `RmeClinicCalendarBoundary`
  token in both critical-gate variants.

## 4. Verification

See the pull request: the suite fails on the base code and passes with the fix; regression over the
touched modules; DEVFLOW and governance gates.

## 5. Production effect

Between 00:00 and 08:00 WITA the affected screens use the clinic day. Nothing stored is rewritten.

## 6. Rollback

`git revert` and redeploy.
