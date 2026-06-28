# Sprint 64.0 — Patient-Centric RM Workspace with Swipeable Sheets (DESIGN SPEC ONLY)

> **Status:** IMPLEMENTED (pending review) — Sprint 64.0.
> Branch `feature/sprint-64-0-patient-centric-rm-workspace-swipeable-sheets`. No PR/merge/tag/deploy yet.
> **Base branch (do NOT target main):** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
> **Latest GO HEAD:** `5b60450` · **GO tag:** `sprint-63-1-1-odontogram-saved-result-table-preview-go`
> **Date:** 2026-06-28
>
> **Implementation note:** the canonical anchor is the earliest non-cancelled RME-branch visit **that
> already has a medical record** (so the canonical URL always renders a real sheet). Each per-visit
> `MedicalRecord` is a "sheet"; `UNIQUE(clinic_visit_id)` preserved (1 sheet/visit). Migration
> `2026_06_28_100001_add_workspace_columns_to_trx_medical_records_table`. New
> `PatientRmWorkspaceResolver` + `rm-sheet-nav` partial. Tests:
> `tests/Feature/RME/PatientCentricRmWorkspaceTest.php` (17). See `docs/sprint_history.md` §Sprint 64.0.

---

## 0. Files inspected (LIMIT SAVER scope)

**MedicalRecord module**
- `app/Modules/MedicalRecord/Models/MedicalRecord.php`
- `app/Modules/MedicalRecord/Models/MedicalRecordHandwriting.php` (referenced)
- `app/Modules/MedicalRecord/Models/MedicalRecordHandwritingPage.php` (referenced)
- `app/Modules/MedicalRecord/Services/MedicalRecordService.php`
- `app/Modules/MedicalRecord/Controllers/MedicalRecordController.php`
- `app/Modules/MedicalRecord/Controllers/MedicalRecordHandwritingController.php` (referenced)
- `app/Modules/MedicalRecord/Policies/MedicalRecordPolicy.php`
- `app/Modules/MedicalRecord/Repositories/MedicalRecordRepository.php` (referenced)
- `app/Modules/MedicalRecord/Interfaces/MedicalRecordRepositoryInterface.php` (referenced)

**ClinicVisit module**
- `app/Modules/ClinicVisit/Models/ClinicVisit.php`
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php`
- `app/Modules/ClinicVisit/Repositories/ClinicVisitRepository.php`
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php` (print resolver)

**Migrations**
- `database/migrations/2026_06_10_100001_create_trx_medical_records_table.php`
- `database/migrations/2026_06_25_210001_create_trx_medical_record_handwriting_pages_table.php`
- `database/migrations/2026_06_13_200002_create_trx_medical_record_handwritings_table.php` (referenced)

**Routes / Views**
- `routes/web.php` (`rme.*` group)
- `resources/views/rme/visits/show.blade.php` (RM/Odontogram buttons)
- `resources/views/rme/visits/medical-record/show.blade.php`, `index.blade.php`
- `resources/views/rme/visits/partials/print-body.blade.php`, `print.blade.php`, `print-pdf.blade.php`

**Tests (read for regression surface)**
- `tests/Feature/RME/Sprint59EditableWorkflowTest.php`, `MedicalRecord*`, `ClinicVisit*`, print/merge/pdf regression suites.

---

## 1. Current implementation summary

### 1.1 Data structure (`trx_medical_records`)
- **Visit-centric.** Primary key per *visit*, with **`clinic_visit_id` declared `UNIQUE`** → **at most one MedicalRecord per visit.**
- Columns: `id, clinic_visit_id (UNIQUE FK), branch_id (FK), patient_id (FK), doctor_id (FK), subjective, objective, assessment, plan, notes, status('draft'|'final'), recorded_by, finalized_at, finalized_by, timestamps, softDeletes`.
- `patient_id` is **already present** as a column + index → patient-level linkage *exists at the column level*, but there is **no patient-level "workspace" aggregation** anywhere in the app. The UI always reaches a record through its single visit.

