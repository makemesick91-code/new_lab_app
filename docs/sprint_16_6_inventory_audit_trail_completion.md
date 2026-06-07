# Sprint 16.6 — Inventory Audit Trail & Activity Log Completion

**Status:** COMPLETED  
**Branch:** `feature/sprint-16-procurement`  
**Tag:** `sprint-16.6-complete`  
**Completion date:** 2026-06-07

---

## Summary

Inventory Activity Log sudah tersedia dengan tabel dedicated `inv_inventory_activity_logs`. Seluruh workflow procurement dan inventory utama (PR, PO, GR, Stock Transfer, Stock Opname, movement, batch) menulis log aktivitas append-only tanpa mengubah ledger stock, BranchContext, atau permission Sprint 16.5.

UI Activity Log tersedia di `/inventory/activity-logs` dengan filter action, tanggal, user, correlation_id, dan search — permission-gated.

---

## Key Decisions

- Tidak reuse `sys_audit_logs` atau `AuditLogService` Lab Order
- `branch_id` first-class column di setiap baris log
- Append-only log (tanpa `updated_at`)
- Non-blocking logging — kegagalan logging tidak rollback transaksi bisnis utama
- `correlation_id` nullable UUID didukung untuk workflow chain
- Activity Log UI permission-gated (`view_inventory_activity_log` + backward compatibility)
- Workflow logging tidak mengubah ledger atau business rules existing

---

## Files Changed

### Migration

- `database/migrations/2026_06_09_100000_create_inv_inventory_activity_logs_table.php`

### Model

- `app/Modules/Inventory/Models/InventoryActivityLog.php`

### Enum

- `app/Modules/Inventory/Enums/InventoryActivityAction.php`

### Repository

- `app/Modules/Inventory/Interfaces/InventoryActivityLogRepositoryInterface.php`
- `app/Modules/Inventory/Repositories/InventoryActivityLogRepository.php`

### Service

- `app/Modules/Inventory/Services/InventoryActivityLogService.php`
- `app/Modules/Inventory/Services/Concerns/LogsInventoryActivity.php`
- `app/Modules/Inventory/Services/PurchaseRequestService.php` (workflow logging)
- `app/Modules/Inventory/Services/PurchaseOrderService.php` (workflow logging)
- `app/Modules/Inventory/Services/GoodsReceiptService.php` (workflow logging)
- `app/Modules/Inventory/Services/StockTransferService.php` (workflow logging)
- `app/Modules/Inventory/Services/StockOpnameService.php` (workflow logging)
- `app/Modules/Inventory/Services/InventoryStockService.php` (movement/batch logging)

### Policy

- `app/Modules/Inventory/Policies/InventoryActivityLogPolicy.php`

### Controller

- `app/Modules/Inventory/Controllers/InventoryActivityLogController.php`

### Request

- `app/Modules/Inventory/Requests/InventoryActivityLogFilterRequest.php`

### Views

- `resources/views/inventory/activity-logs/index.blade.php`
- `resources/views/inventory/activity-logs/show.blade.php`

### Seeder

- `database/seeders/PermissionSeeder.php` (`view_inventory_activity_log`)
- `database/seeders/RoleSeeder.php` (Admin Lab grant)
- `app/Modules/AccessControl/Services/PermissionGroupingService.php` (Inventory grouping)

### Sidebar

- `resources/views/layouts/sidebar.blade.php` (Log Aktivitas link)

### Provider / Routes

- `app/Providers/RepositoryServiceProvider.php` (binding + policy)
- `routes/web.php` (activity-logs routes)

### Factory

- `database/factories/InventoryActivityLogFactory.php`

### Tests

- `tests/Feature/Inventory/InventoryActivityLogTest.php`
- `tests/Feature/Inventory/InventoryActivityLogWorkflowTest.php`
- `tests/Feature/Inventory/InventoryPermissionHardeningTest.php` (updated)

### Design Doc

