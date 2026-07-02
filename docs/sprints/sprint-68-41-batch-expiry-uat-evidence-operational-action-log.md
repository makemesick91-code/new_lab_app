# Sprint 68.41 — Batch Expiry UAT Evidence & Operational Action Log

## Goal

After Sprints 68.38–68.40 (automatic batch numbers, batch-aware transfer/opname, expiry alerts, FEFO visibility), add a minimal **operational action log** so warehouse/admin can record what action was taken for near-expiry/expired batches — **without changing stock or ledger formula**. Produce UAT/evidence documentation for the batch expiry workflow.

## Baseline

| Sprint | Theme |
|--------|--------|
| 68.38 | Auto batch number from expiry date on goods receipt |
| 68.39 | Batch-aware stock transfer & stock opname UX |
| 68.40 | Batch expiry alerts, FEFO suggestion, expiry badges/filters |
| **68.41** | Operational action log + UAT evidence pack |

Deployed baseline before this sprint: merge `4f32a1b` / tag `sprint-68-40-batch-expiry-alert-fefo-visibility-polish-go`.

## Scope delivered

- Additive table `trx_inventory_batch_action_logs` (audit-only `ledger_quantity_snapshot`).
- `InventoryBatchActionLog` model, repository, service, policy (`recordAction`), form request, controller.
- Route `POST inventory/batches/{inventoryBatch}/action-logs` → `inventory.batches.action-logs.store`.
- UI on batch detail (form + history) and inventory alerts expiry section (latest action + link to record).
- Indonesian action labels: Perlu Digunakan Segera, Karantina, Dikembalikan ke Supplier, Rencana Pemusnahan, Dirilis / Aktif Kembali, Catatan.
- Warning copy: *Catatan tindakan tidak mengubah stok. Pengurangan stok tetap harus melalui proses adjustment/opname resmi.*

## Out of scope (non-negotiable)

- No mutable stock columns (`current_stock`, `qty_on_hand`, etc.).
- No ledger formula change; stock remains `SUM(quantity_in) - SUM(quantity_out)` on `trx_inventory_movements`.
- No automatic TRANSFER/ADJUSTMENT movements from action log.
- No auto stock adjustment on quarantine/disposal-planned.
- No WhatsApp notifications.
- No `branch_id` from request on writes — `BranchContext::requireId()` only.

## UAT checklist

| # | Scenario | Expected | Evidence |
|---|----------|----------|----------|
| 1 | Goods Receipt automatic batch number | AUTO-* batch from expiry on GR post | Sprint 68.38 tests / manual GR |
| 2 | Batch-tracked product expiry date | Expiry stored on `inv_inventory_batches` | Batch show |
| 3 | Batch/Lot expiry badge | Kedaluwarsa / Akan Kedaluwarsa / Aktif | Batch index/show |
| 4 | Expiry status filter | Filter expired / near_expiry on batch index | `Sprint6840BatchExpiryAlertFefoVisibilityTest` |
| 5 | Inventory Alerts expiry section | Dedicated batch expiry table on alerts page | Alerts index |
| 6 | Alert only for ledger-positive batch stock | Zero-stock batches excluded | Alert service + tests |
| 7 | Branch-scoped alert visibility | Other branch batches not visible | Branch isolation tests |
| 8 | Stock Transfer batch selection | Batch dropdown per line | Transfer form |
| 9 | FEFO suggestion label | FEFO hint on earliest-expiry batch | Transfer UX |
| 10 | Expired batch warning | Warning on transfer for expired batch | Sprint 6840 tests |
| 11 | Stock Opname one line per available batch | Opname lines per batch at location | Opname show |
| 12 | Stock Card batch filter shows expiry | Expiry in stock card batch filter | Stock card view |
| 13 | **Operational action log create/view** | Record action; history visible; stock unchanged | `Sprint6841InventoryBatchActionLogTest` |

## Evidence placeholders

### Route smoke

```bash
php artisan route:list | rg "inventory.*batch|batch.*action|alert|expiry"
```

Expected: `inventory.batches.action-logs.store`, existing batch/alert routes.

### Feature tests

```bash
DB_CONNECTION=pgsql php artisan test --filter=InventoryBatchActionLog
DB_CONNECTION=pgsql php artisan test --filter=BatchExpiry
```

### Browser smoke (optional)

- Login as inventory manager → Inventory Alerts → expiry section → Catat Tindakan link.
- Batch detail → Catat Tindakan form → submit → history panel updates.
- Confirm stock movement count unchanged after action log.

### Deploy evidence (post-merge)

- GO tag: `sprint-68-41-batch-expiry-uat-evidence-operational-action-log-go`
- `php artisan migrate --force` on VPS (backup first)
- Route/list smoke on VPS

## Safety notes

- Action log is **append-only operational audit** — not stock source of truth.
- `ledger_quantity_snapshot` captured at action time for audit only.
- Disposal still requires official adjustment/opname workflow outside this sprint.
- Policies: `recordAction` requires `manage_inventory_batch_lot` (or manage_inventory / manage master data) + active branch match.

## Files (implementation)

- `database/migrations/2026_07_03_110001_create_trx_inventory_batch_action_logs_table.php`
- `app/Modules/Inventory/Models/InventoryBatchActionLog.php`
- `app/Modules/Inventory/Enums/InventoryBatchActionType.php`
- `app/Modules/Inventory/Services/InventoryBatchActionLogService.php`
- `app/Modules/Inventory/Repositories/InventoryBatchActionLogRepository.php`
- `app/Modules/Inventory/Controllers/InventoryBatchActionLogController.php`
- `app/Modules/Inventory/Requests/StoreInventoryBatchActionLogRequest.php`
- `resources/views/inventory/batches/_batch-action-*.blade.php`
- `tests/Feature/Inventory/Sprint6841InventoryBatchActionLogTest.php`

## Test plan

- [ ] Authorized user creates action log for branch batch
- [ ] BranchContext used (not request branch_id)
- [ ] Unauthorized denied
- [ ] Cross-branch batch denied
- [ ] No new `trx_inventory_movements` row
- [ ] Ledger stock unchanged after log
- [ ] Latest action visible on batch show / alerts
- [ ] Invalid `action_type` rejected

## Quality gates

```bash
DB_CONNECTION=pgsql php artisan test --filter=InventoryBatchActionLog
./vendor/bin/pint --dirty
git diff --check
```

npm build: not required (Blade-only UI changes, no JS asset edits).