### 1.2 Handwriting pages (Sprint 60)
- A MedicalRecord already has **multiple canvas pages**:
  - Page 1 = legacy `trx_medical_record_handwritings` (read-through).
  - Page 2+ = `trx_medical_record_handwriting_pages` with `UNIQUE(medical_record_id, page_number)`.
- `MedicalRecord::orderedHandwritingPages()` returns the unified ordered page list.
- The RM `show` page already paginates **one canvas page at a time** via `?rm_page=` + a flashed `focus_rm_page`.
- **Important:** these "pages" are *within one visit's* MedicalRecord. They are **NOT** the patient-level "sheets" this sprint introduces.

### 1.3 Create / finalize flow
- `MedicalRecordController@store` → `MedicalRecordService::createDraft($visit)`:
  - Rejects non-RME-branch visits.
  - **Guards `findByVisitId($visit->id)` uniqueness** ("Rekam medis sudah ada untuk kunjungan ini.").
  - Copies `branch_id/patient_id/doctor_id` from the visit; status `draft`.
- `finalize()` requires handwriting; on `in_progress` visit it transitions visit → `cashier_pending` (the doctor→cashier gate, Sprint 62.1).
- `updateDraft()` (Sprint 59) is editable-after-final; writes only submitted keys (partial save safe).

### 1.4 Routes (all keyed on `{clinicVisit}`)
| Name | Verb | Path |
|---|---|---|
| `rme.visits.medical-record.show` | GET | `visits/{clinicVisit}/medical-record` |
| `rme.visits.medical-record.store` | POST | `visits/{clinicVisit}/medical-record` |
| `rme.visits.medical-record.update` | PATCH | `visits/{clinicVisit}/medical-record/{medicalRecord}` |
| `rme.visits.medical-record.finalize` | POST | `.../{medicalRecord}/finalize` |
| `rme.visits.medical-record.handwriting.store` | POST | `.../{medicalRecord}/handwriting` |
| `rme.medical-records.index` | GET | `medical-records` |
| `rme.visits.print` / `rme.visits.pdf` | GET | `visits/{clinicVisit}/print` · `/pdf` |

RM show/store/finalize/handwriting + odontogram are gated behind the `visit.room` middleware (Sprint 60.8).

### 1.5 RM entry buttons (today)
- `rme/visits/show.blade.php`: **"Lihat Rekam Medis"** → `medical-record.show($visit)`; **"Buat Rekam Medis"** → `medical-record.store($visit)`; **"Buka Odontogram"** → `odontogram.show($visit)`.
- `rme/visits/medical-record/index.blade.php`: **"Lihat"** → `medical-record.show($record->clinicVisit)`.
- Prev/next arrow nav (`adjacentVisits`, requireMedicalRecord) already walks a patient's chronological visits *that have an MR* — a precedent for patient-scoped traversal.

### 1.6 Policy / permission
- `MedicalRecordPolicy`: view = `view_clinic_visits|manage_clinic_visits`; manage/update/finalize = `manage_clinic_visits`; **branch isolation** via `BranchService::rmeEnabledIds()` (MAIN excluded).

### 1.7 Print / PDF
- `ClinicVisitController::resolvePrintViewData($visit)` eager-loads `medicalRecord.handwriting` for **one visit only**. Print/PDF is per-visit; there is no patient-level RM aggregation in print.

---

## 2. Proposed patient-centric behavior

**Concept:** the doctor experiences **one RM book per patient** (a *workspace*), containing **many RM sheets** (swipeable). Each sheet is the clinical record contributed by one visit. Opening RM from *any* visit lands on the same patient workspace; "Tambah Lembar RM" attaches a new sheet to the visit the doctor came from.

**Business rules (locked):**
1. **1 patient = 1 RM workspace.**
2. **1 workspace = many RM sheets.**
3. **1 sheet ↔ at most 1 source visit** (MVP keeps the existing `UNIQUE(clinic_visit_id)`; a visit contributes ≤1 sheet — see §8).
4. Every RM button from any visit **redirects to the patient workspace** (canonical), carrying `source_visit_id`.
5. If a `source_visit_id` is present, **"Tambah Lembar RM" defaults to that visit.**
6. **Visit counting/filtering/reporting stays on `trx_clinic_visits`** — never on RM sheets.

