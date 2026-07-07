# UIX-4 — RME + Odontogram Polish

**Sprint:** UIX-4
**Branch:** `feature/uix-4-rme-odontogram-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
**Date:** 2026-07-07
**Type:** Presentation-only UI/UX polish (clinical pages). **NOT a rewrite, NOT a business-logic sprint.**

---

## Baseline verification (UIX-1/2/3 GO)

| Sprint | GO tag | Commit |
| --- | --- | --- |
| UIX-1 Design System Foundation | `uix-1-daengtisiams-luxury-healthcare-design-system-foundation-go` | `0d78f92` |
| UIX-2 Dashboard Owner Polish | `uix-2-dashboard-owner-polish-go` | `91364ed` |
| UIX-3 Kunjungan List Polish | `uix-3-kunjungan-list-polish-go` | `9fce4ac` |

Baseline gates before edit (all GO):
- `architecture:ui-governance-check --strict` → GO
- `foundation:security-compliance-check` → GO (9/9)
- `foundation:cicd-enterprise-gate-check` → GO (10/10)
- `foundation:enterprise-closure-check` → GO (36/36)

Worktree clean; branch created off the stable base.

---

## Objective

Polish the RME visit detail page and the Odontogram page onto the DaengtisiaMS Luxury
Healthcare Design System so clinical screens read as **mewah, professional, clean, ringan,
readable** and stay consistent with UIX-1/2/3. These two pages become the **reference
implementation for all clinical pages** (RME, Odontogram, Rekam Medis, Resep).

---

## Graphify / mapping summary

- **RME detail:** `resources/views/rme/visits/show.blade.php` (route `rme.visits.show` → `ClinicVisitController@show`).
- **Odontogram:** `resources/views/rme/visits/odontogram/show.blade.php` (route `rme.visits.odontogram.show` → `OdontogramController@show`, gated by `visit.room`).
- **Timeline partial:** `resources/views/rme/visits/partials/patient-visit-history.blade.php`.
- **Print/PDF:** `print.blade.php`, `print-pdf.blade.php`, `partials/print-body.blade.php`, `odontogram/partials/structured-print-template.blade.php` — **separate, dompdf-safe, zero teal, NOT touched.**
- **Doctor workspace:** `medical-record/show.blade.php` (976 lines, Sprint 64 patient-centric + Alpine) — **intentionally deferred** (high-risk editing surface, out of polish scope).

---

## Files changed (presentation + governance + docs + tests only)

| File | Change |
| --- | --- |
| `resources/views/rme/visits/show.blade.php` | Header → `x-ui.page-header` (breadcrumb + actions); status → `x-ui.badge :status`; transition CTAs → `x-ui.button` variants (warning/primary/success/danger); selesai/batal + room-gate banners → `x-ui.alert`; consent chips → `x-ui.badge`; teal/emerald/rose/amber → semantic tokens. |
| `resources/views/rme/visits/odontogram/show.blade.php` | Chrome teal → brand (eyebrow, links, focus rings, add-row button, DMF-T total card). **Clinical status colors preserved** (karies=merah/hilang=gelap/tambalan=biru/crown=amber/PSA=sky/normal=hijau) for distinguishability + print/PDF parity. |
| `resources/views/rme/visits/partials/patient-visit-history.blade.php` | Current-row highlight + links teal → brand; status column → `x-ui.badge :status`. |
| `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` | Added non-brittle UIX-4 clinical-page rules. |
| `docs/ui/daengtisiams-ui-governance.md` | Added UIX-4 clinical-page governance section + permanent clinical-page standard. |
| `docs/ui/daengtisiams-implementation-checklist.md` | Added UIX-4 acceptance + clinical-page standard; migration order updated. |
| `tests/Feature/Ui/RmeOdontogramUixTest.php` | New — render/authorization/privacy/marker/governance tests. |
| `docs/sprints/uix-4-rme-odontogram-polish.md` | This evidence doc. |

## Files intentionally NOT touched

- All controllers / services / repositories / policies / requests / routes / migrations.
- Print/PDF views (separate dompdf-safe layout, no shared teal chrome).
- Odontogram clinical status color map / legend swatches / DMF-T D/M/F cards (print parity).
- `medical-record/show.blade.php` (deferred — high-risk doctor editing workspace).

---

## Presentation-only / no-regression statement

- **No** change to RME finalization, odontogram state/payload behavior, diagnosis/treatment
  data, consent/payment gate, cashier transition, lab order transition, BranchContext,
  permission/policy, route names, controller/service/query, or schema/migration.
- **No** change to print/PDF clinical content or business logic.
- **No** new frontend dependency; Tailwind + Blade + Alpine only.
- Odontogram data binding (`x-data="odontogramEditor(...)"`, hidden `tooth_map_payload`,
  `getPayload()`, `addRow()`/`setStatus()`/`removeRow()`) untouched.

## Privacy statement

- **No** KTP/NIK/scanned document/raw sensitive note rendered that was not shown before.
- RME detail info card shows only privacy-safe fields (name, phone, WA, age, doctor,
  branch, room, complaint, initial treatment). A governance rule now blocks
  `->ktp_number`/`->ktp`/`->nik`/`->identity_number` in the clinical detail views.

---

## Components & tokens used

- Components: `x-ui.page-header`, `x-ui.card`, `x-ui.badge` (`:status` + tone), `x-ui.button`
  (primary/secondary/neutral/warning/success/danger), `x-ui.alert`, `x-settings-shell`.
- Tokens: `brand-{50,100,200,500,600,700,800}`, `navy`, `ink`/`ink-soft`/`ink-muted`,
  `hairline`, `surface`, status `success`/`warning`/`danger`/`info`. **Gold NOT used** on
  clinical pages (accent-only, never a clinical action).

---

## Validation

_Results captured during Phase 7 — see PR / CLAUDE.md deploy-evidence entry._

- `php artisan test tests/Feature/Ui` — RmeOdontogramUixTest + UIX-1/2/3 suites
- `php artisan test tests/Feature/RME` — RME regression
- `php artisan test --filter=Odontogram` / `--filter=MedicalRecord` / `--filter=Cashier`
- `architecture:ui-governance-check --strict` → GO (incl. UIX-4 rules)
- `foundation:security-compliance-check` / `cicd-enterprise-gate-check` / `enterprise-closure-check` → GO
- `npm run build`, `vendor/bin/pint --dirty`, `git diff --check`, `php artisan view:cache`

---

## Known risks / notes

- Neutral `text-gray-*` labels retained in the RME info card `<dl>` (readable on white
  surfaces; not a brand/teal violation) — a future deeper pass may tokenize to `ink-*`.
- The "Placeholder — Odontogram interaktif…" copy in the RME detail odontogram card is
  legacy wording left untouched (copy-only, out of color scope).

## Deferred

- Doctor filter on Kunjungan list (needs controller/service change — from UIX-3).
- Deep Rekam Medis doctor-workspace polish (`medical-record/show.blade.php`).
- UIX-5 Kasir/payment · UIX-6 Inventory · UIX-7 Lab · UIX-8 Reports/print/PDF.
