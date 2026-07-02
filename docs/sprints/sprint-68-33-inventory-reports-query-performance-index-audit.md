# Sprint 68.33 — Inventory Reports Query Performance & Index Audit

## Scope

Audit-only + light code guards for Inventory Reports after Sprint 68.29 tab scoping and Sprint 68.30/68.31 branch + dependent filter work.

## Queries inspected

| Report | Primary table | Required guards | Pagination |
| --- | --- | --- | --- |
| Current Stock | `trx_inventory_movements` | `branch_id` (+ optional product/location/category/status) | Yes (`current_stock_page`) |
| Stock Card | `trx_inventory_movements` | `branch_id` + **required `product_id`** + date range | Yes (`stock_card_page`) |
| Low Stock | `trx_inventory_movements` | `branch_id` | Yes (`low_stock_page`) |
| Mutation | `trx_inventory_movements` | `branch_id` + date range (`date_from`/`date_to`) | Yes (`mutation_page`) |
| Valuation | `trx_inventory_movements` | `branch_id` | Yes (`valuation_page`) |
| Room Stock | `trx_inventory_movements` + room minimums | `branch_id` | Yes (`room_stock_page`) |

## Existing indexes on `trx_inventory_movements`

From `2026_06_04_120000_create_inventory_core_tables.php` and `2026_06_06_210001_add_inventory_batch_id_to_trx_inventory_movements_table.php`:

- `branch_id`
- `inventory_location_id`
- `product_id`
- `supplier_id`
- `movement_type`
- `movement_date`
- `(reference_type, reference_id)`
- `(branch_id, inventory_location_id, product_id)`
- `inventory_batch_id`
- `(branch_id, inventory_location_id, inventory_batch_id)`

## Index decision

**No new migration added in Sprint 68.33.**

Rationale:

- Report filters already align with existing single-column and composite indexes.
- Pilot dataset size is moderate; all report endpoints paginate and tab-scope to one dataset per request.
- A future composite `(branch_id, movement_date)` index may help mutation/stock-card date scans at higher volume, but was deferred pending production query evidence.

## Code guards added

- `InventoryReportService::resolveReportPerPage()` caps page size to 100.
- Stock Card continues to short-circuit without `product_id` (no movement query/render).
- Export row cap remains `5000` (`EXPORT_ROW_CAP`).

## Quality gates

```bash
DB_CONNECTION=pgsql php artisan test --filter=Sprint6833InventoryReportsPerformanceGuardTest
DB_CONNECTION=pgsql php artisan test --filter=Sprint6832InventoryReportsExportParityTest
DB_CONNECTION=pgsql php artisan test --filter=Sprint6829InventoryReportsTabScopedLoadingTest
DB_CONNECTION=pgsql php artisan test --filter=InventoryReportTest
php artisan migrate --pretend
```
