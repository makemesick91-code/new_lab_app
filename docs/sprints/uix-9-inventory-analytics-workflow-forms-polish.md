# UIX-9 — Inventory Analytics, Charts & Workflow Forms Polish

**Branch:** `feature/uix-9-inventory-analytics-workflow-forms-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Previous GO:** `uix-8-reports-print-pdf-polish-go`

## Scope

Ninth UI/UX sprint — presentation-only polish of the **deferred** inventory analytics,
chart, and workflow form/detail surfaces onto the UIX-1 luxury healthcare design system.
UIX-6 polished only the highest-value inventory *scan* surfaces (product list, stock card,
alerts, batches index, dashboard, workflow index headers); UIX-9 continues onto the
analytics/executive dashboards and the procurement/opname workflow forms and detail pages.

**Design direction (UIX-1):** off-white canvas, white surfaces, **blue** (brand) primary/active,
**gold accent-only** (never an inventory CTA or status), semantic status tokens
(success/warning/danger/info). Neutral gray labels are acceptable (UIX-4/UIX-6 precedent).

## Runtime UI changes

- **Palette normalization (all targets):** shade-safe `teal-*` → `brand-*` conversion across
  every targeted analytics, executive, workflow show/create/form, product form, and batch view
  (`teal-900` → `brand-800` since the brand palette exposes no shade 900). No legacy teal
  remains in scope.
- **`analytics/index` (reference analytics page):** header → `x-ui.page-header`; summary block →
  `x-ui.card`; "Catatan Penting" note → `x-ui.alert variant="warning"`; filter Terapkan/Atur Ulang →
  `x-ui.button`; existing `x-inventory.kpi-card` KPI grid preserved. Ledger-derivation copy unchanged.
- **`executive-dashboard` (reference analytics page):** header → `x-ui.page-header` +
  `x-ui.button` actions; methodology note → `x-ui.alert`; reorder severity → `x-ui.badge`
  (critical=danger/low=warning/else=info); existing `x-inventory.kpi-card` +
  `x-inventory.dashboard-section` preserved.
- **`purchase-orders/show` (reference workflow-detail page):** header + full workflow action area →
  `x-ui.page-header` with `x-ui.button` variant map (Ajukan=warning, Setujui=success,
  Kirim=primary, Terima Barang=primary, Ubah=secondary, Batalkan=danger, Kembali=secondary);
  "Tidak menambah stok" note → `x-ui.alert variant="info"`; status include partials preserved.
- **`goods-receipts/create` (workflow form page):** header → `x-ui.page-header`; ledger note →
  `x-ui.alert`; form wrapped in `x-ui.card`; footer actions → `x-ui.button` (disabled-when-no-PO
  preserved).
- **`products/_form` (reference workflow-form partial):** all fields → `x-ui.input` /
  `x-ui.select` / `x-ui.textarea` (auto validation-error display via the shared error bag);
  missing-master notice → `x-ui.alert variant="warning"`; alert/reorder sub-panel tokenized.
- **`stock-opnames/create` (opname form):** header → `x-ui.page-header`; form → `x-ui.card` with
  `x-ui.select` / `x-ui.input` / `x-ui.textarea`; `x-inventory.searchable-product-select`
  preserved; footer → `x-ui.button`.
- **`products/show`:** stock-action buttons re-tokenized (Terima Stok → `variant="success"`,
  Penyesuaian Keluar → `variant="warning"`) removing raw emerald/green/amber overrides.

## Files changed

- Views (deep component adoption): `inventory/analytics/index`, `inventory/executive-dashboard`,
  `inventory/purchase-orders/show`, `inventory/goods-receipts/create`, `inventory/products/_form`,
  `inventory/stock-opnames/create`, `inventory/products/show`.
- Views (palette normalization only, teal→brand): analytics tab partials, `purchase-requests/*`,
  `purchase-orders/_form`+`edit`, `goods-receipts/_form`+`_batch-item-fields`+`edit`,
  `stock-transfers/*`, `stock-opnames/show`+`review`, `batches/show`, `products/create`+`edit`.
- `app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` — non-brittle UIX-9 rules.
- `docs/sprints/uix-9-inventory-analytics-workflow-forms-polish.md` (this file); design-system
  standard note appended to `docs/ui_design_system.md`.
- `tests/Feature/Ui/InventoryWorkflowUixTest.php` (new).

## Governance / UIX-9 rules

`architecture:ui-governance-check --strict` verifies (non-brittle):
- analytics/index + executive-dashboard use `x-ui.page-header` + `x-inventory.kpi-card`;
  analytics/index also uses `x-ui.card`;
- `purchase-orders/show` uses `x-ui.page-header` + `x-ui.button` + `x-ui.alert`;
- `products/_form` uses `x-ui.input` + `x-ui.select` + `x-ui.textarea`;
- across the 12 polished analytics/workflow files: no legacy `teal-*`, no `variant="gold"` CTA,
  no mutable stock attribute assignment.

## Inventory ledger / business-logic no-change confirmation

No inventory ledger, SUM-movement stock calculation, stock-card running balance, low-stock/reorder,
valuation, batch/lot expiry, PR/PO/GR/transfer/stock-opname lifecycle, or procurement approval
logic was changed. No controller/service/repository/query/route/permission/policy/BranchContext
change. No migration.

## Mutable stock column no-change confirmation

No mutable stock column (`current_stock`, `qty_on_hand`, `stock_quantity`, …) was added or written.
Stock stays ledger-derived. The governance scan asserts no mutable stock attribute assignment across
the polished views.

## Risk / rollback

Presentation-only Blade changes; no runtime/business surface touched. Risk is limited to visual
rendering. Rollback = revert the branch merge commit; no data or schema impact.
