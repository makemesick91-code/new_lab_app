# FIX-RME-PRINT-REMOVE-PATIENT-VISIT-DUPLICATE-1

**Classification:** FEATURE_REVISION / UI PRINT FIX
**Branch:** `feature/fix-rme-print-remove-patient-visit-duplicate-1`
**Baseline:** `authoritative-consolidated-full-suite-2-final-program-closure-go` @ `865d1f4923e72126a692dfdeef363de0da9a71db`
(tree `e43b4634305ea0766e4febe89fd493ba077e9d46`, verified byte-identical to the deployed production HEAD)

---

## 1. What changed

"Cetak RME" no longer prints the **`Data Pasien & Kunjungan`** card.

The card listed, as typed metadata, twelve fields — Nama Pasien, No. Rekam Medis,
Ponsel, Nomor WA, No. Kunjungan, Tanggal Kunjungan, Cabang, Klinik, Antrian,
Dokter, Tindakan Awal, Keluhan Utama — immediately above the handwritten RM sheet
that the same document reproduces. The clinician had already written that
information by hand on the sheet, so the first printed page was a restatement of
the second. The print now opens directly on the clinical content.

**Nothing was deleted from the data.** No column, row, model, relation, request
field or screen lost anything. Every one of those fields is still stored and still
displayed on the visit detail screen (`rme.visits.show`), which is where the module
actually guarantees them.

---

## 2. Files changed

| File | Change |
|---|---|
| `resources/views/rme/visits/partials/print-body.blade.php` | Removed the `Patient & Visit Info` `section-block` (57 lines); documented the removal in the existing `@php` header comment |
| `.github/workflows/foundation-evidence-gates.yml` | Added the `RmePrintPatientVisitDuplicate` token to the NSF-R011 critical filter — **both** runner variants |
| `tests/Feature/RME/RmePrintPatientVisitDuplicateRemovalTest.php` | **New** — 9 tests |
| `tests/Feature/RME/MedicalRecordPrintOdontogramSeparationTest.php` | 2 tests amended (stale contract) |
| `tests/Feature/RME/RmePdfPrintHardeningTest.php` | 2 tests amended (stale contract) |
| `tests/Feature/RME/RmePatientFormatConsentTest.php` | 1 test re-anchored (stale positive anchor) |
| `docs/`, `.cursor/rules/` | This document + the durable rule mirror |

**No migration. No route. No permission. No policy. No controller. No service.
No schema. No JS/CSS asset.**

---

## 3. Why one edit covers both canonical outputs

`rme.visits.print` (browser) and `rme.visits.pdf` (dompdf) are separate wrappers
that both `@include('rme.visits.partials.print-body')`. The card lived in that one
shared partial, so removing it once removes it from both — and neither output can
drift from the other later.

```
rme.visits.print      ─┐
                       ├─→ partials/print-body.blade.php   ← the only edit
rme.visits.pdf        ─┘
```

## 4. What was deliberately KEPT

The goal was to remove a **duplicate information card**, not to strip the document
of its identity. A printed clinical sheet has to be attributable, and that job
belongs to the document header/footer, which is in the wrappers, not the card:

* each wrapper's `<title>` carries the patient name and the visit number;
* each wrapper's footer carries the visit number and the print timestamp;
* the clinic logo's `alt` text ("Logo Klinik Gigi Daengtisia") is branding.

That last one is a real trap and is pinned by a test comment: a naive
`assertDontSee('Klinik')` demands the *logo* be removed. The removal test therefore
asserts the card's `<dt>Klinik</dt>` **cell markup**, not the bare word.

## 5. Deliberate non-change (recorded finding, NOT actioned)

`ClinicVisitController::resolvePrintViewData()` eager-loads `doctor`, `branch`,
`clinic` and `initialTreatment`. After this change the print composition reads
none of them — four dead eager loads.

They were **left in place on purpose**:

* the sprint scope is the print composition; the controller is not a defect;
* `Model::preventLazyLoading` is not enabled anywhere in this application
  (verified), so the loads are a harmless four-row prefetch with no correctness
  effect either way;
