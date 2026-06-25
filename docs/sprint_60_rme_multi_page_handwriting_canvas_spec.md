# Sprint 60 — Multi-Page RME Handwriting Canvas (1 Canvas = 1 Halaman Rekam Medis)

Branch (planned): `feature/sprint-60-rme-multi-page-handwriting-canvas`
Mode: LIMIT SAVER — additive only. No deletion of existing columns/tables. No deploy. No GO tag.

> **Core correction vs. Sprint 59.2:** the handwriting canvas is **NOT** one
> infinitely tall writing area, and we do **NOT** keep extending one canvas
> downward endlessly. **1 canvas = 1 RM page (1 halaman rekam medis).** One
> Medical Record holds **multiple** RM pages. When a page is full, the doctor
> adds a new RM page/canvas **inside the same Medical Record** — without
> creating a new visit and without creating a new Medical Record.

## 1. Goal
Replace the single tall handwriting canvas with a **paged** handwriting model:

- One `MedicalRecord` → many RM pages (`page_number` 1, 2, 3, …).
- Each RM page is exactly one canvas with its own RM table template.
- Doctor sees read-only page previews; tapping a preview opens a same-page
  overlay/modal editor for **that page only**; saving updates that preview.
- A "+ Tambah Halaman RM" action creates the next page/canvas in the **same**
  Medical Record.
- The existing single PNG record continues to work, presented as **Page 1**.

## 2. Current behavior (Sprint 59 / 59.1 / 59.2)
- Table `trx_medical_record_handwritings`: **one row per `medical_record_id`**
  (`handwriting_path`, `handwriting_hash`, `saved_at`, `branch_id`, `doctor_id`,
  `created_by`, timestamps). Migration `2026_06_13_200002`.
- `MedicalRecordHandwritingController@store` looks up the single existing row via
  `MedicalRecordHandwritingRepository::findByMedicalRecordId()` and **overwrites
  it** (`update($existing, …)`). There is no concept of pages.
- Canvas in `resources/views/rme/visits/medical-record/show.blade.php`:
  `<canvas id="rme-canvas" width="900" height="1100" style="max-width:100%;height:auto;">`,
  drawn with a built-in RM table template (columns **Hari / Tanggal**,
  **Pemeriksaan**, **Ket**). Sprint 59.2 simply made this one canvas taller
  (height 1100). This is the behavior Sprint 60 reverses in favor of paging.
- Sprint 59.1 guards: blank/transparent PNG submit is rejected
  (`isBlankHandwriting`) and never overwrites saved strokes; "Reset ke Tulisan
  Tersimpan" reloads the saved PNG (non-destructive). KTP is intentionally not
  rendered. Prev/next visit navigation + Informasi Kunjungan biodata table exist.
- Finalize still requires at least one saved handwriting; handwriting remains
  editable after finalization (Sprint 59). Full-payment-only and SOAP-hidden
  rules unchanged.

## 3. Concept model (Sprint 60)
```
MedicalRecord (1)
 └── RM Pages (N)   ← each is one canvas
       ├── Page 1   page_number=1  handwriting_path  handwriting_hash  timestamps
       ├── Page 2   page_number=2  …
       └── Page 3   page_number=3  …
```
- Adding a page = new page row in the **same** Medical Record. **No** new visit,
  **no** new Medical Record.
- Page 1, Page 2, Page 3 … all belong to the same Medical Record.
- Saving Page 2 must not overwrite Page 1; saving Page 3 must not overwrite
  Page 1 or Page 2 — each page persists to its own row/path/hash.

## 4. Canvas size requirement (per page)
- Each page canvas matches an **RM sheet/page aspect ratio** — portrait,
  paper-like, similar to A4.
- Internal size: **width 900 × height ≈ 1273** (A4 portrait ≈ 1 : 1.414), or
  another consistent portrait page ratio applied uniformly to every page.
- Responsive on screen: `max-width: 100%; height: auto;` (unchanged pattern).
- The RM table template **fits inside each page**. Every page renders its own
  template header: **Hari / Tanggal**, **Pemeriksaan**, **Ket**.
- This replaces the single "extend downward" canvas: instead of one 1100-tall
  canvas, each page is one ~1273-tall A4-ratio canvas and overflow goes to the
  next page.

## 5. Data requirement — additive migration
New table (additive; **do not** delete or alter existing columns):

```
trx_medical_record_handwriting_pages
  id
  medical_record_id   FK → trx_medical_records   (cascadeOnUpdate, restrictOnDelete)
  clinic_visit_id     FK → trx_clinic_visits
  branch_id           FK → mst_branches
  doctor_id           FK → mst_doctors (nullable)
  page_number         unsignedInteger            (1-based; 1,2,3,…)
  handwriting_path    string
  handwriting_hash    string(64)
  saved_at            timestamp nullable
  created_by          FK → users (nullable)
  updated_by          FK → users (nullable)      (if the current pattern supports it)
  timestamps
  UNIQUE (medical_record_id, page_number)
  index (medical_record_id)
  index (clinic_visit_id)
```

