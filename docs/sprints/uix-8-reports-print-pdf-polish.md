# UIX-8 — Reports, Print & PDF Polish

**Branch:** `feature/uix-8-reports-print-pdf-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous GO:** `uix-7-lab-pipeline-polish-go`
**Type:** Presentation-only UI/UX polish (8th UIX sprint).

## Scope

Polish the highest-value report, print, and PDF surfaces onto the UIX-1 luxury
healthcare design system (off-white canvas, **blue** primary, **gold accent-only**),
without changing any report calculation or business logic. Follows the UIX-3 list
standard and the UIX-6 "reference set + preserved semantic colours" precedent.

**No** controller / service / repository / query / route-name / permission / policy /
BranchContext / schema / migration change. **No** report calculation, invoice,
receivable, payment, stock valuation, stock-movement, or KPI logic change. **No**
export data-column change. KTP/NIK are never rendered in any report, print, or export
view. Dompdf/print templates stay table-based (no flexbox for the data grid). No new
heavy PDF/chart dependency.

## Runtime UI changes

### RME reports (reference report screens)
- `resources/views/rme/reports/patients.blade.php` — rebuilt on `x-ui.page-header`
  (breadcrumb + Export/Cetak actions), `x-ui.filter-bar` + `x-ui.input`/`x-ui.select`
  (same GET params), `x-ui.kpi-card` summary totals, `x-ui.table` with semantic
  tokens, `x-ui.badge :status`, and `x-ui.empty-state`.
- `resources/views/rme/reports/payments.blade.php` — same list standard; the revenue
  total KPI uses the gold `accent` rail (revenue-only rule), invoice status → badge.

### RME report print templates (browser `window.print()`)
- `resources/views/rme/reports/print/patients.blade.php` and `.../payments.blade.php`
  — retinted teal `#0f766e` → brand blue `#1D4ED8`, summary chips → brand-50/100,
  added zebra rows and navy header text for readability. Structure unchanged; already
  table-based.

### Inventory reports
- `resources/views/inventory/reports/index.blade.php` — header → `x-ui.page-header` +
  `x-ui.button`; all teal chrome (eyebrow, focus rings, submit, active tab, refill
  button) → brand tokens. **Semantic stock-status colours preserved** (empty=rose,
  low=amber, overstock=sky, normal=emerald; masuk=emerald / keluar=rose) per the UIX-6
  precedent — these encode meaning, not brand.
- `resources/views/inventory/reports/batch-disposals/index.blade.php` and
  `.../batch-monthly-closing/index.blade.php` — header → `x-ui.page-header` +
  `x-ui.button`; teal chrome → brand tokens.
- `resources/views/inventory/reports/room-stock/refill-checklist.blade.php` and
  `resources/views/inventory/stock-transfers/checklist-pdf.blade.php` (print/PDF
  templates) — teal hex → brand hex (`#0f766e`→`#1D4ED8`, `#134e4a`→`#1E40AF`,
  `#99f6e4`→`#BFD7FE`, `#ecfdf5`→`#EFF4FF`). Table-based structure unchanged.

### Reporting module (legacy dental-lab)
- `resources/views/reports/payments.blade.php` — full conversion to the list standard
  as the reporting-module reference (page-header, filter-bar, kpi-style summary chips,
  table, buttons, empty-state).
- `resources/views/reports/{dashboard,delivery,invoices,orders,outstanding,production,qc,revenue}.blade.php`
  — submit-button chrome `bg-gray-800`/`hover:bg-gray-700` → brand blue for
  design-system consistency (light retint; full conversion deferred — see below).

## Governance

`app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` extended with
non-brittle UIX-8 rules: RME report screens + inventory reports hub exist; RME reports
use page-header/filter-bar/table/badge/button/empty-state; inventory reports hub uses
page-header; across the polished report + print surfaces no legacy `teal-*`, no
`variant="gold"` CTA, and no `->ktp/nik/identity_number`; UIX-8 evidence doc present
(soft). Hex is intentionally not scanned (print templates keep inline brand hex — same
as the UIX-5 receipt precedent).

## Tests

`tests/Feature/Ui/ReportsPrintPdfUixTest.php` — guest-redirect (no 500) on report
routes, RME reports use the list standard, inventory/reporting-module pages use the
page header, print templates retinted to brand blue + table-based, KTP/NIK never
rendered, and `architecture:ui-governance-check --strict` exits 0.

## Deferred (future UIX)

- Full x-ui conversion of the remaining reporting-module screens (`reports/*` other
  than `payments`) beyond the chrome retint — legacy dental-lab reporting, low pilot
  value.
- Full x-ui table conversion of the inventory report data tables (semantic stock-status
  colours preserved as-is).
- **UIX-9 — Inventory Analytics, Charts & Workflow Forms Polish.**

## PII / KTP confirmation

No full KTP/NIK is rendered or exported in any touched report, print, or export view;
the governance command asserts this across the polished surfaces.

## Risk / rollback

Low risk — presentation-only Blade/token changes; no PHP business logic, route, or
schema touched. Rollback = revert the branch merge commit; no migration to reverse.
