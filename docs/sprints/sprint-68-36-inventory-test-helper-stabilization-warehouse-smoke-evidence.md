# Sprint 68.36 — Inventory Test Helper Stabilization & Warehouse Smoke Evidence

## Purpose

Stabilize Inventory Pest helpers after Sprints 68.29–68.35 so sprint-focused tests can run standalone, and document Admin Warehouse smoke verification after Sprint 68.35 daily quick-actions polish.

## Test helper changes

Moved from `tests/Feature/Inventory/InventoryReportTest.php` into global `tests/Pest.php`:

- `createReportStockRow()`
- `createReportMovement()` (dependency)
- `reportPanelHtml()` and report section HTML helpers used by tab/performance sprint tests

Each helper is wrapped with `if (! function_exists(...))` to avoid redeclaration when Pest bootstraps.

Sprint tests no longer depend on `InventoryReportTest.php` load order.

## PostgreSQL requirement for Inventory tests

Run inventory tests with PostgreSQL:

```bash
DB_CONNECTION=pgsql php artisan test --filter=Inventory
DB_CONNECTION=pgsql php artisan test tests/Feature/Inventory/Sprint6834InventoryDashboardBranchAwareKpiTest.php
```

**Why not default sqlite in-memory:** inventory migrations include PostgreSQL-specific statements such as `CREATE INDEX CONCURRENTLY`, which fail or behave differently on sqlite. Sprint 68.33+ performance/index work assumes PG semantics.

## Browser / Playwright MCP

- **Playwright/browser MCP:** not available in this sprint run.
- **Evidence mode:** manual browser smoke required (checklist below).

## Admin Warehouse Smoke Checklist

Login:

- [ ] Login as Admin Warehouse.

Dashboard:

- [ ] Open Inventory Dashboard.
- [ ] Confirm branch selector/branch label is correct.
- [ ] Confirm KPI cards load.
- [ ] Confirm Panel Aksi Cepat Harian Gudang is visible.

Quick actions:

- [ ] Permintaan Pembelian opens existing PR route.
- [ ] Pesanan Pembelian opens existing PO route.
- [ ] Penerimaan Barang opens existing goods receipt route.
- [ ] Transfer Stok opens existing transfer route.
- [ ] Stok Opname opens existing opname route.
- [ ] Laporan Inventory opens reports page with branch-scoped query.
- [ ] Peringatan Stok opens low-stock/alert report.
- [ ] Analitik Persediaan opens analytics/dashboard route.

Reports:

- [ ] Open Inventory Reports.
- [ ] Confirm Cabang dropdown shows real RME branch names.
- [ ] Confirm tab switch preserves branch.
- [ ] Confirm Stok Saat Ini renders only current-stock panel.
- [ ] Confirm Kartu Stok without product shows empty state.
- [ ] Confirm Kartu Stok with product uses selected branch.
- [ ] Confirm Mutasi Stok uses selected branch and date filters.
- [ ] Confirm no inactive report tables are visible.

Security:

- [ ] Try manual `branch_id` tampering if practical.
- [ ] Confirm unauthorized branch does not leak data.

Expected:

- [ ] No 404.
- [ ] No unexpected 403 for permitted Admin Warehouse routes.
- [ ] No 500.
- [ ] Laravel log has no new critical errors.

## Quality gates (local)

```bash
DB_CONNECTION=pgsql php artisan test --filter=Sprint6834InventoryDashboardBranchAwareKpiTest
DB_CONNECTION=pgsql php artisan test --filter=Sprint6835WarehouseOperatorDailyWorkflowPolishTest
DB_CONNECTION=pgsql php artisan test --filter=InventoryDashboardTest
DB_CONNECTION=pgsql php artisan test --filter=InventoryReportTest
DB_CONNECTION=pgsql php artisan test --filter=Sprint6831InventoryReportsBranchScopedDependentFiltersTest
DB_CONNECTION=pgsql php artisan test --filter=Sprint6832InventoryReportsExportParityTest
DB_CONNECTION=pgsql php artisan test --filter=Sprint6833InventoryReportsPerformanceGuardTest
DB_CONNECTION=pgsql php artisan test --filter=Sprint6830InventoryReportsRmeBranchFilterIntegrationTest
DB_CONNECTION=pgsql php artisan test --filter=Sprint6829InventoryReportsTabScopedLoadingTest
./vendor/bin/pint --dirty
npm run build
git diff --check
```

## Deploy

Expected: **NO APP DEPLOY** — tests/docs-only sprint; no production code changed.
