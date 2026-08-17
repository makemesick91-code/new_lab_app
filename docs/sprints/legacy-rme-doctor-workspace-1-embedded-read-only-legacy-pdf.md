# LEGACY-RME-DOCTOR-WORKSPACE-1 — Embedded Read-Only Legacy PDF in the Doctor's RME Workspace

**Branch:** `feature/legacy-rme-doctor-workspace-1-embedded-read-only-legacy-pdf`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
**Type:** presentation / clinical read surface. No migration, no new route, no new permission.

---

## 1. The problem

A published legacy RME PDF was already readable — but only from the
**clinical-history card at the very bottom of the RME workspace**.

In `resources/views/rme/visits/medical-record/show.blade.php` the history
partial was included at **line 935 of a 985-line view**, below:

- the visit information card,
- the "Catatan Rekam Medis" notes area,
- the entire multi-page handwriting canvas,
- the full-screen handwriting overlay editor,
- the structured-diagnosis card,
- the relocated sheet navigation.

So a doctor who wanted the patient's old RME had to scroll past the very
surface they were writing on to discover it existed. The archive was present
but not *practically* reachable during an encounter.

## 2. What changed

The same documents are now offered as a **document rail at the top of the
workspace**, directly under the workflow navigation and above "Informasi
Kunjungan".

- Native RM sheets and published legacy documents appear side by side,
  explicitly typed and visually distinct.
- A legacy entry is badged `RME Legacy` and `Hanya Baca` — read-only stated in
  **text**, never by colour alone.
- Selecting a legacy document opens a **read-only overlay viewer** with zoom in
  / zoom out / fit-width, page navigation for multi-page archives, and a
  full-screen expand.

The pre-existing clinical-history card is **unchanged and still rendered**. This
rail is additive — a faster read surface, not a replacement.

## 3. What deliberately did NOT change

- **No data-model merge.** A legacy record is never converted into a
  `ClinicVisit` or a `MedicalRecord` so that it can be listed. No shadow native
  record, no backfill.
- **No new route.** Legacy bytes still stream from the existing
  `rme.legacy-records.source` and `rme.legacy-records.pages.show`; native sheet
  selection still uses the workspace's own `?sheet=` URL.
- **No new permission.** Reading uses the existing
  `view_legacy_rme_imports | view_legacy_rme_archive` pair.
- **No mutation path.** The archive still has exactly one state change (`void`),
  it is not a workspace action, and it keeps its own named permission.
- **No CSP change.** The application defines no `Content-Security-Policy`,
  `frame-src` or `X-Frame-Options` middleware at all, and this sprint added
  none. The overlay is same-origin, so nothing needed relaxing.
- **No new dependency.** No PDF.js, no React/Vue. Rendered page images are
  served by the existing rasterizer output; the browser's own viewer is the
  fallback.

## 4. Architecture

```
MedicalRecordController@show
        │
        ├── PatientRmWorkspaceResolver::sheetsForPatient()   (native, already authorized)
        │
        └── RmeWorkspaceDocumentPresenter::documentsFor()
                    │
                    └── LegacyRmePatientHistoryService::publishedRecordsFor()   ← CANONICAL
                                │
                                ├── feature flag
                                ├── LegacyRmeRecordPolicy::READ_PERMISSIONS
                                ├── LegacyRmeWorkspaceScope (branch)
                                └── DoctorPatientScopeService (treating relationship)
```

- `App\Modules\MedicalRecord\Support\RmeWorkspaceDocument` — a `final`
  presentation value object with an **explicit** `type` (`native` | `legacy`).
  No enum (the codebase uses `final` Support classes for vocabularies), no
  persistence, no polymorphic table.
- `App\Modules\MedicalRecord\Services\RmeWorkspaceDocumentPresenter` — builds
  the rail. The native side is **passed in** (never re-queried); the legacy side
  comes from the canonical service, so publication status, VOID filtering,
  branch scope, the feature flag and the doctor's clinical scope are **never
  reimplemented**.