The page row mirrors the existing `trx_medical_record_handwritings` columns and
adds `page_number` + the `UNIQUE(medical_record_id, page_number)` guard so two
saves can never collide on the same page or silently overwrite a sibling page.

### Backward compatibility — Page 1 (LOCKED: read-through)
The Page 1 strategy is **locked** — see §5.1. Sprint 60 uses **read-through**:
the legacy `trx_medical_record_handwritings` row stays authoritative for Page 1,
and the new pages table holds **Page 2 and later only**. No backfill in the
initial Sprint 60 implementation.

- Existing single-PNG records in `trx_medical_record_handwritings` **must keep
  working** and **must behave as Page 1**.
- **Do not** delete or mutate `trx_medical_record_handwritings` or its columns.
- The legacy row is the **canonical Page 1**; the new pages table holds only
  Page 2+. A read accessor exposes a unified ordered page list where index 0 =
  legacy row. Saving Page 1 updates the legacy row (preserving Sprint 59.1
  guards); saving Page 2+ writes the pages table and **never** overwrites Page 1.
- **Old handwriting must not be erased**, and a Medical Record with only the
  legacy PNG must render exactly one preview labelled "Halaman 1".

## 5.1 Locked Implementation Decision — Page 1 read-through
**Decision (LOCKED):** Use the low-risk **read-through** strategy for Page 1
backward compatibility. Do **not** backfill existing PNGs into the pages table
during the initial Sprint 60 implementation.

**Final rule:**
1. Existing legacy `trx_medical_record_handwritings.handwriting_path` remains
   **authoritative for Page 1** when no new page-row is explicitly created.
2. **Do not** backfill existing PNGs into `trx_medical_record_handwriting_pages`
   during the initial Sprint 60 implementation.
3. **Do not** mutate or delete old `trx_medical_record_handwritings` columns.
4. `trx_medical_record_handwriting_pages` is **additive**.
5. Use the new pages table for **Page 2 and later**.
6. Saving Page 2+ must **never** overwrite Page 1.
7. Page 1 read-through must **preserve existing print/receipt/test paths** that
   still read the legacy table.
8. If we later decide to migrate Page 1 into the pages table, that must be a
   **separate controlled sprint** with database backup and regression tests
   (not part of Sprint 60).

**Why read-through (rationale):**
- **Lowest risk** — the smallest possible change to reach multi-page support.
- **No existing data mutation** — legacy rows and columns are left exactly as-is.
- **Avoids breaking existing single-PNG RM records** — they keep rendering as
  Page 1 with zero migration.
- **Avoids touching existing print/receipt/test paths** — every current reader
  of `trx_medical_record_handwritings` continues to work unchanged.
- **Incremental** — multi-page (Page 2+) is layered on additively, and a future
  full migration of Page 1 stays a deliberate, separately-tested decision.

## 6. UX flow
1. Medical Record page shows **RM page previews** (one per page).
2. Each preview represents one RM page/canvas and is **read-only**.
3. Doctor clicks/touches a page preview.
4. A **same-page overlay/modal** opens to edit that selected RM page.
5. Doctor writes on the selected page (template + saved strokes loaded in).
6. Doctor saves.
7. Overlay closes.
8. That page's preview updates (re-renders the new PNG).
9. If the page is full, doctor clicks **"+ Tambah Halaman RM"**.
10. System creates the next page/canvas (`page_number = max + 1`) in the **same**
    Medical Record and opens it for editing.
11. No new visit, no new Medical Record is created by adding a page.

The previews + overlay replace the always-on inline canvas. The editor markup
(canvas, template draw, save/reset buttons, blank-guard, reset-to-saved) is the
existing Sprint 59 editor, relocated into the overlay and parameterized by the
selected `page_number`.

