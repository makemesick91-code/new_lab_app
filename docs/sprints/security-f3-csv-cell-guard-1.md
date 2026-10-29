# SECURITY-F3-CSV-CELL-GUARD-1

**Status:** IMPLEMENTED AND VERIFIED LOCALLY. Not pushed, not merged, not deployed.

**Type:** `SECURITY_FIX`. **Base:** `19a2ed1c`
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`). Audit item F-3,
decision D20 (numeric rule, recommended default) —
`docs/audit/full-system-audit-2026-10-27/POST-AUDIT-EXECUTION-ROADMAP.md`, PR-04.

No migration, no route, no permission.

## 1. Finding

A spreadsheet runs a cell that begins with `=`, `+`, `-`, `@`, a tab or a carriage return as a
formula. Seven web exporters wrote such values unchanged — patient names, product and location
names, batch numbers, notes. Five other exporters each carried a private copy of the same guard.

| Exporter | Carries |
|---|---|
| `RmeReportController::streamCsv()` (patients, payments) | patient names |
| `RmeInvoiceController::exportReceivables()` | patient names |
| `PatientAuditController::export()` | patient names |
| `Reporting\ExportReportService::stream()` | lab report rows, patient names |
| `InventoryReportService::exportCsv()` | product, location and user names, notes |
| `InventoryBatchDisposalReportService::exportCsv()` | batch numbers, evidence references |
| `InventoryBatchMonthlyClosingPackService::exportCsv()` | batch numbers, references |

## 2. Change

- `App\Support\Export\CsvCell`: `guard()` prefixes an apostrophe to a text cell that begins with
  one of the six characters; `row()` applies it to every cell. Numbers stay numbers (D20): ints,
  floats and plain signed decimal strings such as `-15000.00` or `+62812` are written unchanged,
  so numeric columns remain numeric. A value such as `-2+3` or `-1e5` is not a plain decimal and
  is guarded.
- Every row of the seven exporters passes through `CsvCell::row()` (20 write sites).
- End-to-end test per exporter with a hostile value (`=1+1`, `@SUM(A1)`, `-2+3`, `+SUM(1,2)`,
  `=HYPERLINK(…)`, `=B…`, `@B…`), each asserting the apostrophe-prefixed cell and the absence
  of the raw one. **Each fails on the base code and passes here.**
- `CsvFormulaInjectionGuardTest` (22): the rule for each trigger, multibyte text, stringable
  values, numbers and plain numeric strings; and a scan of `app/` — every file that writes CSV
  either routes rows through `CsvCell` or is on an exact list with the reason. A new exporter that
  skips the guard, or a listed file that stops writing CSV, fails the test.
- Declared mandatory; `CsvFormulaInjectionGuard` token in both critical-gate variants.

## 3. Verification

Mutations against the helper and the scan (reverted by copy):

| Mutant | Result |
|---|---|
| `-` dropped from the trigger set | killed |
| any text treated as a plain decimal | killed |
| apostrophe not added | killed |
| one exporter's row unwrapped | killed (scan) |
| int/float handled by the text branch | survives — **equivalent**: the text branch returns non-text values unchanged |

Regression (RME report, cashier, patient audit, lab reporting, inventory report, disposal,
closing pack, export security and today-default suites, plus `tests/Feature/Security`):
354 passed, 0 failed.

## 4. Known exception

`InventoryAmbiguousBatchReviewPackCommand` (an operator CLI file writing product codes) is not
guarded. It is outside F-3's seven web exporters and is listed, with that reason, in the scan's
exception list, so it stays visible. Consolidating the five private guard copies onto `CsvCell`
is SAFE-CLEANUP M-8.

## 5. Production effect

Exported text changes only for cells beginning with one of the six characters (e.g. a name
`-Kassa` becomes `'-Kassa`). None of these exports is re-imported.

## 6. Rollback

`git revert` + redeploy.
