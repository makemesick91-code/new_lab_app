# Sprint 23 Phase 23.10.6 — Merge Odontogram Selected Results into Medical Record Print

**Base commit:** `e48c645` — "Fix RME cashier branch scoping and clinical summary"
(tag `sprint-23-phase-23-10-5-rme-cashier-branch-clinical-summary`)

**Branch:** `feature/sprint-23-phase-23-10-6-medical-record-print-odontogram-merge`
**Tag:** `sprint-23-phase-23-10-6-medical-record-print-odontogram-merge`

**Scope:** Print/view only. No migration, no schema change, no deployment, no push,
no destructive DB commands.

---

## Goal

Cetak Rekam Medis must be the **main combined print output** and must include the
odontogram selected-results table in the same print page, so the user does not need
to open the separate Cetak Odontogram to see odontogram results.

## What "Cetak Rekam Medis" is

The combined RME visit print bundle:

- Route: `rme.visits.print` → `ClinicVisitController::print`
- View: `resources/views/rme/visits/print.blade.php` (browser `window.print()`) and
  `resources/views/rme/visits/print-pdf.blade.php` (DomPDF), both rendering the shared
  `resources/views/rme/visits/partials/print-body.blade.php`.
- Button "Cetak RME" on `resources/views/rme/visits/show.blade.php`.

The combined bundle already contains: identitas pasien & data kunjungan, rekam medis /
clinical summary (handwriting RM; SOAP stays hidden by design), odontogram, paid
invoice/payment, and the lab workflow summary.

## Change

- Extracted a shared partial
  `resources/views/rme/visits/partials/odontogram-selected-results.blade.php`
  that renders:
  - subsection title **"Hasil Odontogram yang Dipilih"**
  - the merged selected-results table
  - safe empty states
- `print-body.blade.php` now `@include`s this partial inside its existing **Odontogram**
  section, replacing the previous inline table (avoids duplicate logic).

### Table columns (user-friendly merged labels)

| No | Gigi / Area | Kondisi Odontogram | Tanda Klinis / Kondisi Tambahan | Catatan Gigi / Catatan Tambahan |
|----|-------------|--------------------|---------------------------------|---------------------------------|

- **Kondisi Odontogram** — mapped label of `status` (e.g. `caries` → `Karies`).
- **Tanda Klinis / Kondisi Tambahan** — mapped `conditions[]` plus free-text `additional_condition`.
- **Catatan Gigi / Catatan Tambahan** — `note` plus `additional_note`.
- Only selected / non-normal rows (a tooth carrying `status`, `conditions`, or `note`) are shown.

### Empty states

- No odontogram for the visit → `Belum ada data odontogram.`
  (legacy `Odontogram belum tersedia.` retained in the same line to keep the existing
  `ClinicVisitTest` assertion green).
- Odontogram exists but no selected rows → `Belum ada kondisi odontogram yang dipilih.`
- Empty `additional_condition` / `additional_note` / etc. → `—`.

## Data source

The odontogram linked to the visit. Per-row data:

```
tooth_map_payload.teeth.<num>.status
tooth_map_payload.teeth.<num>.conditions
tooth_map_payload.teeth.<num>.note
tooth_map_payload.teeth.<num>.additional_condition
tooth_map_payload.teeth.<num>.additional_note
```

Old payloads without `additional_condition` / `additional_note` render `—` safely.
Output is Blade-escaped; raw `tooth_map_payload` JSON is never printed.

## Preserved behavior

- Separate Cetak Odontogram (`rme.odontograms.print`, `odontogram/print.blade.php`)
  is left untouched and still works.
- Phase 23.10.5 cashier branch scoping + clinical summary (`e48c645`) untouched.
- Phase 23.10.4 odontogram selected-results behavior (`d461ad8`) preserved; the
  existing `additional_conditions` column is **not** removed.
- No migration.

## Tests

New: `tests/Feature/RME/MedicalRecordPrintOdontogramMergeTest.php` — 11 passed, 32 assertions:

1. Cetak Rekam Medis includes an Odontogram section when odontogram exists.
2. Includes "Hasil Odontogram yang Dipilih".
3. Shows selected tooth / area.
4. Shows selected odontogram condition label.
5. Shows per-row Tanda Klinis / Kondisi Tambahan.
6. Shows per-row Catatan Gigi / Catatan Tambahan.
7. Does not show raw `tooth_map_payload` JSON.
8. Handles a visit with no odontogram safely.
9. Handles old payload without additional keys safely.
10. Separate odontogram print still works.
11. Combined output keeps patient + clinical summary sections intact.

Regression (all passed):

- `OdontogramSelectedResultsTableTest | RmePdfPrintHardeningTest | Odontogram |
  RmePilotDataEntryHardeningTest | RmeVisitListBranchFilterTest |
  RmeClinicSourceFromBranchTest | ClinicVisit` — 278 passed (762 assertions).
- `Rme | Patient | Permission | Sidebar | Branch` — 953 passed (2843 assertions).

Build: `./vendor/bin/pint --dirty` passed; `npm run build` succeeded.

## Out of scope

- No payment / generation / conversion logic changes.
- No SOAP doctor UI (stays hidden by design).
- No VPS deployment, no push.

## Next recommended phase

Sprint 23 Phase 23.10.7 — VPS deploy + browser smoke of the combined Cetak Rekam Medis
(backup DB first, `migrate --force` only).