## 7. Preserved behavior (must not regress)
- Old handwriting must **not** be erased.
- Existing page handwriting **loads into the editor** when its preview is opened.
- Doctor can add handwriting **on top** of the loaded page.
- Save preserves **template + old handwriting + new handwriting** for that page.
- "Reset ke Tulisan Tersimpan" is **non-destructive** and affects **only the
  selected page** (reloads that page's saved PNG).
- Blank/empty submit must **not** erase the existing page (Sprint 59.1 guard
  applies **per page**).
- **KTP must not be displayed** anywhere on the page/previews/overlay.
- Previous/next **visit navigation remains unchanged**.
- **Informasi Kunjungan** biodata table remains unchanged.
- Finalize still requires at least one non-blank saved page. SOAP stays hidden,
  full-payment-only unchanged.

## 8. Files inspected
- `database/migrations/2026_06_13_200002_create_trx_medical_record_handwritings_table.php`
- `app/Modules/MedicalRecord/Models/MedicalRecordHandwriting.php`
- `app/Modules/MedicalRecord/Controllers/MedicalRecordHandwritingController.php`
- `app/Modules/MedicalRecord/Repositories/MedicalRecordHandwritingRepository.php`
- `app/Modules/MedicalRecord/Interfaces/MedicalRecordHandwritingRepositoryInterface.php`
- `app/Modules/MedicalRecord/Services/MedicalRecordService.php`
- `resources/views/rme/visits/medical-record/show.blade.php` (canvas + template JS)
- `resources/views/rme/visits/partials/print-body.blade.php`,
  `resources/views/rme/visits/print.blade.php`,
  `resources/views/rme/visits/print-pdf.blade.php`,
  `resources/views/rme/cashier/partials/clinical-summary.blade.php`
  (everywhere the handwriting PNG is rendered → must render **all** pages)
- `routes/web.php` (rme group, handwriting.store route)

## 9. Files expected to change
- `database/migrations/<new>_create_trx_medical_record_handwriting_pages_table.php` — new table (created).
- `app/Modules/MedicalRecord/Models/MedicalRecordHandwritingPage.php` — new model (created).
- `app/Modules/MedicalRecord/Models/MedicalRecord.php` — `handwritingPages()` ordered relation + unified page accessor.
- `app/Modules/MedicalRecord/Controllers/MedicalRecordHandwritingController.php` —
  accept `page_number`; save/create per page; add "add page" handling.
- `app/Modules/MedicalRecord/Repositories/MedicalRecordHandwritingRepository.php` (+ interface) —
  per-page find/upsert (`findByMedicalRecordIdAndPage`, `nextPageNumber`).
- `routes/web.php` — page-aware handwriting store (reuse existing route name with
  `page_number` param, or add `…/handwriting/{page}` — prefer keeping one route).
- `resources/views/rme/visits/medical-record/show.blade.php` — page previews grid,
  "+ Tambah Halaman RM" button, overlay/modal editor (relocated canvas).
- Print/receipt/summary views — loop over **all** pages instead of one PNG.
- `database/factories/MedicalRecordHandwritingPageFactory.php` — new factory.
- `tests/Feature/RME/Sprint60MultiPageHandwritingTest.php` — new tests (created).
- `CLAUDE.md` — append Sprint 60 note on closure.

## 10. Canvas/template design notes
- Canvas attributes per page: `width="900" height="1273" style="max-width:100%;height:auto;"`.
- The existing template-draw JS (column separators + headers Hari / Tanggal,
  Pemeriksaan, Ket; ruled rows) is reused but recomputed for the A4-ratio height
  so the template fills the page.
- Preview = the saved PNG (or the blank template render for a brand-new empty
  page), shown read-only at responsive width.

## 11. Access control / privacy
- View + edit reuse existing gates: `view_clinic_visits|manage_clinic_visits`
  group + `authorize('update', $medicalRecord)` for saving a page (unchanged).
- No new permission slug. No role rewrite.
- Previews/overlay render only clinical handwriting; **no KTP**, no phone/WA/
  address/password/token/`.env`.

## 12. Test plan (`tests/Feature/RME/Sprint60MultiPageHandwritingTest.php`)
1. An existing single-PNG record appears as **Page 1** (backward compatible).
2. Adding **Page 2** inside the same Medical Record (no new visit, no new MR).
3. Saving **Page 2** does **not** overwrite Page 1 (distinct path/hash/row).
4. Saving **Page 3** does **not** overwrite Page 1 or Page 2.
5. Clicking/opening a page returns/loads the editor overlay for **that** page
   with the page's saved handwriting preloaded.
6. "Reset ke Tulisan Tersimpan" affects **only the selected page**.
7. Blank/transparent submit for a page does **not** erase that page's existing
   handwriting (Sprint 59.1 guard per page).
8. **No KTP** is rendered on the medical-record page, previews, or overlay.
9. `UNIQUE(medical_record_id, page_number)` prevents duplicate page rows.
10. Previous/next visit navigation still works (unchanged).
11. Informasi Kunjungan biodata table still renders (unchanged).
12. Finalize still requires at least one non-blank saved page.
13. Print/receipt/summary render **all** pages, not just Page 1.

## 13. Acceptance criteria
- **1 canvas = 1 RM page.**
- Canvas size follows an RM page/sheet ratio (portrait, A4-like, ~900×1273).
- Multiple pages can exist under one Medical Record.
- Doctor can add a new RM page when the current page is full.
- Page previews are **read-only** until clicked/touched.
- Editor opens in the same page as an overlay/modal.
- Save updates the selected page preview.
- **No page overwrite** between Page 1, Page 2, Page 3.

## 14. Risk checklist
- Migration is **additive**; `trx_medical_record_handwritings` and its columns
  are **not** dropped or altered.
- Old single-page records keep rendering as Page 1 via **read-through** (§5.1):
  no backfill, no data migration that can erase existing PNGs.
- Sprint 59.1 blank-guard and reset-to-saved semantics preserved **per page**.
- KTP-hidden, prev/next nav, and biodata table untouched.
- Payment / cashier / generation / conversion logic untouched.
- No permission slug rename/delete; no auth rewrite.

## 15. Rollback plan
Additive change. To roll back: revert the feature commit (new migration + model
+ controller/repo/interface edits + view + print loops + factory + tests + doc).
The legacy `trx_medical_record_handwritings` rows remain the source of truth for
Page 1, so reverting restores the Sprint 59 single-canvas behavior with no data
loss. Drop `trx_medical_record_handwriting_pages` only after confirming Page 2+
data is not needed (or export first).
