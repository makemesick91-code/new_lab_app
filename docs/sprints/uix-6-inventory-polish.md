# UIX-6 — Inventory Polish

**Branch:** `feature/uix-6-inventory-polish`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
**Type:** Presentation-only UI/UX polish. **No business logic changes.**

## Baseline verified before start

| Sprint | GO tag | Verified |
| --- | --- | --- |
| UIX-1 | `uix-1-daengtisiams-luxury-healthcare-design-system-foundation-go` | ✅ |
| UIX-2 | `uix-2-dashboard-owner-polish-go` | ✅ |
| UIX-3 | `uix-3-kunjungan-list-polish-go` | ✅ |
| UIX-4 | `uix-4-rme-odontogram-polish-go` | ✅ |
| UIX-5 | `uix-5-kasir-payment-polish-go` | ✅ |

Baseline gates before editing (all GO): `architecture:ui-governance-check --strict`,
`foundation:security-compliance-check`, `foundation:cicd-enterprise-gate-check`,
`foundation:enterprise-closure-check`. Worktree clean.

## Objective

Polish the Inventory module's primary **scan surfaces** onto the UIX-1 design
system (off-white canvas, white surface cards, brand blue primary/active, gold
accent-only, semantic status colors) so large inventory tables are more
readable, stock status is clearer, and action buttons are consistent — **without
touching inventory ledger, stock calculation, procurement/transfer/opname
lifecycle, valuation, or batch/expiry logic.**

## Scope map (Graphify + route/view survey)

The inventory module has **104 Blade views**. UIX-6 deliberately polishes the
highest-value **read/scan surfaces** (mirrors the UIX-3/UIX-5 "reference set"
precedent, not a 104-file rewrite). Forms (`create`/`edit`/`_form`), `show`
details, reports, and print/PDF are **deferred** (reports/print → UIX-8).

### Views changed (presentation only)

| View | Route | Polish |
| --- | --- | --- |
| `inventory/dashboard.blade.php` | `inventory.dashboard` | eyebrow/select/CTA → tokens + `x-ui.button` (UIX-2 dashboard standard) |
| `inventory/products/index.blade.php` | `inventory.products.index` | **reference inventory list** → `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` + `x-ui.badge` + `x-ui.button` + `x-ui.empty-state` |
| `inventory/stock/index.blade.php` | `inventory.stock.index` | page-header + filter-bar + `x-ui.kpi-card` + tokenized table |
| `inventory/stock/card.blade.php` | `inventory.products.stock-card` | **reference ledger-derived detail** → page-header + card + table; running-balance/sign/order untouched |
| `inventory/alerts/index.blade.php` | `inventory.alerts.index` | page-header + filter-bar + card + table + empty-state; `orange`→`warning` token |
| `inventory/batches/index.blade.php` | `inventory.batches.index` | page-header + `x-ui.alert` note + filter-bar + card + table + empty-state |
| `inventory/purchase-requests/index.blade.php` | `inventory.purchase-requests.index` | page-header + token chrome |
| `inventory/purchase-orders/index.blade.php` | `inventory.purchase-orders.index` | page-header + token chrome |
| `inventory/goods-receipts/index.blade.php` | `inventory.goods-receipts.index` | page-header + token chrome |
| `inventory/stock-transfers/index.blade.php` | `inventory.stock-transfers.index` | page-header + token chrome |
| `inventory/stock-opnames/index.blade.php` | `inventory.stock-opnames.index` | page-header + token chrome |

### Shared components & badge partials tokenized (teal/emerald/amber/rose/sky → tokens)

`components/inventory/{kpi-card,alert-summary-widget,movement-timeline,quick-actions-panel,dashboard-section,searchable-product-select,location-card,stock-value-card}.blade.php`;
`inventory/{_status-badge,_low-stock-badge,_filter-actions,alerts/_stock-severity-badge,batches/_batch-status-badge,batches/_batch-expiry-status-badge,purchase-requests/_status-badge,purchase-orders/_status-badge,purchase-orders/_receiving-status-badge,goods-receipts/_status-badge,stock-transfers/_status-badge}.blade.php`.
The PR & GR status badges now route through `x-ui.badge` with semantic tones
(submitted→info, approved/posted→success, rejected/cancelled/void→danger).

### Files intentionally NOT touched

- Every inventory `create`/`edit`/`_form`/`show` view (workflow forms — deferred).
- `inventory/reports/**` + `**/print.blade.php` + `checklist-pdf.blade.php` (print/export → UIX-8).
- `analytics/**`, `executive-dashboard`, `activity-logs`, `locations`, `suppliers`,
  `product-categories`, `product-units`, `location-minimums`, `batch-disposal-requests`,
  `goods-receipts` batch-field/form partials.
- All controllers, services, repositories, requests, policies, models, routes, migrations.

## Guarantees (presentation-only)

- **No inventory ledger change** — stock stays SUM-of-movements / ledger-derived.
- **No stock calculation / stock card / running-balance change** — `stock/card`
  still renders movements in service order with `quantity_in`/`quantity_out`
  signs and `running_balance` verbatim.
- **No low stock / reorder / valuation change.**
- **No procurement (PR/PO/GR) / transfer / stock opname / batch-expiry lifecycle change.**
- **No permission / policy / BranchContext / route-name / schema / migration change.**
- **No mutable stock column introduced** (governance + test guard against a
  mutable stock attribute write in views).
- **No heavy frontend dependency** — Tailwind + Blade + Alpine only.
- **KTP/NIK** — not applicable to inventory; none rendered.

## Governance

`app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php` extended with
non-brittle **UIX-6** rules: reference inventory views exist; product list uses
page-header/filter-bar/table/badge/button/empty-state; stock card uses
page-header/card/badge/table; no legacy `teal-*`, no `variant="gold"` CTA, and
no mutable stock attribute assignment across the 11 polished inventory files;
UIX-6 evidence doc present (soft). Durable inventory rules added to
`docs/ui/daengtisiams-ui-governance.md` and
`docs/ui/daengtisiams-implementation-checklist.md`.

## Tests / build

- `tests/Feature/Ui/InventoryUixTest.php` — page render/authorize + component
  markers + no-teal/gold/mutable-stock + governance GO.
- `php artisan architecture:ui-governance-check --strict` → GO.
- `foundation:security-compliance-check` / `cicd-enterprise-gate-check` /
  `enterprise-closure-check` → GO.
- `npm run build`, `vendor/bin/pint --dirty`, `git diff --check`,
  `php artisan view:cache` → clean.

## Deferred (future UIX)

- Deeper inventory analytics dashboard & chart polish.
- Inventory print/PDF/export deep polish → **UIX-8**.
- Inventory workflow form (`create`/`edit`) polish.
- Lab pipeline → **UIX-7**.
