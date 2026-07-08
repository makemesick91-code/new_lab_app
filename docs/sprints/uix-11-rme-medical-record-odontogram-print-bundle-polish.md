# UIX-11 — RME Medical Record, Odontogram & Print Bundle Polish

**Branch:** `feature/uix-11-rme-medical-record-odontogram-print-bundle-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous GO:** `uix-10-rme-visit-queue-patient-workspace-polish-go`

## Scope

Eleventh UI/UX sprint — **presentation-only** polish of the doctor-facing RME
clinical documentation surfaces onto the UIX-1 design system. The medical record
detail becomes the reference clinical documentation page; the medical record list
follows the UIX-3 list standard; the odontogram detail and the print/PDF bundle
are brought onto semantic tokens / brand hex.

**No backend behavior changed.** No controller/service/repository/query/route/
permission/policy/BranchContext/schema/migration change. No RME workflow,
finalization, handwriting, odontogram-finalization, room gate, doctor→cashier
gate, cashier/payment, or report-calculation change. Blade + Tailwind + Alpine
only — no new frontend dependency.

## Runtime UI changes

- **`resources/views/rme/visits/medical-record/index.blade.php`** — rebuilt on the
  UIX-3 list standard: `x-ui.page-header` + `x-ui.filter-bar` (+ `x-ui.input`/
  `x-ui.select`, same GET params `search`/`status`/`visit_date_from`/
  `visit_date_to`) + `x-ui.table` + `x-ui.badge` (draft=warning/final=success) +
  `x-ui.button` + `x-ui.empty-state`. Legacy teal/gray → semantic tokens.
- **`resources/views/rme/visits/medical-record/empty.blade.php`** — header →
  `x-ui.page-header`; opened-from-later-visit note → `x-ui.alert variant="info"`;
  the "Buat Halaman RM Pertama" panel → `x-ui.empty-state` with the form in the
  `action` slot. The store route + `source_visit_id` hidden field are unchanged.
- **`resources/views/rme/visits/medical-record/show.blade.php`** — header →
  `x-ui.page-header` (breadcrumb + status badge in the actions slot); follow-up
  and opened-from-later-visit notices → `x-ui.alert`; page-nav active chip and
  preview hover teal → brand tokens; the handwriting error and the "no
  handwriting yet" placeholder → danger/warning tokens; the finalize block →
  `x-ui.alert` (warning gate / success finalized) + `x-ui.button variant="success"`
  (disabled when handwriting is missing). **The handwriting `<canvas>` template
  script is untouched** — it bakes the official Daengtisia RM paper (neutral ink
  hex `#111827`) into the saved PNG so the print bundle keeps paper parity.
- **`resources/views/rme/visits/odontogram/show.blade.php`** — finalized notice →
  `x-ui.alert variant="info"`; header → `x-ui.page-header` (badge + prev/next nav
  + back/print/finalize in the actions slot). The Alpine `odontogramEditor` table
  editor, the FDI visual, and the DMF-T tally are untouched; **clinical status
  colours are preserved on purpose** (karies=red / tambalan=blue / etc.) for
  distinguishability and Sprint-63.1 print parity.
- **Print bundle** — `print.blade.php`, `print-pdf.blade.php`,
  `odontogram/print.blade.php`: legacy teal hex `#0f766e`/`#0d9488` → brand hex
  `#1D4ED8`/`#1E40AF` (UIX-8 precedent). Semantic status badge colours (draft /
  final / finalized) preserved. Templates stay `<table>`-based / dompdf-safe;
  `print-body.blade.php` and `structured-print-template.blade.php` were already
  token-neutral and needed no change.

## Governance

`app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` gains non-brittle
UIX-11 rules: the four clinical views exist; the medical record detail uses
page-header/card/badge/button/alert; the medical record list uses the list
standard; the odontogram detail uses page-header/card/badge/button; across the
clinical views no legacy `teal-*`, no `variant="gold"` CTA, no rendered
`->ktp/nik/identity_number`; the print templates carry no residual teal hex and
no rendered KTP/NIK; the UIX-11 evidence doc exists (soft).

## Preserved rules (explicit)

- Medical record **finalization logic** unchanged (`$isDraft && $canFinalize`
  gate; finalize route/form untouched).
- **Handwriting PNG requirement** unchanged — finalize stays disabled until
  `$hasHandwriting`; the canvas capture/save guards are untouched.
- Odontogram **finalization logic** unchanged (`$canFinalize` gate, finalize
  route/confirm untouched); tooth-map payload semantics untouched.
- **SOAP stays hidden** in the doctor UI — no new SOAP input added; the legacy
  read-only SOAP card is unchanged.
- **Doctor cannot complete the visit directly**; visit `completed` remains
  cashier/payment-driven. No cashier/payment/receivable behavior changed.
- **Room gate (`visit.room`)** not bypassed — no gate/middleware change.
- Policy / permission / BranchContext unchanged; request `branch_id` never
  trusted (no new branch input added).
- **No full KTP/NIK** rendered in any UI/print/PDF/export/log.
- **Print/PDF stays dompdf-safe** — table-based layout preserved.

## Tests

- New `tests/Feature/Ui/RmeClinicalDocumentationUixTest.php` (9).
- Regression: MedicalRecord, Odontogram, PatientCentricRmWorkspace,
  RmeRoomAssignmentGate, RmeDoctorCashierCompletionGate, Ui.

## Rollback

Revert the branch merge commit; all changes are Blade views + one console
command + docs/tests. No migration, no data change.
