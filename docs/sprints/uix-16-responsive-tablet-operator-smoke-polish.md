# UIX-16 — Responsive, Tablet & Operator Smoke Polish

**Branch:** `feature/uix-16-responsive-tablet-operator-smoke-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous required GO:** `uix-15-global-component-foundation-hardening-go`

## Scope

Presentation-only responsive/tablet/operator polish of the shared `x-ui.*`
foundation and the highest-frequency operator surfaces so the app is easier to
operate on laptop (1366px), tablet landscape (1024px) and tablet portrait (768px)
widths. Desktop appearance is preserved (all changes widen only from a `sm`/`md`
breakpoint up, or are additive wrap/overflow utilities).

**No** route / policy / Gate / permission / Spatie role / BranchContext / query /
schema / migration / controller / service / repository change. **No** financial,
RME, room-gate, medical-record, odontogram, cashier, payment, Lab, inventory ledger,
procurement, transfer, opname, report calculation, or dashboard KPI logic change.
**No** React/Vue/SPA/datatable/chart/heavy-responsive dependency — Blade + Tailwind +
Alpine only. **No** form field name / method / action / validation / Alpine business
behaviour change. **No** sensitive-data (KTP/NIK/scans/raw notes/secrets/env) exposure.

## Component-level responsive changes (`resources/views/components/ui/`)

- **`filter-bar.blade.php`** — the action group now wraps on narrow widths
  (`flex items-center gap-2` → `flex flex-wrap items-center gap-2`) in both the
  `<form>` and non-form variants, so submit/reset buttons never overflow. Field
  stacking (`flex-col md:flex-row md:flex-wrap`) was already present.
- **`card.blade.php`** — the header action group now wraps
  (`flex shrink-0 items-center gap-2` → `flex shrink-0 flex-wrap items-center gap-2`).

(`x-ui.table` already wraps in `overflow-x-auto`; `x-ui.page-header` already stacks
`flex-col → sm:flex-row` and wraps its actions — UIX-15/earlier. UIX-16 locks these
guarantees behind governance.)

## Page-level responsive changes (representative high-frequency surfaces)

Fixed non-stacking detail/summary grids (`grid grid-cols-2/3 … text-sm`) converted to
a `grid-cols-1` base that widens from `sm` up (`grid-cols-1 … sm:grid-cols-2/3`), so
label/value and summary cards stack cleanly on phone widths while tablet/desktop
(≥640px) stay identical:

- RME cashier: `show`, `payment/create`, `follow-ups/create`, `partials/clinical-summary`
- Lab: `case-candidates/show`
- Inventory: `products/index`, `stock/card`, `batches/index`, `alerts/index`,
  `purchase-orders/show`

## Governance

`app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` extended with
non-brittle UIX-16 rules:

- `x-ui.table` keeps `overflow-x-auto`.
- `x-ui.filter-bar` stacks (`flex-col` + `md:flex-row`) and wraps its actions (`flex-wrap`).
- `x-ui.page-header` stacks (`flex-col` + `sm:flex-row`) and wraps its actions (`flex-wrap`).
- Representative operator surfaces carry no fixed non-stacking `grid-cols-2`/`grid-cols-3`
  *text-sm* detail grid.
- `docs/ui_design_system.md` documents the UIX-16 standard + sprint doc present (soft).

## Docs / rules

- `docs/ui_design_system.md` — new "Responsive, tablet & operator standard (UIX-16)".
- `docs/ui/daengtisiams-ui-governance.md` — new UIX-16 responsive rules section.
- This sprint evidence doc.

## Tests

- New `tests/Feature/Ui/ResponsiveOperatorUixTest.php` — asserts the foundation
  components render the responsive utilities (table overflow, filter-bar/page-header
  stacking + action wrapping), the representative pages carry the stacking grid classes,
  and `architecture:ui-governance-check --strict` stays GO.

## Responsive / operator smoke notes

At 1366 / 1024 / 768 widths the operator surfaces are expected to show: no horizontal
body overflow (tables scroll inside their own container), page-header and filter-bar
actions wrap, detail/summary grids stack, and all primary actions remain visible.

## Risk / rollback

Low risk — additive Tailwind responsive utilities only; desktop layout unchanged.
Rollback: revert the branch / GO tag; no data, migration, or schema impact.