- `docs/sprint_16_6_inventory_audit_trail.md`

---

## Actions Logged

| Action | Workflow |
|---|---|
| `purchase_request_created` | Purchase Request |
| `purchase_request_updated` | Purchase Request |
| `purchase_request_submitted` | Purchase Request |
| `purchase_request_approved` | Purchase Request |
| `purchase_request_rejected` | Purchase Request |
| `purchase_request_cancelled` | Purchase Request |
| `purchase_order_created` | Purchase Order |
| `purchase_order_updated` | Purchase Order |
| `purchase_order_submitted` | Purchase Order |
| `purchase_order_approved` | Purchase Order |
| `purchase_order_cancelled` | Purchase Order |
| `goods_receipt_created` | Goods Receipt |
| `goods_receipt_updated` | Goods Receipt |
| `goods_receipt_completed` | Goods Receipt |
| `goods_receipt_cancelled` | Goods Receipt |
| `stock_transfer_created` | Stock Transfer |
| `stock_transfer_updated` | Stock Transfer |
| `stock_transfer_submitted` | Stock Transfer |
| `stock_transfer_approved` | Stock Transfer |
| `stock_transfer_received` | Stock Transfer |
| `stock_transfer_cancelled` | Stock Transfer |
| `stock_opname_created` | Stock Opname |
| `stock_opname_updated` | Stock Opname |
| `stock_opname_completed` | Stock Opname |
| `stock_opname_cancelled` | Stock Opname |
| `inventory_movement_created` | Inventory Movement (receive, adjustment, transfer, opname) |
| `inventory_batch_created` | Inventory Batch |

---

## Not Logged Yet

- `purchase_order_rejected` — workflow does not exist
- `inventory_batch_received` — there is no separate workflow
- `inventory_batch_updated` — batch service is read-only
- PR → PO → GR `correlation_id` chain not fully propagated yet

---

## Permission

**Primary:** `view_inventory_activity_log`

**Fallback (backward compatibility):**

- `view_inventory`
- `manage_inventory`
- `view_inventory_analytics`

Permission muncul di Role Management di grup **Inventory / Persediaan**.

---

## UI

| Route | Purpose |
|---|---|
| `/inventory/activity-logs` | Index dengan filter action, user, tanggal, correlation_id, search |
| `/inventory/activity-logs/{id}` | Detail log dengan metadata JSON |

Sidebar: **Log Aktivitas** (permission-gated).

---

## Tests

**Full-suite verification (recorded at Sprint 16.6 Step 6 completion):**

| Gate | Result |
|---|---|
| `php artisan optimize:clear` | PASS |
| `php artisan migrate:fresh --seed` | PASS |
| `php artisan test` | PASS — **1121 tests**, 3778 assertions |
| `./vendor/bin/pint` | PASS |
| `npm run build` | PASS |

**Regression suites verified PASS:**

- `InventoryActivityLogTest`
- `InventoryActivityLogWorkflowTest`
- `InventoryPermissionHardeningTest`
- `RoleManagementTest`
- Purchase Request tests
- Purchase Order tests
- Goods Receipt tests
- Stock Transfer tests
- Stock Opname tests
- `InventoryStockServiceTest`
- Branch isolation tests

---

## Deployment Notes

1. Migration required: `2026_06_09_100000_create_inv_inventory_activity_logs_table`
2. `PermissionSeeder` required for `view_inventory_activity_log`
3. Cache rebuild required after deploy (`config:cache`, `route:cache`, `view:cache`)
4. Frontend rebuild required (`npm run build`)

---

## Risks / Follow-up

- **Volume log movement** — high-frequency `inventory_movement_created` may grow table quickly; consider retention/archival policy
- **Correlation chain** — PR → PO → GR end-to-end `correlation_id` propagation is incremental; not fully wired in 16.6
- **Branch selector** — Activity Log UI uses active `BranchContext`; cross-branch selector not exposed yet
- **Export** — CSV/PDF export for activity logs not implemented yet
