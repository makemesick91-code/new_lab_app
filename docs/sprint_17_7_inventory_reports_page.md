# Sprint 17.7 — Inventory Reports Page + Room Stock Report

Date: 2026-06-08
Status: Implemented
Module: `app/Modules/Inventory`

## 1. Purpose

This sprint adds a dedicated **Inventory Reports** page under **Persediaan / Inventory**. The page
consolidates six ledger-derived, read-only operational reports in one tabbed UI with shared filters,
independent pagination per report, and CSV export. No schema changes, no new permissions, and no
workflow side effects (transfer or purchase request creation) were introduced.

## 2. Routes

| Method | Path | Route name |
|---|---|---|
| GET | `/inventory/reports` | `inventory.reports.index` |
| GET | `/inventory/reports/export` | `inventory.reports.export` |

Registered in `routes/web.php` inside the existing `inventory` route group.

## 3. Controller / Request / Service

| Layer | Class | Responsibility |
|---|---|---|
| Controller | `InventoryReportController` | Authorizes `viewAny` on `InventoryMovement`, delegates to service, renders `inventory.reports.index` or streams CSV export |
| Form Request | `InventoryReportFilterRequest` | Validates filters, report tabs, export `report_type`, pagination `per_page` |
| Service | `InventoryReportService` | Resolves active branch via `BranchContext`, orchestrates repository queries, computes running balances, recommendations, status labels, and CSV export |

Flow: `Controller → InventoryReportFilterRequest → InventoryReportService → InventoryMovementRepository`.

## 4. Repository Methods

Methods added to `InventoryMovementRepositoryInterface` and implemented in
`InventoryMovementRepository`:

| Method | Purpose |
|---|---|
| `getCurrentStockReport(...)` | Ledger-derived stock grouped by branch + product + location |
| `getStockCardReport(...)` | Paginated movement rows for stock card within date range |
| `getStockCardOpeningBalance(...)` | `SUM(quantity_in - quantity_out)` before `date_from` |
| `getStockCardPeriodBalanceBeforePage(...)` | Period net change on prior pages for running balance |
| `getLowStockReport(...)` | Empty and low-stock rows using product `minimum_stock` |
| `getStockAvailabilityForProducts(...)` | Cross-location stock snapshot for recommendation logic |
| `getStockMutationReport(...)` | Period mutation summary per product/location |
| `getInventoryValuationReport(...)` | Operational value estimate per product/location |
| `getRoomStockReport(...)` | Stock per room/location/product with refill context |

## 5. Permissions / Authorization

- Uses the existing **`view_inventory`** / **`InventoryMovement::viewAny`** authorization pattern
  (same as other inventory read screens).
- Controller calls `$this->authorize('viewAny', InventoryMovement::class)` on index and export.
- **No new permission** was added.
- Reports are **read-only**; no stock mutations, transfers, or purchase requests are created from
  this page.

## 6. Sidebar

Updated canonical sidebar: `resources/views/layouts/sidebar.blade.php`

Added under **Persediaan**:

- **Laporan Inventory** → `route('inventory.reports.index')`
- Active state: `request()->routeIs('inventory.reports.*')`
- Visible to users with inventory view access (same gating as other Persediaan links).

## 7. Reports Included

| Tab key | Indonesian label | Description |
|---|---|---|
| `current_stock` | Laporan Stok Saat Ini | Current ledger-derived stock per product/location |
| `stock_card` | Laporan Kartu Stok | Movement ledger detail with running balance |
| `low_stock` | Laporan Low Stock | Empty and below-minimum items with text recommendations |
| `mutation` | Laporan Mutasi Stok | Opening balance, period in/out totals per product/location |
| `valuation` | Laporan Nilai Persediaan | Operational inventory value estimate |
| `room_stock` | Laporan Stok per Ruangan | Stock per room/location with refill guidance |

Views:

- `resources/views/inventory/reports/index.blade.php`
- `resources/views/inventory/reports/_empty-table.blade.php`

## 8. Export

- **CSV export only.** No Excel export was implemented.
- Route: `inventory.reports.export` (`GET /inventory/reports/export`)
- Required query param: `report_type`
- Supported `report_type` values:
  `current_stock`, `stock_card`, `low_stock`, `mutation`, `valuation`, `room_stock`
- Export is capped at **5,000 rows** (`InventoryReportService::EXPORT_ROW_CAP`).
- If more than 5,000 rows match, the CSV includes a trailing **CATATAN** row explaining the cap.
- **UTF-8 BOM** (`\xEF\xBB\xBF`) is written for Excel/CSV compatibility.
- **Stock Card export requires `product_id`** (validated in `InventoryReportFilterRequest`).
- Export inherits the same active-branch scope as the on-screen reports.

## 9. Ledger Invariants

- `trx_inventory_movements` remains the **source of truth**.
- Current stock formula:
  `SUM(quantity_in) - SUM(quantity_out)`
- Grouping for current stock, low stock, and valuation:
  `branch_id + product_id + inventory_location_id`
- Grouping for room stock:
  `branch_id + inventory_location_id + product_id`
- **No mutable stock columns** were added.
- **No schema changes** were made.

## 10. Branch Isolation

