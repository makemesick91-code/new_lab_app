# Sprint 63.1 — Structured Odontogram Print Template Conversion (DESIGN SPEC ONLY)

> **Status:** IMPLEMENTED (2026-06-28) on branch `feature/sprint-63-1-structured-odontogram-print-template-conversion` (base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`; do NOT target main). No migration. Pending review — no PR/merge/tag/deploy yet.
>
> **Delivered:** `OdontogramPrintFormatter` (read-only view-model) + shared dompdf-safe partial `resources/views/rme/visits/odontogram/partials/structured-print-template.blade.php` (HTML-table tooth grid, no flexbox). Standalone print (`rme.odontograms.print`) and the combined visit print/PDF bundle (`rme.visits.print` / `rme.visits.pdf`) both consume them; the now-redundant `odontogram-selected-results.blade.php` partial was removed. DMF-T reuses `Odontogram::dmftCounts()`. Tests: new `tests/Feature/RME/StructuredOdontogramPrintTemplateTest.php` (11). Green: Odontogram filter 155, RME dir 833, print/merge/pdf regression 55; pint + `git diff --check` clean.
> **Base branch (do NOT target main):** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`.
> **Scope discipline:** This sprint **renders already-saved structured odontogram data** into a printable/PDF layout matching the Daengtisia paper odontogram form. It does **not** change data entry, finalization, payment, or receivable logic.

## 0. Cancellation note — what this sprint is NOT

The earlier Sprint 63.1 "handwriting / canvas / OCR" concept was **cancelled and rolled back**. This spec explicitly does **NOT**:

- reintroduce a handwriting/drawing canvas (single- or multi-page),
- add OCR, AI, or handwriting-to-text conversion,
- mutate, migrate, backfill, or normalize existing odontogram data,
- change odontogram data-entry UX or finalization gating,
- add any external PDF/image binary dependency,
- expose KTP/NIK,
- touch payment/receivable/consent logic,
- add HR scope,
- deploy.

The objective is purely **presentation**: convert the structured `tooth_map_payload` already stored in `trx_odontograms` into a Daengtisia-style print/PDF template.

---

## 1. Current odontogram data structure

**Table:** `trx_odontograms` (model `App\Modules\Odontogram\Models\Odontogram`).

Relevant columns:

| Column | Type | Notes |
|---|---|---|
| `clinic_visit_id` | FK | one odontogram per visit (`ClinicVisit::odontogram`) |
| `branch_id` | FK | RME-branch scoped |
| `medical_record_id` | FK (nullable) | links to the visit's RM |
| `status` | string | `draft` / `finalized` (kept for back-compat; Sprint 59 removed the edit-lock) |
| `summary_notes` | text (nullable) | legacy free-text, hidden from doctor UI since Sprint 60.2, still printed |
| `additional_conditions` | text (nullable) | legacy free-text, same treatment |
| `tooth_map_payload` | **json (cast `array`)** | **single source of truth for structured per-tooth data** |
| `finalized_at` / `finalized_by` | datetime / FK | back-compat only |

**`tooth_map_payload` shape** (as written by `OdontogramService::updatePlaceholder()` and the `odontogramEditor` Alpine table editor, Sprint 59):

```jsonc
{
  "teeth": {
    "16": {
      "status": "caries",              // primary per-tooth condition (drives DMF-T + visual color)
      "conditions": ["mobility"],      // legacy Sprint 20 clinical signs (array, de-duplicated)
      "note": "free text",             // legacy per-tooth note
      "additional_condition": "...",   // free-text diagnosis addendum
      "additional_note": "...",        // free-text → used as PERAWATAN
      "dokter": "drg. Nama"            // doctor name for the row
    },
    "21": { "status": "filling" }
    // keys are FDI tooth-number strings; only "touched" teeth are present
  }
}
```

Notes:
- Keys are **FDI tooth numbers as strings**. Only teeth the doctor touched are stored (sparse map).
- `status` is the canonical condition. Recognized values: `normal`, `caries`, `missing`, `filling`, `crown`, `root_treated`.
- `conditions[]`, `note`, `additional_condition`, `additional_note`, `dokter` are optional per-tooth fields.
- **No new fields are required for this sprint.** The print template consumes this shape read-only.

---

## 2. Current odontogram print / PDF behavior

There are **two** existing print surfaces, and they are **inconsistent** — this is the core problem Sprint 63.1 fixes.

### 2a. Standalone "Cetak Odontogram" — already Daengtisia-styled
- Route: `rme.odontograms.print` → `OdontogramController@print` → view `resources/views/rme/visits/odontogram/print.blade.php`.
- Output: browser HTML page with `window.print()` (no server PDF).
- **Already renders the full Daengtisia layout:** clinic header + logo, `ODONTOGRAM GIGI PASIEN <BRANCH>` title, `Nama / No. RM` identity box, **FDI visual tooth grid** (permanent Q1–Q4 + primary Q5–Q8, center→outer), **Jumlah D / M / F / DMF-T** summary, **legend**, and the **GIGI | DIAGNOSA | PERAWATAN | DOKTER** table with a repeating header row (`thead { display: table-header-group }`) for multi-page continuation.
- **Built entirely with CSS flexbox** (`display:flex` / `inline-flex`) for the tooth grid — fine for **browser** print, problematic for **dompdf** (see §13).

### 2b. Combined "Cetak Rekam Medis" bundle — table only, NO visual
- Routes: `rme.visits.print` → `ClinicVisitController@print` (browser, `print.blade.php`) and `rme.visits.pdf` → `ClinicVisitController@pdf` (dompdf via `barryvdh/laravel-dompdf`, `print-pdf.blade.php`).
- Both include `resources/views/rme/visits/partials/print-body.blade.php`, which renders the Odontogram section via `resources/views/rme/visits/partials/odontogram-selected-results.blade.php`.
- That partial renders **only** the `GIGI | DIAGNOSA | PERAWATAN | DOKTER` table + legacy notes. It does **NOT** render the visual tooth diagram, the DMF-T summary, or the legend.
- `resolvePrintViewData()` already eager-loads `odontogram` on the visit.

### 2c. Logic duplication (the maintainability problem)
The table-row construction logic (status labels, condition labels, `markedTeeth` filter, `ksort`, diagnosa concatenation) is **copy-pasted** in both `odontogram/print.blade.php` and `odontogram-selected-results.blade.php`. DMF-T is centralized in the model, but tooth-map building and row building are not. There is **no formatter/service** — all presentation logic lives inline in Blade.

### 2d. DMF-T (already centralized, already standardized)
`Odontogram::dmftCounts()` + `Odontogram::DMF_MAP` already exist (Hotfix Sprint 60.3):

```php
const DMF_MAP = [
  'caries' => 'D',
  'missing' => 'M',
  'filling' => 'F', 'crown' => 'F', 'root_treated' => 'F',
];
```
`normal` and untouched teeth are excluded; `DMFT = D + M + F`. This is the correct, ready mapping — Sprint 63.1 **reuses it as-is** (see §6).

---

## 3. Proposed behavior (overview)

Convert the structured odontogram presentation into a **single, reusable, dompdf-safe structured print template**, fed by **one formatter service**, used consistently by both the standalone print and the combined bundle.

1. **New formatter service** `OdontogramPrintFormatter` (a.k.a. `OdontogramPrintViewDataService`) builds an immutable view-model array from an `Odontogram`: tooth grid rows, DMF-T (delegating to `dmftCounts()`), legend, and ordered table rows. De-duplicates the two Blade copies.
2. **New shared partial** `resources/views/rme/visits/odontogram/partials/structured-print-template.blade.php` renders the full Daengtisia structured layout (header optional/parameterized, visual, DMF-T, legend, table) from the formatter output.
3. **dompdf-safe visual:** the tooth grid is re-expressed as an HTML `<table>` layout (not flexbox) so it renders identically in browser print **and** dompdf.
4. **Standalone print** (`odontogram/print.blade.php`) is refactored to consume the formatter + shared partial (no visible output change for users).
5. **Combined bundle** (`print-body.blade.php`) optionally includes the visual + DMF-T + legend (currently table-only) by reusing the same partial in a "compact / embedded" mode — gated so the bundle stays clean.
6. **Continuation page** is handled by CSS pagination rules (repeating `thead`, `page-break-inside: avoid` per row, page-break before the continuation table) — no manual row-count math required, but the formatter MAY expose a configurable first-page row budget for explicit page breaks if visual fidelity needs it (see §7–§8).
7. **On-demand only** — no migration, no stored image, no new columns.

---

## 4. Data-to-visual mapping (tooth diagram)

**Source:** `tooth_map_payload.teeth[<FDI>].status`.

**Target:** FDI quadrant grid, center→outer ordering (already used in `odontogram/print.blade.php`):

```
Upper right (Q1): 18 17 16 15 14 13 12 11 | Upper left (Q2): 21 22 23 24 25 26 27 28
Primary UR (Q5):  55 54 53 52 51           | Primary UL (Q6): 61 62 63 64 65
------------------------------- midline -------------------------------
Primary LR (Q8):  85 84 83 82 81           | Primary LL (Q7): 71 72 73 74 75
Lower right (Q4): 48 47 46 45 44 43 42 41 | Lower left (Q3): 31 32 33 34 35 36 37 38
```

**Per-cell rendering:** each cell shows the FDI number and is color-coded by `status`:

| status | class | meaning |
|---|---|---|
| (none / untouched) | `t-default` | not assessed |
| `normal` | `t-normal` | sound |
| `caries` | `t-caries` | D |
| `missing` | `t-missing` | M |
| `filling` | `t-filling` | F |
| `crown` | `t-crown` | F |
| `root_treated` | `t-root_treated` | F (PSA) |

**Rules:**
- The visual is **auto-generated from saved data only** — no drawing, no canvas.
- Untouched teeth always render as `t-default` (never blank the grid).
- Unknown/legacy status values fall back to `t-default` and are excluded from DMF-T (see §6) — never crash, never inflate counts.
- The formatter returns the four jaw rows as plain arrays of `{ number, status, css_class }` so the Blade partial stays logic-free.

---

## 5. Data-to-table mapping (GIGI | DIAGNOSA | PERAWATAN | DOKTER)

**Selected rows** = teeth with any meaningful entry (matches the existing `markedTeeth` filter):
`status` set **OR** non-empty `conditions[]` **OR** `note` **OR** `additional_condition` **OR** `additional_note` **OR** `dokter`.

Rows are `ksort`ed with `SORT_NATURAL` (ascending FDI).

| Column | Source | Build rule |
|---|---|---|
| **GIGI** | tooth-map key | FDI number |
| **DIAGNOSA** | `status` + `additional_condition` + `conditions[]` + `note` | human labels joined with ` — ` (status label → additional_condition → legacy condition labels → note) |
| **PERAWATAN** | `additional_note` | free-text; `—` when empty |
| **DOKTER** | `dokter` | free-text; `—` when empty |

Status/condition label maps (already in both blades) are centralized in the formatter:
`caries→Karies, missing→Hilang, filling→Tambalan, crown→Crown, root_treated→PSA, normal→Normal`; legacy signs `mobility→Goyang, impaction→Impaksi`.

### One-row-per-tooth vs one-row-per-finding — **recommendation: grouped by tooth (one row per tooth)**
- **Recommended:** **one row per tooth**, combining all findings into the DIAGNOSA cell (joined with ` — `). This is exactly what both current blades do, matches the Daengtisia paper form (GIGI column = a single tooth), avoids row-count explosion, and keeps continuation pagination predictable.
- **Rejected:** one-row-per-finding would split a single tooth across multiple GIGI rows, diverge from the paper template, and complicate page-break budgeting.
- The data model stores a **single `status` per tooth** plus addenda, so there is no true multi-finding array to explode anyway — grouped-by-tooth is both safest and the natural fit.

---

## 6. DMF-T calculation recommendation

**Reuse `Odontogram::dmftCounts()` + `Odontogram::DMF_MAP` unchanged.** The mapping is already standardized and matches the Daengtisia legend:

- **D (Decay)** = `caries`
- **M (Missing)** = `missing`
- **F (Filling/restoration)** = `filling`, `crown`, `root_treated`
- **DMF-T** = D + M + F
- `normal`, untouched teeth, and any unrecognized status → **excluded** (do not inflate DMF-T).

The formatter delegates to `dmftCounts()` (no recomputation, no duplicated mapping). **No change to `DMF_MAP` is proposed.** If a future status value appears that is restorative/decay/missing in nature, it is added to `DMF_MAP` in one place — out of scope for this sprint.

> Decision: because `DMF_MAP` already exists and is correct, **no new "safe mapping table" needs to be invented** — the requested fallback ("if unknown, show as non-DMF condition and do not inflate DMF-T") is already the model's behavior (`?? null` → skipped).

---

## 7. Page 1 layout design

Page 1 (matches the Daengtisia PDF page 1), top→bottom:

1. **Clinic header** — Daengtisia logo + `KLINIK GIGI DAENGTISIA` + address/contact meta (reuse `<x-brand.daengtisia-logo>`).
2. **Document title** — `ODONTOGRAM GIGI PASIEN <BRANCH>`.
3. **Identity box** — `Nama : <patient name>` | `No. RM : <medical_record_number>`. **No KTP/NIK.**
4. **Visual tooth diagram** — FDI grid (§4), rendered as an HTML table (§9/§13).
5. **DMF-T summary** — `Jumlah D / M / F / DMF-T` (§6).
6. **Legend** — D=Decay, M=Missing, F=Filling, Crown, PSA.
7. **Table header + first batch of rows** — `GIGI | DIAGNOSA | PERAWATAN | DOKTER`.

The table is allowed to begin on page 1 and **flow naturally** onto page 2+ (the header row repeats). A configurable **first-page row budget** constant (e.g. `FIRST_PAGE_ROW_BUDGET`) MAY be exposed by the formatter to force an explicit page break after the visual block if testing shows dompdf overlapping the visual and the first rows; default behavior is natural CSS flow.

---

## 8. Page 2+ continuation layout design

Page 2 onward (matches Daengtisia PDF page 2):

- **Only the table continues** — no repeat of the visual diagram, DMF-T, or legend.
- The table `<thead>` (`display: table-header-group`) **auto-repeats** the column headers (and optionally the `Nama / No. RM` repeat-title row already present in `odontogram/print.blade.php`) at the top of each continuation page.
- Each `<tr>` uses `page-break-inside: avoid` so a row is never split across a page boundary.
- No fixed row cap is hard-coded; the browser/dompdf paginator overflows naturally. The optional `FIRST_PAGE_ROW_BUDGET` only governs the page-1/page-2 split point, not a maximum.
- Legacy `Kondisi Tambahan` / `Catatan Odontogram` notes render after the final table page (as today).

---

## 9. Blade/HTML/SVG vs generated image — recommendation

**Recommendation: Blade + HTML `<table>` + CSS. No SVG image, no raster image, no headless browser.**

- The diagram is a simple **labelled grid**, perfectly expressible as an HTML `<table>` with colored `<td>` cells — fully supported by **both** browser print and **dompdf**.
- **Avoid CSS flexbox for the grid in any dompdf-rendered surface** — dompdf's flexbox support is incomplete and will misrender the current `display:flex` grid. Convert the grid to table-cell layout (this is the single most important conversion in this sprint).
- **SVG:** dompdf's SVG support (via `php-svg-lib`) is limited and brittle for a 32+ cell labelled grid; **not recommended**. HTML table is simpler and more reliable.
- **Generated image (PNG/JPG):** rejected — would require a rendering engine (headless Chrome / Imagick), i.e. a new heavy dependency, plus storage, plus a privacy surface. Explicitly out of scope.

---

## 10. Migration recommendation

**No migration.** All required data already exists in `trx_odontograms.tooth_map_payload`, `status`, and the patient/visit/branch relations. DMF-T is derived. No new column, table, index, or FK.

---

## 11. Stored vs on-demand output

**Generate on demand on every print/PDF request.** Do **not** persist any rendered HTML, PDF, or image.

- Rationale: the formatter is cheap (sparse map, ≤ a few dozen teeth), output must always reflect the latest editable data (Sprint 59 allows post-finalization edits), and storing a rendered artifact would create a stale-copy + privacy-retention surface for no benefit.
- The existing `rme.visits.pdf` route already streams a dompdf download without persistence — keep that model.

---

## 12. Privacy / security rules

- **Never render or export KTP/NIK**, scanned KTP documents, or raw medical-note scans in the odontogram template. Only `patient.name` and `patient.medical_record_number` appear in the identity box.
- Authorization unchanged: standalone print stays behind `OdontogramPolicy@print`; bundle stays behind `ClinicVisitPolicy@print`. Branch isolation via the existing RME-branch scoping is preserved — the formatter does **not** widen any query.
- No new route, no new permission. The formatter is a pure transform over an already-authorized model instance.
- Output is on-demand and not persisted (§11), so no new data-at-rest retention concern.

---

## 13. Print / PDF / dompdf compatibility

| Concern | Decision |
|---|---|
| Engine | Browser `window.print()` for HTML surfaces; existing `barryvdh/laravel-dompdf` for `rme.visits.pdf`. No new dependency. |
| **Flexbox** | **Remove from any dompdf path.** Convert the tooth grid + DMF-T strip from `display:flex` to HTML `<table>` / `inline-block` cells. This is the key compatibility fix. |
| Table header repeat | `thead { display: table-header-group; }` — supported by dompdf for cross-page header repeat. |
| Row splitting | `tr { page-break-inside: avoid; }` per row. |
| Page break between visual and continuation | `page-break-before` / CSS `@page { margin: 1cm }`; optional explicit break after the page-1 block. |
| Colors | Use background-color on `<td>` (dompdf honors simple `background`/`border`); avoid gradients/box-shadow. |
| Fonts | Keep the existing safe stack (Segoe UI/Arial/sans-serif); embed nothing new. |
| Logo | `<x-brand.daengtisia-logo>` already renders in both surfaces today; keep as-is. |

**Validation requirement:** the structured template must be rendered through **dompdf** in tests (assert HTML structure / no exception), not only the browser path, because the two engines diverge on flexbox.

---

## 14. Test plan

All tests are **feature tests** (existing RME suite conventions). No browser/JS tests required for the core logic.

**New: `tests/Feature/RME/StructuredOdontogramPrintTemplateTest.php`**
1. Formatter builds the four jaw rows with correct FDI ordering and per-cell `css_class` from `status`.
2. Formatter DMF-T equals `Odontogram::dmftCounts()` (D=caries, M=missing, F=filling/crown/root_treated; `normal`/untouched excluded).
3. Unknown/legacy status → `t-default` cell and excluded from DMF-T (no inflation, no exception).
4. Table rows: one row per touched tooth, ascending FDI, DIAGNOSA concatenation order, PERAWATAN=`additional_note`, DOKTER=`dokter`, `—` fallbacks.
5. Empty odontogram (no touched teeth) → visual all-default, DMF-T all zero, table empty-state, no error.
6. Standalone print route `rme.odontograms.print` renders the visual + DMF-T + legend + table (HTTP 200, asserts key markers).
7. Continuation: with > first-page budget of marked teeth, the table header markup repeats and all rows render (assert all FDI numbers present).
8. **dompdf path:** `rme.visits.pdf` renders the bundle including the structured odontogram block **without exception** and the response is a PDF.
9. Privacy: rendered output contains `medical_record_number` but **never** the patient KTP/NIK value (assert absence).
10. Authorization: a user lacking print permission gets 403 on both surfaces (regression).

**Regression suites to keep green:**
- `OdontogramTest`, `OdontogramSelectedResultsTableTest`, `OdontogramAdditionalFieldsTest`
- `MedicalRecordPrintOdontogramMergeTest`, `RmePdfPrintHardeningTest`, `Sprint59EditableWorkflowTest`
- `tests/Browser/RmeOdontogramSmokeTest.php`, `tests/Browser/RmePrintSmokeTest.php` (if run)
- `php artisan test tests/Feature/RME`

**Tooling gates:** `vendor/bin/pint --test`, `git diff --check`. No `npm run build` unless a JS/CSS asset actually changes (none expected).

---

## 15. Rollout plan

1. Implement formatter `OdontogramPrintFormatter` + the shared `structured-print-template.blade.php` partial (dompdf-safe table grid).
2. Refactor `odontogram/print.blade.php` to consume them (no user-visible change — pure de-duplication).
3. Optionally surface the visual + DMF-T + legend inside the combined bundle (`print-body.blade.php`) via the same partial in embedded mode.
4. Add the new test file; keep regression suites green; run `pint` + `git diff --check`.
5. Update `CLAUDE.md` with a Sprint 63.1 closure note.
6. Open PR against the base branch `feature/sprint-26-phase-26-8-...` (NOT main). No DB migration step on deploy. No `migrate:fresh`/`db:wipe` ever on VPS.

Because there is no migration and no data change, rollback is a plain revert of the view/service commit — zero data risk.

---

## 16. Risks and mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| dompdf misrenders the flexbox tooth grid | High (known dompdf limitation) | Convert grid to HTML `<table>` cells (§9, §13); assert via dompdf test (§14.8). |
| Duplicate-logic drift if only one Blade is refactored | Medium | Single formatter is the only source; both surfaces consume it. |
| Large odontogram overflows / row split across pages | Medium | `table-header-group` + `page-break-inside: avoid`; continuation test (§14.7). |
| Accidental change to data-entry or finalization | Low | Read-only formatter; no service writes; Sprint 59/60.8 gates untouched. |
| Unknown legacy `status` value crashes or inflates DMF-T | Low | `?? t-default` fallback + `DMF_MAP[...] ?? null` skip; explicit test (§14.3). |
| KTP/NIK leakage into print | Low | Identity box limited to name + RM; privacy assertion test (§14.9). |
| Visual divergence from paper form | Low | Reuse existing Daengtisia styling already validated in standalone print; diff is layout-engine compat only. |

---

## 17. GO / NO-GO criteria

**GO when all are true:**
- No migration, no schema/data change, no new permission/route, no new external dependency.
- Existing odontogram data is read-only; entry + finalization logic unchanged.
- Standalone print renders identically to today (or better) and the bundle's dompdf path renders the structured block **without exception**.
- DMF-T equals `Odontogram::dmftCounts()`; `normal`/untouched/unknown excluded.
- Continuation pagination verified (header repeats, no split rows).
- No KTP/NIK in output.
- New test file + all listed regression suites green; `pint --test` + `git diff --check` clean.

**NO-GO if any of:**
- Any handwriting/canvas/OCR/AI element reintroduced.
- A migration, stored rendered artifact, or external PDF/image binary becomes "necessary."
- Flexbox left in the dompdf path (PDF visual broken).
- Any payment/receivable/consent/finalization behavior changes.
- KTP/NIK appears in any rendered/exported output.

---

## 18. Recommended coding-phase prompt

> Implement Sprint 63.1 — Structured Odontogram Print Template Conversion, per `docs/sprint_63_1_structured_odontogram_print_template_conversion_spec.md`. Base branch `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main).
>
> Render the already-saved `trx_odontograms.tooth_map_payload` into the Daengtisia print/PDF layout. **No migration. No data mutation. No handwriting/canvas/OCR/AI. No new external dependency. No payment/receivable/consent/finalization changes. Never render KTP/NIK.**
>
> 1. Add `App\Modules\Odontogram\Services\OdontogramPrintFormatter` (pure, read-only): builds the FDI visual jaw rows (`{number,status,css_class}`, center→outer order), delegates DMF-T to `Odontogram::dmftCounts()`, and builds ascending one-row-per-tooth table rows (GIGI/DIAGNOSA/PERAWATAN/DOKTER) using the centralized status/condition label maps. Expose an optional `FIRST_PAGE_ROW_BUDGET`.
> 2. Add `resources/views/rme/visits/odontogram/partials/structured-print-template.blade.php` rendering header(optional)/visual/DMF-T/legend/table from the formatter. **Use HTML `<table>` cells for the tooth grid — no flexbox (dompdf-safe).** Continuation via `thead{display:table-header-group}` + `tr{page-break-inside:avoid}`.
> 3. Refactor `resources/views/rme/visits/odontogram/print.blade.php` to consume the formatter + partial (no visible change; remove duplicated inline logic).
> 4. Optionally include the visual+DMF-T+legend in `resources/views/rme/visits/partials/print-body.blade.php` via the same partial (embedded mode), keeping `odontogram-selected-results.blade.php` table behavior intact.
> 5. Add `tests/Feature/RME/StructuredOdontogramPrintTemplateTest.php` covering visual mapping, DMF-T parity, unknown-status fallback, table rows, empty state, standalone route, continuation, **dompdf bundle renders without exception**, KTP/NIK absence, and authorization 403.
> 6. Keep green: `OdontogramTest`, `OdontogramSelectedResultsTableTest`, `OdontogramAdditionalFieldsTest`, `MedicalRecordPrintOdontogramMergeTest`, `RmePdfPrintHardeningTest`, `Sprint59EditableWorkflowTest`, `php artisan test tests/Feature/RME`. Run `vendor/bin/pint --test` and `git diff --check`. Then `graphify update .` and add a Sprint 63.1 closure note to `CLAUDE.md`.
