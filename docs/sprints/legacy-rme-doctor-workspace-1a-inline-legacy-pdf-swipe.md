# LEGACY-RME-DOCTOR-WORKSPACE-1A — Inline Legacy PDF Pages in the Handwritten RME Swipe Canvas

**Type:** corrective / product-completion sprint
**Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Builds on:** LEGACY-RME-DOCTOR-WORKSPACE-1 (foundation — kept, not erased)

---

## 1. Why this sprint exists

LEGACY-RME-DOCTOR-WORKSPACE-1 was **technically correct**. It moved the published
legacy archive out of the bottom-of-page clinical history and into a document rail
at the top of the workspace, with a policy-gated overlay viewer offering zoom and
expand. Nothing about its authorization, privacy or read-only guarantees was wrong.

It simply did not implement the workflow the owner asked for.

The owner's requirement is one sentence: **the doctor only needs to swipe.** In
WORKSPACE-1 a doctor still had to *leave* the `RME Tulisan Tangan Lengkap` page
navigation, pick a document from a rail, and enter a second viewer with its own
internal page navigation. Two navigation experiences for one clinical reading task.

1A changes that, and **only** that.

## 2. The product rule

```
ONE PAGE NAVIGATION EXPERIENCE
TWO DATA SOURCES

native handwritten page = editable
legacy PDF page         = read only
```

Native handwriting pages and legacy archive pages now form **one numbered
sequence** behind the same `?rm_page=` index, the same `← Sebelumnya` /
`Berikutnya →` controls, the same numbered buttons and the same swipe zone.

A legacy PDF with N pages contributes **N** virtual workspace pages — not one
"open this document" step:

```
Halaman 1 dari 7

1  Native handwritten page      EDITABLE
2  Native handwritten page      EDITABLE
3  Legacy PDF A — page 1        READ ONLY
4  Legacy PDF A — page 2        READ ONLY
5  Legacy PDF A — page 3        READ ONLY
6  Legacy PDF B — page 1        READ ONLY
7  Legacy PDF B — page 2        READ ONLY
```

## 3. The hard domain boundary

This is a **presentation and navigation** change. It is not a data-model merge.

A legacy page is a projection rebuilt per request. Reading or swiping one creates
**no** `ClinicVisit`, **no** `MedicalRecord`, **no** handwriting page row, **no**
odontogram, invoice, payment, `LabOrder` or SATUSEHAT candidate. No shadow native
row is ever created to make an archive page "fit" the sequence.

> Native and legacy pages share **presentation navigation**.
> They never share **persistence semantics**.

## 4. Architecture

```
MedicalRecordController::show
        │
        ├── PatientRmWorkspaceResolver::orderedHandwritingBookForPatient()   (native, unchanged)
        ├── LegacyRmePatientHistoryService::publishedRecordsFor()            (canonical archive read)
        │
        ▼
RmeWorkspacePageSequencer::sequenceFor()
        │
        ▼
Collection<RmeWorkspacePage>            ← explicit `type`, never inferred
        │
   ┌────┴─────────────────────────┐
   │                              │
RmeWorkspacePage::TYPE_NATIVE   RmeWorkspacePage::TYPE_LEGACY
editable canvas figure          read-only archive page
                                zoom / fit / fullscreen
                                bytes via policy-gated private route
```

### New files

| File | Role |
|---|---|
| `app/Modules/MedicalRecord/Support/RmeWorkspacePage.php` | Immutable, PII-free page projection. `type` is an explicit field. |
| `app/Modules/MedicalRecord/Services/RmeWorkspacePageSequencer.php` | Builds the unified sequence; resolves nothing itself. |
| `resources/views/rme/visits/medical-record/partials/legacy-archive-page.blade.php` | The read-only archive page surface. |
| `resources/views/rme/visits/medical-record/partials/rm-page-navigator.blade.php` | Extracted — **the** page navigator, shared by every surface. |
| `resources/views/rme/visits/medical-record/partials/rm-page-swipe-script.blade.php` | Extracted — **the** swipe implementation, shared by every surface. |