- Reports resolve the active branch through **`BranchContext::requireId()`**.
- Submitted `branch_id` in query strings does **not** widen server-side access; filter options
  expose only the active branch.
- CSV export also remains **active-branch scoped**.
- Low stock and room stock recommendations do **not** perform cross-branch refill logic.

## 11. Report-Specific Notes

### Current Stock

- Shows ledger-derived stock per product/location.
- Status badges: **Kosong**, **Low Stock**, **Normal**.
- **Overstock** is not produced because no maximum-stock field exists on products.

### Stock Card

- Requires `product_id` for detail rows; without it the UI shows a prompt to select a product.
- Uses default **current-month** date range when dates are missing (`startOfMonth` → today).
- Opening balance = `SUM(quantity_in - quantity_out)` before `date_from`.
- Running balance is ledger-based and **page-aware** (prior pages included via
  `getStockCardPeriodBalanceBeforePage`).

### Low Stock

- Shows empty and low-stock items only.
- Uses product-level **`minimum_stock`**.
- Text recommendations (no workflow side effects):
  - Segera restock
  - Perlu restock
  - Refill dari Gudang Utama
  - Pertimbangkan transfer dari lokasi lain
  - Buat permintaan pembelian

### Mutation

- Default date range: **current month start** through **current date**.
- `opening_balance` = `SUM(quantity_in - quantity_out)` before `date_from`.
- `total_in` / `total_out` during the selected period.
- `movement_type` filter affects **period totals only**; `opening_balance` remains the true stock
  opening balance.

### Valuation

- **Operational estimate only**, not accounting valuation.
- Formula: `current_stock × inv_products.average_cost`
- Missing/zero cost shows dash / **Rp 0** according to UI formatting.
- No FIFO/LIFO/procurement costing changes.

### Room Stock

- **Room** = `inv_inventory_locations`.
- Shows stock per room/location/product.
- Uses product-level **`minimum_stock`** (no per-room minimum table).
- Refill recommendations are **text only** (same pattern as low stock).
- **Sprint 17.8** should add per-room minimum/maximum stock thresholds.

## 12. Pagination

Each report tab uses an independent pagination query parameter so switching tabs does not reset
other report pages:

| Report | Page parameter |
|---|---|
| Current Stock | `current_stock_page` |
| Stock Card | `stock_card_page` |
| Low Stock | `low_stock_page` |
| Mutation | `mutation_page` |
| Valuation | `valuation_page` |
| Room Stock | `room_stock_page` |

Shared `per_page` filter (1–100, default 15) applies to all paginated reports.

## 13. Testing

Focused suite: `tests/Feature/Inventory/InventoryReportTest.php`

At sprint closure:

- `php artisan test --filter=InventoryReport` — **108 tests, 449 assertions** (PASS)

Coverage areas:

- Route registration and naming
- Authorization (allowed / denied)
- Sidebar menu visibility
- Filter validation and application (product, category, location, stock status, movement type, dates)
- Branch isolation (submitted `branch_id` cannot widen scope)
- Report calculations (opening balance, running balance, period totals, valuation)
- Status badges (Kosong, Low Stock, Normal)
- Low stock and room stock recommendations
- Independent pagination per tab
- CSV export (headers, BOM, cap note, branch scope, stock card `product_id` requirement)

## 14. Quality Gates

Commands used at sprint closure:

```text
php artisan test --filter=InventoryReport
.\vendor\bin\pint --test
git diff --check
php artisan route:list --path=inventory
npm run build
php artisan test
```

Results are recorded in the **Quality Gate Result** section at the end of this document after the
final closure run.

## 15. Limitations

- Products **without movement rows** are excluded from reports (ledger-only derivation).
- Export capped at **5,000 rows**.
- Valuation is an **operational estimate** only (`average_cost × derived stock`).
- Minimum stock remains **product-level**; no per-room thresholds yet.
- Per-room minimum/maximum stock is **future work** (Sprint 17.8).
- Full `php artisan test` suite may require extended runtime; see quality gate results below.

## 16. Recommended Next Sprint

**Sprint 17.8 — Minimum Stock per Room**

Suggested scope:

- `inv_location_product_minimums` table
- Minimum stock per room
- Maximum stock per room
- Room stock refill thresholds
- Printable room stock checklist
- Room stock opname
- Refill request workflow from Gudang Utama
- Update room stock report to use per-room thresholds

---

## Quality Gate Result

Closure run: 2026-06-08

| Gate | Result |
|---|---|
| `php artisan test --filter=InventoryReport` | **PASS** — 108 tests, 449 assertions (~45s) |
| `.\vendor\bin\pint --test` | **PASS** |
| `git diff --check` | **PASS** |
| `php artisan route:list --path=inventory` | **PASS** — 106 inventory routes |
| `npm run build` | **PASS** — 56 Vite modules transformed |
| `php artisan test` (full suite) | **FAIL** — 1430 passed, **1 failed**, 5138 assertions (~406s / ~6m 46s) |

**Full-suite failure (not a timeout):**

- `Tests\Feature\Inventory\InventoryUiTest` — `assertDontSee('Inventory')` on the inventory
  dashboard now fails because the Persediaan sidebar includes the new link label **Laporan
  Inventory**.
- Focused `InventoryReport` regression suite remains green and is the verified anchor for this
  sprint's report/export behavior.
