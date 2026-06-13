# Sprint 23 Phase 23.10.2 — Odontogram Additional Conditions and Notes Input Fix

## 1. Status

- **Complete** (local only — not deployed, not pushed)
- **Branch:** `feature/sprint-23-phase-23-10-2-odontogram-additional-fields`
- **Commit:** see `Show odontogram additional fields before finalization`
- **Tag:** `sprint-23-phase-23-10-2-odontogram-additional-fields`
- **Baseline:** `sprint-23-phase-23-10-rme-pilot-data-entry-hardening` (commit `bf3a43a`)

## 2. Bug

During Sprint 23 Phase 23.10.1 VPS smoke, while filling the odontogram the
general **"kondisi tambahan"** and **"catatan odontogram"** inputs did not
appear. The odontogram fill page only exposed per-tooth conditions (which only
surface after selecting a tooth with a status) and a single generic
"Catatan Ringkasan" field — there was no clearly labelled, always-visible
section for general additional conditions and odontogram notes before
finalization.

## 3. Expected Behavior

Doctors/nurses must be able to fill **Kondisi Tambahan** (general additional
conditions) and **Catatan Odontogram** (additional odontogram notes) before
finalization. They must be able to save and review these fields while the
odontogram is a draft, see them on the show/print pages, and the fields must be
preserved through finalization (read-only afterwards per existing rules).

## 4. Fix

### Fields added / shown
- New **Kondisi Tambahan** textarea → `additional_conditions` column.
- **Catatan Odontogram** textarea → reuses the existing `summary_notes` column
  (previously labelled "Catatan Ringkasan").
- Both grouped under a new section **"Kondisi Tambahan & Catatan Odontogram"** on
  the odontogram fill/edit page, visible and editable while the odontogram is a
  draft and the user has `manage_clinic_visits`. The section renders `old()`
  validation values and saved values.

### Save logic
- `UpdateOdontogramPlaceholderRequest` validates `additional_conditions` as
  `nullable|string|max:5000` (same convention as `summary_notes`).
- `OdontogramService::updatePlaceholder()` whitelists `additional_conditions`
  alongside `summary_notes` and `tooth_map_payload`. Existing tooth-data save and
  condition de-duplication are unchanged.
- `Odontogram` model `$fillable`, the factory, and repository defaults include
  `additional_conditions`.

### Show / print behavior
- **Show page (draft):** editable textareas. **Show page (viewer/finalized):**
  read-only display of both fields, falling back to `—` when null.
- **Odontogram print** (`rme.odontograms.print`): adds a "Kondisi Tambahan"
  section and renames the notes section to "Catatan Odontogram"; both fall back
  to an italic empty message.
- **Visit print bundle** (`partials/print-body.blade.php`): odontogram block now
  shows "Kondisi Tambahan" and "Catatan Odontogram" when present.
- All output is escaped via Blade `{{ }}` — no raw JSON rendered.

### Finalization behavior
- Finalization does not touch `additional_conditions` or `summary_notes`; both
  are preserved. After finalization the fields render read-only (the editable
  form is gated behind `$canUpdate`, which is false once finalized), matching the
  existing immutability rules.

### Storage columns
- Additive migration `2026_06_18_100001_add_additional_fields_to_trx_odontograms_table.php`
  adds **only** `additional_conditions` (text, nullable) after `summary_notes`.
  `summary_notes` is reused for notes; no `notes` column was added. No backfill,
  no data rewrite. Migration guards with `Schema::hasColumn`.

## 5. Tests

```
php artisan test --filter=OdontogramAdditionalFieldsTest   # 15 passed (41 assertions)
php artisan test --filter=Odontogram                       # 103 passed (252 assertions)
php artisan test --filter=RmePilotDataEntryHardeningTest   # 26 passed (67 assertions)
php artisan test --filter=RmeVisitListBranchFilterTest     # 16 passed (45 assertions)
php artisan test --filter=RmeClinicSourceFromBranchTest    # 15 passed (32 assertions)
php artisan test --filter=Rme                              # 511 passed (1438 assertions)
./vendor/bin/pint --dirty                                  # passed
npm run build                                              # built OK
```

New test file `tests/Feature/RME/OdontogramAdditionalFieldsTest.php` covers:
fields visible before finalization, save persistence, max-length validation,
show/print visibility, finalization preservation, read-only finalized display,
tooth data still saving, `clinic_id`-null visit, additional RME branch (ATG3)
allowed, non-RME branch forbidden.

## 6. Files Changed

**Migration**
- `database/migrations/2026_06_18_100001_add_additional_fields_to_trx_odontograms_table.php` (new, additive)

**Model / request / service / repository / factory**
- `app/Modules/Odontogram/Models/Odontogram.php`
- `app/Modules/Odontogram/Requests/UpdateOdontogramPlaceholderRequest.php`
- `app/Modules/Odontogram/Services/OdontogramService.php`
- `app/Modules/Odontogram/Repositories/OdontogramRepository.php`
- `database/factories/OdontogramFactory.php`

**Views**
- `resources/views/rme/visits/odontogram/show.blade.php`
- `resources/views/rme/visits/odontogram/print.blade.php`
- `resources/views/rme/visits/partials/print-body.blade.php`

**Tests**
- `tests/Feature/RME/OdontogramAdditionalFieldsTest.php` (new)

**Docs**
- `docs/sprint_23_phase_23_10_2_odontogram_additional_fields.md` (this file)
- `docs/sprint_history.md`

## 7. Out of Scope

- No automatic rewrite of existing odontogram data.
- No redesign of the tooth grid or per-tooth condition UI.
- No VPS deploy.
- No destructive DB commands.

## 8. Watch Items

- VPS deploy requires a DB backup first.
- A migration was added — deploy must run `php artisan migrate --force` only
  (never `migrate:fresh` / `db:wipe`).
- Browser smoke must verify both fields are visible/editable before finalization.
- Browser smoke must verify finalization keeps the fields and renders them
  read-only.

## 9. Next Recommended Phase

Sprint 23 Phase 23.10.3 — VPS Deploy + Odontogram Additional Fields Smoke.