The navigator and the swipe script were **extracted, not duplicated**, precisely so
a second navigation system cannot grow. Both the populated workspace and the
zero-native-sheet empty state include the same two partials.

### Ordering, and why it is not strictly chronological

Native pages come first in their existing chronological order; the archive follows,
**newest document first**, with pages ascending inside each document.

Strict chronology would put every legacy page *before* every native page — the
legacy date rule guarantees an archive date is earlier than the earliest native RME
date. That would force a doctor to swipe through years of history before reaching
the page they need to write on. The editable present therefore stays at the front,
and history begins one swipe past the last native page.

### Page-count authority

No migration was needed. The count is the number of **actually rendered page rows**
(`trx_rme_legacy_record_pages`), loaded as a single aggregate — not the declared
`page_count`. A record can carry a declared count while nothing was ever rasterised;
trusting it would put pages in the sequence that could only render as broken images.

A record with **zero** rendered pages still belongs in the sequence — it is real
evidence — so it contributes exactly **one** fallback page that offers the inline
source PDF through the same private route.

## 5. Clinical safety

| Risk | How it is closed |
|---|---|
| A pen stroke turning the page | The canvas and the open editor overlay carry `[data-ignore-swipe]`; a gesture must be clearly horizontal (≥60px, 1.5× vertical) to count at all. Unchanged from the native baseline. |
| A pan gesture turning the page while zoomed | The archive viewer sets `data-ignore-swipe` **while zoomed** — at fit-width a swipe navigates, when zoomed the doctor is panning. |
| Silently losing unsaved handwriting | A `beforeunload` guard plus an explicit confirm on closing the editor with live strokes. Saving sets a `submitting` flag so a save is never mistaken for data loss. |
| Writing on evidence | The editable figure is **not rendered at all** on an archive page — there is no inert-but-present canvas to aim a stylus at. |
| `+ Tambah Halaman RM` touching the archive | It always targets the canonical **native** handwriting endpoint, on an archive page as much as a native one. |

## 6. Authorization — unchanged, and deliberately not re-derived

`RmeWorkspacePageSequencer` resolves nothing itself. The archive comes from
`LegacyRmePatientHistoryService::publishedRecordsFor()`, which already applies the
read permissions, the branch scope, the doctor patient scope and the
published-only/VOID policy.

A test asserts the sequencer's source contains **no** `STATUS_PUBLISHED`, no
`branchIdsFor`, no `doctorCanAccessPatient` and no `LegacyRmeRecord::query` — there
must be exactly one definition of "which archive may this user see".

Being listed in a sequence is **not** an authorization decision: every byte is still
fetched through the policy-gated streaming route, which re-authorizes per request.
Same branch alone still grants nothing.

## 7. Performance

The archive is now resolved **once per request** and shared by all three consumers
(clinical history, document rail, unified sequence). It was resolved **twice**
before this sprint, so 1A adds a whole new consumer while *reducing* the query
count. Rendered-page counts are one aggregate, not one query per document. Only the
**active** page's image is fetched; no PDF is base64'd into the HTML.

## 8. Out of scope

No migration. No new route. No new permission. No change to SOURCE-RM-BINDING,
separation of duties, the clinical date rule, ClinicalClock, the production resting
state (`CAPABILITY=OFF`, `ADMISSION=EMPTY`, `ACTIVE_BATCH=NONE`) or
`ROLL-4-WAVE-3 = SKIPPED / NOT REQUIRED`.

## 9. Tests

`tests/Feature/LegacyRme/LegacyRmeDoctorWorkspaceInlinePagesTest.php` — 32 tests
covering sequence composition, exact record/page mapping, native↔legacy and
legacy↔legacy navigation, document boundaries, reverse navigation, swipe targets,
non-colour-only legacy marking, `rm_page` clamping, read-only enforcement, absence
of mutating routes, zoom/fullscreen, native editability, the add-page boundary, the
unsaved-work guard, zero-legacy and zero-native-sheet states, doctor scope
(treating / same-branch-untreating / wrong-branch / guest / cross-patient),
non-published exclusion, unrendered-PDF degradation, lazy binary fetch, single
resolution + no N+1, the architecture delegation assertion, downstream side-effect
counts and KTP absence.
