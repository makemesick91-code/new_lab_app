# UIX-7 — Lab Pipeline Polish

**Branch:** `feature/uix-7-lab-pipeline-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
**Previous baseline:** UIX-6 Inventory Polish — merged, GO tag `uix-6-inventory-polish-go`.

## Summary

Seventh UI/UX sprint. Presentation-only polish of the **Lab pipeline** surfaces
onto the UIX-1 DaengtisiaMS design system. The lab order list becomes the
reference lab list page and the lab order / production / QC / delivery detail
pages become the reference lab detail pages.

**No business-logic change:** no LabOrder lifecycle change, no auto-create of a
LabOrder from RME payment, no change to RME→Lab candidate generation, no
invoice/payment change, no policy/permission/BranchContext bypass, no
schema/migration, no route rename, no heavy frontend dependency (Blade + Tailwind
+ Alpine only). Every `@can`/`@canany` gate, route name, form field name, and
Alpine data binding is preserved verbatim.

## Design direction (UIX-1)

Off-white canvas, white surfaces, **blue** primary/active, **gold accent-only
(never a CTA)**, warning=orange/danger=red/success=green/info=blue. Clinical/QC
result colors carried by semantic status tones.

## New reusable component

* `resources/views/components/lab/status-badge.blade.php` — **`x-lab.status-badge`**.
  Maps the Lab pipeline's uppercase lifecycle / priority / QC / delivery status
  codes (DRAFT, RECEIVED, IN_PRODUCTION, ON_HOLD, QC_PENDING, QC_PASSED,
  DELIVERED, COMPLETED, CANCELLED, REMAKE, NORMAL/URGENT/SUPER_URGENT, PASS/FAIL/
  PENDING/REVISION/N_A, OPEN/IN_PROGRESS/DONE/SKIPPED) to a semantic UIX-1 tone +
  Indonesian label, then renders through `x-ui.badge`. First `x-lab.*` component —
  justified by the same status-badge pattern repeating across every lab surface.

## Views polished

| Surface | File |
| --- | --- |
| Lab order list (reference list) | `lab-orders/index.blade.php` |
| Lab order detail (tabs) | `lab-orders/show.blade.php` |
| Lab order create/edit/form | `lab-orders/create.blade.php`, `edit.blade.php`, `_form.blade.php` |
| RME case candidates list | `lab/case-candidates/index.blade.php` |
| RME case candidate detail + convert form | `lab/case-candidates/show.blade.php` |
| Production board (list) | `production/board.blade.php` |
| Production detail (action-heavy) | `production/show.blade.php` |
| Production work logs | `production/work-logs.blade.php` |
| QC queue (list) | `quality-control/queue.blade.php` |
| QC detail (action-heavy) | `quality-control/show.blade.php` |
| Delivery queue (list) | `deliveries/index.blade.php` |
| Delivery detail + POD | `deliveries/show.blade.php`, `_pod-form.blade.php`, `_signature-pad.blade.php` |

Each list uses `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` +
`x-lab.status-badge` + `x-ui.button` + `x-ui.empty-state` with semantic tokens
(same GET params). Each detail uses `x-ui.page-header` + `x-ui.card` +
`x-lab.status-badge` + `x-ui.button` with an improved action hierarchy
(primary/secondary/success/warning/danger). Palette across all surfaces:
teal→brand, emerald→success, amber→warning, purple/indigo→brand/info,
rose/red→danger, gray→navy/ink/hairline.

The `_pod-form` partial swapped its `buttonClass` param for a `buttonVariant`
param routed through `x-ui.button`. The `_signature-pad` retokenized its Blade
chrome; its JS canvas ink color stays a JS constant (not a Blade CSS class).

## Governance

`architecture:ui-governance-check` extended with **non-brittle UIX-7 rules**: the
`x-lab.status-badge` component exists; the lab order list uses page-header/
filter-bar/table/status-badge/button/empty-state; the lab detail uses page-header/
button/status-badge; across all 14 polished lab surfaces there is no legacy
`teal-*`, no `variant="gold"` CTA, and no rendered `->ktp/nik/identity_number`.
Hex is intentionally **not** scanned (the delivery signature pad keeps a JS canvas
ink color — same precedent as UIX-5 skipping hex for the receipt).

## Durable standard

Recorded the lab-page standard in `docs/ui/daengtisiams-ui-governance.md` +
`docs/ui/daengtisiams-implementation-checklist.md`: every lab surface MUST use the
`x-ui.*` list/detail components + the shared `x-lab.status-badge` + semantic
tokens; no from-scratch table/badge/button; no teal legacy; no gold CTA; never
render full KTP/NIK.

## Tests

`tests/Feature/Ui/LabPipelineUixTest.php` — status-badge tone/label mapping,
guest-redirect on all lab routes, reference surfaces adopt the design-system
components, no full KTP/NIK, and `architecture:ui-governance-check --strict` GO.

## Deferred

UIX-8 Reports, Print & PDF Polish (reports/production, reports/delivery, and all
print/PDF layouts).
