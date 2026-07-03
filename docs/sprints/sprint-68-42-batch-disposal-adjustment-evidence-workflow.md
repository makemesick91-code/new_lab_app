# Sprint 68.42 — Batch Disposal & Adjustment Evidence Workflow

## Baseline

Builds on Sprint 68.38–68.41 (batch expiry, FEFO visibility, operational action logs).

- Sprint 68.41 GO tag: `sprint-68-41-batch-expiry-uat-evidence-operational-action-log-go`
- Baseline commit: `3ec8351954b608dbc5b2e581c86047dec9b54c1a`

## Goal

Convert expired/quarantined/disposal-planned batch decisions into a controlled disposal/return/adjustment request with evidence and approval. Stock decreases **only** through explicit authorized ledger `ADJUSTMENT_OUT` finalization.

## Workflow

1. Expiry alert / batch detail identifies risky batch.
2. Operator records action log (`quarantine`, `disposal_planned`, `return_supplier`, etc.) — Sprint 68.41.
3. Operator creates disposal/return adjustment evidence request.
4. Supervisor/Admin Warehouse approves or rejects (`manage_inventory`).
5. Approved request is finalized into official ledger `ADJUSTMENT_OUT`.
6. Ledger movement is the source of stock reduction evidence.

## Safety Notes

- Request creation does **not** change stock.
- Approval does **not** change stock.
- Finalization creates `ADJUSTMENT_OUT` only through explicit authorized action.
- No mutable stock column added.
- No auto adjustment on expiry or action log.

## Deliverables

### Database

- `trx_inventory_batch_disposal_requests` (additive migration)

### Backend

- `InventoryBatchDisposalRequest` model + enums (type/status)
- `InventoryBatchDisposalWorkflowService`
- `InventoryBatchDisposalRequestPolicy`
- `InventoryBatchDisposalRequestController`
- Form requests: store, reject, filter
- Repository interface + implementation
- `InventoryStockService::adjustOut()` extended with optional `reference_type` / `reference_id`

### Routes

- `inventory.batch-disposal-requests.index`
- `inventory.batch-disposal-requests.show`
- `inventory.batches.disposal-requests.store`
- `inventory.batch-disposal-requests.approve`
- `inventory.batch-disposal-requests.reject`
- `inventory.batch-disposal-requests.finalize-adjustment`
- `inventory.batch-disposal-requests.cancel`

### UI

- Batch detail: disposal request form + active requests list
- Alerts expiry: link to create disposal request
- Disposal request index + show (approval/finalization actions)
- Sidebar: Disposal/Adjustment Batch

## UAT Checklist

1. [ ] Create disposal request from batch detail
2. [ ] Create disposal request from expiry alert link
3. [ ] Evidence note/reference required
4. [ ] Quantity cannot exceed ledger stock for branch/location/product/batch
5. [ ] Unauthorized user blocked
6. [ ] Other branch user blocked
7. [ ] Submit does not create movement
8. [ ] Approve does not create movement
9. [ ] Reject blocks finalization
10. [ ] Finalize approved request creates one `ADJUSTMENT_OUT`
11. [ ] Retry finalization does not duplicate movement
12. [ ] Stock card shows `ADJUSTMENT_OUT` with batch context
13. [ ] Batch detail shows linked disposal workflow status

## Evidence Placeholders

| Item | Value |
|------|-------|
| Migration | `2026_07_03_120001_create_trx_inventory_batch_disposal_requests_table.php` |
| Feature tests | `tests/Feature/Inventory/InventoryBatchDisposalWorkflowTest.php` |
| VPS backup path | `storage/app/backups/deploy/pre_sprint_68_42_batch_disposal_adjustment_*.sql` |
| Deploy commit | _pending merge_ |
| GO tag | `sprint-68-42-batch-disposal-adjustment-evidence-workflow-go` |

## Test Plan

```bash
DB_CONNECTION=pgsql php artisan test --filter=InventoryBatchDisposalWorkflow
DB_CONNECTION=pgsql php artisan test --filter=InventoryBatchActionLog
DB_CONNECTION=pgsql php artisan test --filter=BatchExpiry
./vendor/bin/pint --dirty
```

## Rollback

- Revert merge commit on base branch
- Migration down only if no production disposal requests exist: `php artisan migrate:rollback --step=1` (VPS: after backup)
