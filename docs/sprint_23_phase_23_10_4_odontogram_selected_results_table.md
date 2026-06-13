# Sprint 23 Phase 23.10.4 — Odontogram Selected Results Table Notes Fix

## 1. Status

- **Complete**
- **Branch:** `feature/sprint-23-phase-23-10-4-odontogram-selected-results-table` (from `sprint-23-phase-23-10-2-odontogram-additional-fields` / `3378500`)
- **Commit:** _(see tag below — set at commit time)_
- **Tag:** `sprint-23-phase-23-10-4-odontogram-selected-results-table`
- Local only — no VPS deploy, no push, no destructive DB commands.

## 2. Correction

The previous Phase 23.10.2 implementation used **global** additional fields
("Kondisi Tambahan" / "Catatan Odontogram" textareas). Smoke review found this
wrong for the clinic workflow.

The correct workflow is a **selected odontogram results table** where each
selected odontogram entry (a tooth/area carrying a status) renders as a table
row with **per-row** "Kondisi Tambahan" and "Catatan Tambahan" inputs.

## 3. Corrected Behavior

- Each tooth marked on the FDI grid appears as a row in the new
  **"Hasil Odontogram yang Dipilih"** table.
- Columns: `No | Gigi / Area | Kondisi Odontogram | Kondisi Tambahan | Catatan Tambahan`.
- Empty state: `Belum ada kondisi odontogram yang dipilih.`
- Table updates live as conditions are selected/unselected on the grid (Alpine).
- Each row has an editable **Kondisi Tambahan** (`additional_condition`) and
  **Catatan Tambahan** (`additional_note`) while draft.
- Draft save persists per-row values; values remain visible after save.
- Finalization preserves per-row values; rows become display-only.
- Show, odontogram print, and the visit print bundle all render the table.
- The previous global fields are kept but **de-emphasized** as an optional
  general "Catatan Umum Odontogram" section below the table (legacy/general).

## 4. Storage

- Per-row data lives **inside `tooth_map_payload`** (no new migration).
  Each selected tooth entry gains two optional string keys:

  ```json
  "11": {
    "status": "caries",
    "note": "...",
    "conditions": ["caries"],
    "additional_condition": "Karies profunda",
    "additional_note": "Rencana tambal komposit"
  }
  ```

- Keys are optional; old payloads without them render `—` safely.
- The Sprint 23.10.2 `additional_conditions` column is **retained** and reused
  only as the optional general "Kondisi Tambahan (umum)" field; it is not used
  for the corrected per-row workflow. `summary_notes` remains the general
  "Catatan Odontogram (umum)" field. No columns removed.
- Validation: `tooth_map_payload.teeth.*.additional_condition` and
  `.additional_note` → `nullable|string|max:1000`. Service whitelist and
  per-tooth normalization preserve the new keys (only `conditions` is
  de-duplicated).

## 5. Tests

```
php artisan test --filter=OdontogramSelectedResultsTableTest   # 23 passed (64 assertions)
php artisan test --filter='Odontogram|RmePilotDataEntryHardeningTest|RmeVisitListBranchFilterTest|RmeClinicSourceFromBranchTest|RmePdfPrintHardeningTest'  # 202 passed (527 assertions)
php artisan test --filter='Rme|ClinicVisit|Patient|Permission|Sidebar|Branch'  # exit 0 (all passed)
./vendor/bin/pint --dirty   # passed
npm run build               # built OK
```

## 6. Files Changed

### Views / JS
- `resources/js/app.js` — `odontogramEditor`: `statusLabels`, `selectedRows`
  getter, `statusLabel()`, `setAdditional()`; `clickTooth()` preserves per-row
  additional fields on status re-apply.
- `resources/views/rme/visits/odontogram/show.blade.php` — new
  "Hasil Odontogram yang Dipilih" table (live edit + read-only), empty state,
  de-emphasized general notes card.
- `resources/views/rme/visits/odontogram/print.blade.php` — per-tooth table
  retitled "Hasil Odontogram yang Dipilih" with `Kondisi Tambahan` /
  `Catatan Tambahan` columns.
- `resources/views/rme/visits/partials/print-body.blade.php` — visit print
  bundle odontogram table gains the two per-row columns.

### Service / request / model
- `app/Modules/Odontogram/Requests/UpdateOdontogramPlaceholderRequest.php` —
  validation rules for `additional_condition` / `additional_note`.
- (No service/repository/model/migration changes required — per-row data flows
  through the existing `tooth_map_payload` array.)

### Tests
- `tests/Feature/RME/OdontogramSelectedResultsTableTest.php` — 23 tests.

### Docs
- `docs/sprint_23_phase_23_10_4_odontogram_selected_results_table.md` (this file)
- `docs/sprint_history.md` — phase entry.

## 7. Out of Scope

- No tooth-grid redesign beyond selected-results table sync.
- No data rewrite / backfill.
- No column removal (`additional_conditions`, `summary_notes` retained).
- No new migration.
- No VPS deploy. No destructive DB commands. No push.

## 8. Watch Items

- VPS deploy requires DB backup first; `migrate --force` only.
- Browser smoke must verify the selected results table appears, updates live as
  teeth are marked, and saves per-row Kondisi Tambahan / Catatan Tambahan.
- Confirm the de-emphasized general "Catatan Umum Odontogram" fields do not
  confuse users; remove later if smoke confirms they are redundant.
- Confirm odontogram print and visit print bundle display the row-level notes.

## 9. Next Recommended Phase

Sprint 23 Phase 23.10.5 — VPS Deploy + Odontogram Selected Results Table Smoke.