* removing them would widen the diff into `ClinicVisit` for no user-visible gain.

Recorded here as a finding for a future cleanup sprint rather than silently
expanding this one.

## 6. Tests

New suite `RmePrintPatientVisitDuplicateRemovalTest` (9):

| # | Test | Claim |
|---|---|---|
| 1 | section title gone | caption absent, escaped **and** raw |
| 2 | field labels gone | all 12 `<dt>` cells absent |
| 3 | field **values** gone | the data is absent, not just the caption — a relabelled card still fails |
| 4 | handwriting preserved | `RME Tulisan Tangan` renders, still embedded inline (never linked) |
| 5 | document identity preserved | title + footer still name the patient and visit |
| 6 | no layout residue | no empty `section-block`/`info-grid`; the medical record is now the opening section |
| 7 | PDF drops the card too | with a surviving non-vacuous extraction anchor |
| 8 | authorization unchanged | unauthorized → 403 on print **and** PDF |
| 9 | branch isolation unchanged | cross-branch → 403 on print **and** PDF |

**Mutation-verified.** With the original template restored, tests 1, 2, 3, 6 and 7
fail and 4, 5, 8, 9 still pass — so the removal assertions are not vacuous and the
invariant assertions are genuinely invariant.

### Amended tests

Five tests asserted the card *renders*. Each was amended to keep its original
purpose while re-anchoring on content that survives — none was weakened to a
vacuous pass:

* `MedicalRecordPrintOdontogramSeparationTest` — the PDF extraction proof moved
  from the card's `Nama Pasien` cell to the pinned visit number in the footer
  (still document-specific, still wrap-proof per FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1).
* `RmePdfPrintHardeningTest` — the two card-content tests became: the document
  still identifies its patient and visit, and the card's cells are gone. The
  initial-treatment test now asserts the visit **keeps** its `initial_treatment_id`
  while the printed duplicate goes.
* `RmePatientFormatConsentTest` — the "no KTP on print" privacy claim is unchanged;
  its positive anchor moved from `Nomor WA` to `Rekam Medis` so it still cannot
  pass merely because the page failed to render.

## 7. CI selection

A new suite matching no filter token would never run in a required gate
(the failure mode CI-MONITORING-CRITICAL-TOKEN-COVERAGE-1 exists to prevent).
`RmePrintPatientVisitDuplicate` was added to the NSF-R011 critical filter in
**both** the GitHub-hosted and self-hosted variants, and the two filter strings
were verified byte-identical. Selection was then proven empirically by running
`pest --filter='<exact CI filter>' --list-tests` and confirming all 9 tests are
selected — not inferred from reading the regex.

Neither `critical_gate_required_filters` (scoped to CI's own tooling) nor
`critical_gate_mandatory_suites` (scoped to monitor truthfulness, and explicit
that "adding a suite is a governance decision, not a reflex") was touched: a
print-presentation suite belongs in neither.

## 8. Full Suite governance

Not run, and not required. `full_suite_gate` fires only when
`classify.outputs.full_suite_authorized == 'true'`, and while the GLOBAL TEMPORARY
FULL-SUITE POLICY is **ACTIVE** that resolver returns `false` for both automatic
events (`schedule` and post-merge `push` to base). The only surviving path is a
deliberate `workflow_dispatch` with both inputs set. This sprint neither requested
that dispatch nor altered the policy.

## 9. Preserved invariants

* Clinical workflow (Registration → Queue → in_progress → Consent → authoring →
  Selesai Pemeriksaan → cashier_pending → payment) — untouched.
* Consent gate, room gate, doctor→cashier gate — untouched.
* Branch isolation — untouched; no request-controlled branch authority introduced.
* Clinical privacy — handwriting still embedded inline from the private
  `clinical_evidence` disk, never linked and never moved to a public disk.
  KTP/NIK still never rendered.
* Odontogram model, workflow and standalone print — untouched.
