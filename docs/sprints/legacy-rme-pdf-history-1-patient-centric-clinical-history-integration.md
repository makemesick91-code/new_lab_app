# LEGACY-RME-PDF-HISTORY-1 — Patient-Centric Clinical History Integration

Branch `feature/legacy-rme-pdf-history-1-patient-centric-clinical-history-integration`
Base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `6d4850f`
(the LEGACY-RME-PDF-ROLL-3 GO commit — ROLL-3 stays closed, immutable and untouched).

## Objective

A doctor opening the RME workspace from a new visit sees the patient's whole
clinical story in one place:

```
Current RME  +  earlier native RME  +  PUBLISHED legacy RME PDF
```

Legacy archives become part of the patient's clinical **history**. They are
never converted into native records.

## What already existed (ROLL-3 baseline)

| Capability | State before this sprint |
|---|---|
| `LegacyRmePatientHistoryService` (`publishedRecordsFor`, `timelineFor`, `hasLegacyHistory`) | shipped in 1C |
| Merged native+legacy card | **only** on the visit detail page (`rme.visits.show`) |
| Doctor-facing viewer, print, export, VOID | shipped in 1C/1D |
| `LegacyRmeRecordPolicy` + `DoctorPatientScopeService` clinical gate | shipped (ROLL-2 finding) |
| `latest_rme_date` column | existed, **never surfaced** |

The patient-centric RM workspace — `rme.visits.medical-record.show`, the page a
doctor actually works in — had **no** legacy integration at all. That gap is the
core of this sprint.

## Gap → change

| Gap | Change |
|---|---|
| Legacy history absent from the RM workspace | `MedicalRecordController@show` now builds a patient-centric history and both the workspace and its zero-sheet empty state render it |
| Multi-date archive shown as a single date | `LegacyRmeTimelineEntry` carries `latest_rme_date`; rows render an earliest–latest range |
| Workspace needs newest-first + a "current visit" marker | new `patientClinicalHistoryFor()`; the visit page's ascending `timelineFor()` contract is untouched |
| Read-vs-migration separation unproven | explicit regression coverage (below) |

## Implementation

**No migration. No new route. No new permission. No policy relaxed.**

- `app/Modules/LegacyRme/Support/LegacyRmeTimelineEntry.php`
  `endDate` + `isCurrent`; `hasDateRange()`, `dateLabel()`. A latest date is only
  a range when **strictly after** the earliest one — an equal value is a
  single-date document and an inverted value (bad data) falls back to the single
  representative date rather than rendering a backwards range.
- `app/Modules/LegacyRme/Services/LegacyRmePatientHistoryService.php`
  new `patientClinicalHistoryFor()` + a shared private `mergeEntries()` so the
  visit page and the workspace can never describe the same history differently.
  Same authorization path as before; only the projection differs.
- `app/Modules/MedicalRecord/Controllers/MedicalRecordController.php`
  thin: resolves the patient from the already-authorized visit, takes the
  canonical native history from `ClinicVisitService::patientVisitHistory()`
  (RME-branch scoped, `doctor`/`initialTreatment` eager-loaded → no N+1) and
  hands both to the service.
- `resources/views/rme/visits/partials/patient-rme-clinical-history.blade.php` (new)
  read-only card, `x-ui.*` + semantic tokens, Alpine filter (Semua / RME Sistem /
  RME Lama) that is presentation only.
- `resources/views/rme/visits/medical-record/{show,empty}.blade.php` — include it.
- `resources/views/rme/visits/partials/patient-rme-timeline.blade.php` — the visit
  page now shows the same truthful date range.

## Ordering

`sortKey()` is a strict total order: clinical date → kind (legacy before native)
→ source id. The workspace reverses it with `sortByDesc`, so newest-first stays
fully deterministic and a same-day collision never falls back to the database's
row order. Ordering is always the **clinical** date (`rme_date` / `visit_date`),
never upload or creation time.

## Capability state vs already-published evidence

Verified against the code, not assumed:

- **Branch admission** (ROLL-2/ROLL-3, "which branch may migrate documents") is
  an **ingestion** gate. It is not consulted on the read path, so an archive
  already published stays in the patient's history after a migration wave closes
  and the admitted set is emptied. This is the write/read separation the sprint
  required — it already held, and is now pinned by a regression test.
- **The feature flag** `rme.legacy_pdf_archive` is the documented master switch
  for the whole capability: while it is off, every import/review/publish/viewer
  route answers 404 and the history card renders nothing. That is the canonical
  rule declared in `config/feature_flags.php` and in the flag's own rollback
  action. **HISTORY-1 does not change it** — doing so would re-enable part of a
  high-risk capability that the owner deliberately switched off in production.
  The behaviour is now pinned by a test that also proves the published evidence
  is *hidden, never erased*.

Production posture is unchanged: `FEATURE_RME_LEGACY_PDF_ARCHIVE=false`,
admitted branch set empty. HISTORY-1 grants no rollout or migration approval.

## Tests

`tests/Feature/LegacyRme/LegacyRmePatientCentricHistoryTest.php` — 24 tests:

native-only · legacy-only (no native RME) · unified merge · multiple archives ·
same-day deterministic collision · multi-date range · single/inverted date never
a range · current-visit marker · non-published states excluded · VOID excluded
but preserved · patient isolation · treating doctor sees · same-branch
non-treating doctor denied · cross-branch doctor denied · direct viewer URL
denied (403 / 404 / permission) · workspace renders unified history · empty-state
renders the archive · no conversion action rendered · zero downstream rows
created · flag-off hides without erasing · admission-empty keeps reads working ·
KTP/NIK and storage paths never rendered · visit-page card stays ascending.

## Non-goals

No global rollout, no migration wave, no branch admission, no bulk import, no
OCR date authority, no legacy→native conversion, no auto-publish, no billing /
lab / SATUSEHAT generation, no DoctorPatientScope bypass, no ROLL-4 operations.