---

## 3. Canonical workspace resolution rule

Add `MedicalRecordWorkspaceResolver` (service) — pure read, no writes.

```
resolveCanonicalVisit(Patient $patient, branchScope = rmeEnabledIds()):
  candidates = visits where patient_id = patient.id
               AND branch_id IN branchScope
               AND status != 'cancelled'
  order by (visit_date ASC, id ASC)
  return first candidate  // the anchor / canonical visit
```

- **Q6 — first vs first-finalized vs earliest active?** → **Earliest non-cancelled RME-branch visit (by `visit_date,id`).** Rationale: matches the existing `adjacentVisitsForPatient` ordering; does not require an MR or a finalized record to exist (so the workspace opens even on a brand-new patient's first visit). The *anchor* defines the canonical URL; the *sheets* are all MedicalRecords for the patient in scope.
- **Q7 — cancelled first visit?** → Cancelled visits are **excluded from anchor selection** but their MR sheet (if any) is still *listed read-only* inside the workspace flagged "Kunjungan dibatalkan". If the only visit is cancelled, the anchor falls back to the most recent visit so a route can still be built; the workspace simply shows the cancelled sheet read-only.
- **Q8 — first visit in another branch?** → **Branch isolation wins over patient-centricity.** Anchor + sheet list are restricted to the RME branches the current user can access (`rmeEnabledIds()`, MAIN excluded). A patient with visits in two RME branches yields, per accessing user, only the sheets/anchor in branches they may view. (No cross-branch leakage; matches `MedicalRecordPolicy::belongsToActiveBranch`.) A note is shown: "Sebagian lembar dari cabang lain tidak ditampilkan."
- The resolver is **deterministic and idempotent**: same patient + same branch scope → same anchor.

Optionally persisted (see §8): cache the resolved anchor in `canonical_visit_id` on each sheet for audit + faster reads; the resolver remains the source of truth and self-heals if the cached value points to a now-cancelled/cross-branch visit.

---

## 4. Redirect behavior (all RM buttons → canonical workspace)

New route (workspace is patient-addressed but reuses the canonical visit param for minimal blast radius):

```
GET rme.visits.medical-record.show  (UNCHANGED route name/signature)
   → controller resolves canonical visit for $clinicVisit->patient
   → if $clinicVisit->id != canonicalVisit->id:
         redirect 302 → medical-record.show(canonicalVisit) with ?source_visit_id={original}
     else render workspace, source_visit_id from query (default = canonical)
```

- **No new route is strictly required** — the redirect is implemented *inside* the existing `show()` controller. This keeps every existing button/link working unchanged; they just get bounced to the canonical visit.
- The redirect **preserves `source_visit_id`** in the query string. On arrival, the workspace reads `?source_visit_id`, validates it belongs to the same patient + accessible branch (else drops it), and uses it to pre-target "Tambah Lembar RM".
- `store`, `update`, `finalize`, `handwriting.store` continue to operate on a concrete `{clinicVisit}`/`{medicalRecord}` (a *sheet*), so the doctor→cashier gate, room gate, finalize→`cashier_pending`, and odontogram remain byte-for-byte intact.
- Odontogram button is **left as-is** (per-visit) for this sprint — out of scope, no regression.

**Flow graph (graphify-style):**
```
[Any visit RM button]
      │
      ▼
[medical-record.show($visit)]
      │  resolveCanonicalVisit(patient, rmeEnabledIds)
      ▼
[$visit == canonical?] ── no ──► [302 → show(canonical) ?source_visit_id=$visit]
      │ yes                                   │
      ▼                                       ▼
[Render Patient RM Workspace] ◄───────────────┘
      │  list sheets = MedicalRecords(patient, branchScope) ordered by sheet ordinal
      ▼
[Swipeable sheets: prev/next/tabs/swipe]  +  [Notice if opened from later visit]
      │
      ▼
[Tambah Lembar RM] ── defaults to source_visit_id ──► [store(sourceVisit)] (1 sheet/visit)
      │
      ▼
[Visit count/reporting still reads trx_clinic_visits]   [Print/PDF reads patient sheets]
```

---

## 5. Add-sheet behavior from later visits

- "Tambah Lembar RM" posts to the **existing** `rme.visits.medical-record.store` with the **target = `source_visit_id`** (the visit the doctor came from), not the canonical visit.
- Because `UNIQUE(clinic_visit_id)` holds, a visit can own **one** sheet. Behaviour:
  - If `source_visit` has **no** MR yet → create it (a new sheet appears in the workspace, audit fields stamped with the source visit).
  - If `source_visit` **already** has an MR → "Tambah Lembar RM" is disabled for that visit; the existing sheet is selected instead, and the doctor adds **canvas pages** within it (Sprint 60 `rm_page`). The UI copy clarifies: "Kunjungan ini sudah punya lembar; gunakan halaman kanvas untuk menambah lembar tulisan."
- Audit fields written on the new sheet: `patient_id, canonical_visit_id, source_visit_id (= clinic_visit_id), doctor_id, branch_id, sheet_number, recorded_by(created_by), created_at` — all already derivable from the source visit (see §8).
- The new sheet's `branch_id` = **source visit's branch** (validated against `rmeEnabledIds()`), never the canonical visit's branch — preserving per-sheet branch audit.

---

## 6. Swipe / refresh UX design

**Sheet navigation (workspace page):**
- **Swipe** left/right (tablet/mobile) via a lightweight Alpine + pointer/touch handler (no new JS dependency; reuse `resources/js/app.js` patterns). Threshold-based horizontal swipe → prev/next sheet.
- **Prev/Next buttons** (desktop), mirroring the existing visit-nav-arrows partial style.
- **Sheet tabs / dropdown**: tabs for ≤ N sheets, collapse to a `<select>` dropdown when many.
- **"Tambah Lembar RM"** button (gated by `manage_clinic_visits` + source-visit eligibility).
- Each sheet itself keeps the **Sprint 60 canvas-page pagination** (`?rm_page=`) for multi-canvas writing — two-level: **sheet (visit)** → **canvas page**.

**Opened-from-later-visit notice:**
> "Anda membuka RM pasien dari Kunjungan #{X} ({tanggal}). Lembar baru akan ditautkan ke kunjungan ini."

Shown only when `source_visit_id` differs from the canonical visit.

**Refresh / persistence (Q15):**
- Active sheet persisted in the **URL query** (`?sheet={visitId}` / reuse `?rm_page=`) as the primary, shareable, server-safe state — survives full refresh and back/forward.
- **`sessionStorage`** (key `rmws:{patientId}:activeSheet` and `:scrollTop`) as a secondary enhancer to restore scroll position and the last sheet when navigating in without an explicit query param. `localStorage` avoided to prevent stale cross-day state; `sessionStorage` clears on tab close.
- Scroll restore is **best-effort** (`scrollIntoView`/`scrollTop` on mount), never blocking render.
- **Read-only mode** for users with view-but-not-manage: navigation works, but "Tambah Lembar RM", canvas edit, finalize, and save are hidden/disabled (server-enforced by policy regardless of UI).

---

## 7. Print / PDF plan

- **Per-visit print stays the default and unchanged** (`rme.visits.print` / `.pdf`) — no regression to Sprint 63.1 odontogram print or existing print tests.
- Add an **opt-in patient-workspace print** that aggregates all the patient's in-scope RM sheets (each sheet → its canvas pages) into one document:
  - New view-model method on the workspace resolver: `sheetsForPrint(patient, branchScope)` returning ordered sheets + their `orderedHandwritingPages()`.
  - Reuse existing dompdf-safe partials (`print-body`, Sprint 63.1 structured odontogram partial) per sheet; insert `page-break-before` between sheets; `thead{display:table-header-group}` continuation already established.
  - **KTP/NIK never rendered** (continues current behaviour).
- MVP may ship patient-print as a follow-on toggle; the per-visit print path must remain the regression-safe baseline.

---

## 8. Data model recommendation

### Options evaluated
- **Option A — new tables** (`trx_patient_medical_record_workspaces` + `trx_patient_medical_record_sheets`): cleanest conceptually, but **migrates clinical data into a parallel table**, forces dual-write/migration of every existing MR, and risks breaking `findByVisitId`, print, cashier finalize, odontogram coupling, and the entire MedicalRecord test suite. **High blast radius. Rejected for MVP.**
- **Option B — extend current table.** `trx_medical_records` *is already* the per-sheet table (it has `patient_id, doctor_id, branch_id, clinic_visit_id`). Treat **each MedicalRecord row as one sheet**; the "workspace" is a **virtual aggregation** (patient + branch scope) computed by the resolver. Add only thin, **nullable, additive** audit/index columns.

### ✅ Recommended: **Option B (additive columns, virtual workspace, one-sheet-per-visit)**

New migration (additive only — `migrate` never `migrate:fresh`/`db:wipe`), on `trx_medical_records`:
| Column | Type | Purpose |
|---|---|---|
| `canonical_visit_id` | nullable FK → `trx_clinic_visits`, indexed | cached workspace anchor (resolver is source of truth; self-heals) |
| `source_visit_id` | nullable FK → `trx_clinic_visits`, indexed | audit copy of the visit the sheet was created from (= `clinic_visit_id` under one-sheet-per-visit) |
| `sheet_number` | nullable unsigned int | per-patient sheet ordinal (display/audit; computed on create) |
| `created_by` | reuse existing `recorded_by` | already present — no new column |

- **Keep `UNIQUE(clinic_visit_id)`** → one sheet per visit; least breaking; `findByVisitId`, `createDraft` guard, finalize→cashier all unchanged.
- Existing `patient_id/doctor_id/branch_id/finalized_by/finalized_at/status/softDeletes` reused verbatim.
- New columns are **nullable + backfillable** — old rows function untouched (resolver derives anchor at runtime when `canonical_visit_id` is null).
- **Workspace = virtual** (no workspace table) → nothing to keep in sync, no orphan rows, trivially reversible.

**If a future phase needs >1 sheet per visit:** drop `UNIQUE(clinic_visit_id)` and add `UNIQUE(clinic_visit_id, sheet_number)` — deferred (Phase 64.x), out of MVP scope. Flagged in Risks.

---

## 9. Migration plan

1. **One additive migration**: add nullable `canonical_visit_id`, `source_visit_id`, `sheet_number` + indexes to `trx_medical_records`. No drops, no NOT NULL, no data deletion.
2. **No backfill required for MVP** — resolver computes anchor at runtime when columns are null (mirrors Sprint 60 "do not backfill legacy Page 1"). Optional lazy backfill: on next save of a sheet, stamp `canonical_visit_id/source_visit_id/sheet_number`.
3. Optional **idempotent backfill command** (`rme:backfill-rm-workspace`, dry-run default) for reporting/print consistency — not on the deploy critical path.
4. **VPS rules unchanged:** backup DB before pull/migrate; `php artisan migrate --force` only; **never** `migrate:fresh`/`db:wipe`; reset `storage`/`bootstrap/cache` permissions after deploy.

---

## 10. Backward compatibility plan

- All existing routes/buttons keep working — the redirect lives inside `show()`; old links resolve to the canonical workspace transparently.
- `clinic_visit_id` uniqueness, `findByVisitId`, `createDraft`/`updateDraft`/`finalize`, the doctor→cashier gate (Sprint 62.1), room gate (60.8), full-payment/partial rules, consent gate, SOAP-hidden, odontogram, and per-visit print are **untouched**.
- Sprint 60 canvas pages keep working inside each sheet.
- New columns nullable → existing MR rows and the entire MedicalRecord test suite remain valid.

---

## 11. Permission / policy plan

- Reuse `MedicalRecordPolicy`: view = `view_clinic_visits|manage_clinic_visits`; manage (create sheet / edit / finalize) = `manage_clinic_visits`. **No new permission.**
- Add a policy/resolver guard `viewWorkspace(User, Patient)` = `canView` + patient has ≥1 sheet/visit in `rmeEnabledIds()`.
- Read-only users → workspace renders, write actions hidden + server-denied.

---

## 12. Branch isolation plan

- Anchor selection + sheet list filtered to `BranchService::rmeEnabledIds()` (MAIN excluded), matching `belongsToActiveBranch`.
- `source_visit_id` validated to same patient + accessible RME branch before use; forged/cross-branch/other-patient ids dropped (IDOR boundary).
- New sheet inherits **source visit's branch**, re-asserted as RME-enabled before insert.
- Cross-branch sheets a user can't access are excluded from list/print (with a neutral "sebagian lembar disembunyikan" note), never leaked.

---

## 13. Privacy / security plan

- **No KTP/NIK** rendered or exported anywhere (header shows Name, RM number, branch, age/gender only — already-safe fields).
- No scanned docs, no raw note dumps beyond existing per-sheet RM rendering.
- `source_visit_id`/`canonical_visit_id` server-validated; no trust in client-supplied ids.
- No HR scope. No new external dependency.

---

## 14. Test plan

New: `tests/Feature/RME/PatientCentricRmWorkspaceTest.php`

1. Visit 1 RM button creates/opens the canonical workspace for the patient.
2. Visit 2 RM button **redirects (302)** to the same patient's canonical workspace.
3. Redirect **carries `source_visit_id`** (= visit 2) in the query.
4. "Tambah Lembar RM" from visit 2 **links the new sheet to visit 2** (`clinic_visit_id`/`source_visit_id` = visit 2, `canonical_visit_id` = visit 1).
5. **Visit count unchanged** — creating sheets does not change `trx_clinic_visits` count.
6. Existing visit filters/reports/Owner-KPI still count **visits, not sheets**.
7. Swipe / next-prev / tab changes active sheet (controller param level; JS smoke optional).
8. Refresh keeps active sheet (URL param drives server-rendered active sheet).
9. Unauthorized (view-only) user **cannot create/edit/finalize** a sheet (403 / hidden).
10. Cross-branch patient/visit **cannot be accessed**; cross-branch sheets excluded; forged `source_visit_id` dropped.
11. Cancelled first visit → excluded from anchor; its sheet shown read-only; anchor falls to next eligible.
12. Patient print/PDF includes **all in-scope sheets**; per-visit print unchanged.
13. **KTP/NIK not rendered** in workspace or print.
14. Existing **MedicalRecord** tests stay green (editable-after-final, partial save, finalize→cashier).
15. Existing **ClinicVisit / Cashier / Odontogram / RME print** suites stay green.

Quality gates: `vendor/bin/pint --test`, `git diff --check` clean. Targeted suites: `tests/Feature/RME`, `MedicalRecord`, `ClinicVisit`, `CashierBilling`, `Odontogram`, print/merge/pdf regression, `Owner` KPI.

---

## 15. Rollout plan

1. Phase 64.0a — additive migration + resolver + redirect (server) + tests.
2. Phase 64.0b — workspace view: sheet nav (buttons/tabs), notice, read-only mode.
3. Phase 64.0c — swipe + sessionStorage scroll/active-sheet persistence.
4. Phase 64.0d — optional patient-workspace print/PDF aggregation.
5. Phase 64.0e — optional idempotent backfill command.
6. Manual pilot smoke on staging → GO/NO-GO → VPS via existing checklist.

Feature can be staged behind a soft config flag (`rme.patient_workspace_redirect`) defaulting on once tests pass, off as instant rollback without revert.

---

## 16. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Redirect loop if canonical resolution is non-deterministic | Pure, ordered-by-(visit_date,id) resolver; unit-tested idempotency; redirect only when ids differ. |
| Doctor expects >1 sheet per single visit | MVP = 1 sheet/visit + multi canvas-pages within it; clear UI copy; >1/visit deferred to Phase 64.x (drop UNIQUE). |
| Cross-branch patient hides sheets unexpectedly | Explicit "sebagian lembar disembunyikan" note; documented branch-isolation-wins rule. |
| Cancelled-only patient has no valid anchor | Fallback anchor = latest visit; cancelled sheet read-only. |
| Print aggregation regresses Sprint 63.1 print | Per-visit print untouched; patient print is additive + separately tested. |
| Backfill drift between cached `canonical_visit_id` and resolver | Resolver is source of truth; cache self-heals; no NOT NULL dependency. |
| Visit reporting accidentally counts sheets | Tests assert counts read `trx_clinic_visits`; no reporting query touched. |

---

## 17. GO / NO-GO criteria

**GO when:**
- Additive migration only; `UNIQUE(clinic_visit_id)` preserved; no data deleted.
- All RM buttons from any visit land on the canonical workspace with `source_visit_id`.
- Add-sheet links to the source visit; visit counts unchanged.
- Swipe + refresh persistence work; read-only mode enforced server-side.
- KTP/NIK never rendered; branch isolation holds; no cross-patient/cross-branch access.
- New tests pass **and** MedicalRecord/ClinicVisit/Cashier/Odontogram/RME-print suites stay green; pint + `git diff --check` clean.

**NO-GO if:** any existing suite regresses, the doctor→cashier/room/consent gates change, print regresses, a migration is non-additive, or any redirect loop/IDOR is observed.

---

## 18. Recommended coding-phase prompt

> Implement Sprint 64.0 — Patient-Centric RM Workspace with Swipeable Sheets, per `docs/sprint_64_0_patient_centric_rm_workspace_swipeable_sheets_spec.md`.
> Base branch `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main). Create `feature/sprint-64-0-patient-centric-rm-workspace`.
> Use Option B: keep `trx_medical_records` as the per-sheet table and `UNIQUE(clinic_visit_id)`. Add ONE additive migration adding nullable indexed `canonical_visit_id`, `source_visit_id`, `sheet_number` to `trx_medical_records` (no drops, no backfill). `migrate` only — never `migrate:fresh`/`db:wipe`.
> Add `MedicalRecordWorkspaceResolver` (resolveCanonicalVisit by earliest non-cancelled RME-branch visit ordered by visit_date,id; branch-isolated to rmeEnabledIds(); cancelled/cross-branch fallbacks per §3). Inside `MedicalRecordController@show`, redirect to the canonical visit with `?source_visit_id` when the opened visit isn't canonical; render the workspace otherwise. Keep store/update/finalize/handwriting per-visit (one sheet/visit) and "Tambah Lembar RM" defaulting to a validated `source_visit_id`.
> Build the workspace view: swipe (Alpine pointer/touch, no new dep) + prev/next + tabs/dropdown + "opened-from-later-visit" notice + read-only mode; persist active sheet via URL param and sessionStorage scroll restore. Keep Sprint 60 canvas-page pagination within each sheet.
> Do NOT change: doctor→cashier gate (62.1), room gate (60.8), consent gate, full-payment/partial rules, odontogram, per-visit print/PDF, SOAP-hidden, KTP/NIK privacy. Patient-aggregated print is an additive opt-in.
> Add `tests/Feature/RME/PatientCentricRmWorkspaceTest.php` covering §14 items 1–15. Keep MedicalRecord/ClinicVisit/Cashier/Odontogram/RME-print suites green. Run `vendor/bin/pint --test` and `git diff --check`. No PR/merge/tag/deploy without explicit approval.

---

## 19. GO / NO-GO recommendation

**GO (proceed to coding phase)** with **Option B (additive columns + virtual workspace, one-sheet-per-visit)**. It delivers the patient-centric book + swipeable sheets + canonical redirect with **minimal schema change, zero data loss, and no change to the payment/gate/odontogram/print invariants**, and is fully reversible via a soft flag. Defer ">1 sheet per single visit" and patient-aggregated print to follow-on phases to keep the MVP regression-safe.