### Cost

`documentsFor()` adds exactly **one** bounded, indexed query
(`trx_rme_legacy_records` by `patient_id`) per workspace load. It is O(1) in the
number of documents — not an N+1. The native side costs nothing extra because
`sheetsForPatient()` already eager-loads `clinicVisit`.

## 5. Read-only is structural, not cosmetic

Read-only here does not mean "the edit button is hidden". There is simply **no
edit endpoint** for a published archive anywhere in the application, and this
sprint adds none. `LegacyRmeRecordPolicy` exposes no `update` and no `delete`
ability at all. A correction is a VOID plus a fresh import through the intake
workflow.

A structural test asserts that the only mutating `rme.legacy-records.*` route in
the entire route table is `rme.legacy-records.void`.

## 6. Authorization

Unchanged and reused verbatim:

- A doctor reaches an archive only through `DoctorPatientScopeService` — an
  active patient-doctor assignment or a visit with that doctor.
- **Same branch alone is never enough.** A same-branch doctor with no clinical
  relationship is refused both the listing and the bytes.
- Every byte request re-authorizes independently (`viewFile`), so a copied URL
  fails closed regardless of what the UI rendered. A hidden button is not a
  security boundary and is not treated as one.
- Out-of-scope ids 404 (never 403), so an id cannot be probed.
- A VOIDed record never streams, even for Super Admin — enforced in the
  controller and not only in the policy, because the single global
  `Gate::before` grants Super Admin every ability.

## 7. No clinical data loss

Opening a legacy document opens an **in-page overlay**. The doctor never
navigates away, so unsaved handwriting or notes in the active RME are never
discarded by reading the archive. This was the deciding factor in choosing an
overlay over a full-page viewer.

Selecting a *native* sheet still reloads the workspace exactly as the existing
sheet navigation always has — that behaviour is unchanged by this sprint.

## 8. Nothing loads until asked

The viewer body lives inside `<template x-if="open">` and binds `src` only once
opened. On initial render the workspace fetches **no** page image and **no** PDF
byte, regardless of how many documents the patient has. A test asserts no
`src="…legacy-records…"` or `data="…legacy-records…"` attribute exists in the
rendered HTML.

## 9. Graceful degradation

An archive imported without a working rasterizer has a page count but no
rendered pages. The viewer detects this (and also recovers from a failed image
load) and falls back to the inline source PDF, which already streams with
`Content-Disposition: inline` through the same policy-gated route. No broken
image is ever shown in a clinical viewer.

## 10. Tests

`tests/Feature/LegacyRme/LegacyRmeDoctorWorkspaceDocumentsTest.php` — 22 tests:

| Area | Covered |
|---|---|
| UX fix | rail renders **above** the history card; history card still present; works with zero native sheets |
| Read-only | read-only stated in text; only mutating legacy route is `void` |
| Typing | native/legacy explicitly typed, never inferred from id |
| Performance | no archive bytes fetched on initial render |
| Doctor scope | treating doctor allowed; same-branch-no-relationship denied (listing **and** bytes); other branch 404; guest redirected; direct page URL re-authorized |
| Status | non-published never listed; voided never streams |
| Multi-document | several archives newest-first, distinct, correct page counts; single vs multi-page; no-rendered-pages fallback |
| Native regression | native update form intact; sheet links anchored on the canonical visit |
| Side effects | zero rows in visits/records/billing/lab/SATUSEHAT; no public storage URL; no KTP |

## 11. A bug caught before it shipped

The first implementation anchored native sheet links on **each sheet's own
visit**. The workspace redirects any non-canonical visit to the canonical URL,
and that redirect does **not** carry `sheet` — so selecting any sheet outside
the canonical visit would have silently lost the selection. Native links are now
anchored on the canonical workspace visit, exactly as `rm-sheet-nav` does, and a
test pins it.

## 12. Deploy

No migration, no seeder, no permission change. `Nothing to migrate.` is the
expected deploy output.
