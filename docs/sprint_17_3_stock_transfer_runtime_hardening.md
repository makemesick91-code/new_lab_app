# Sprint 17.3 - Stock Transfer Runtime Hardening

Date: 2026-06-07
Status: Implemented
Module: `app/Modules/Inventory`

## What Changed

Sprint 17.3 hardens the existing Stock Transfer runtime behavior without changing schema, routes, statuses, movement types, or UI workflow.

Preserved workflow:

```text
draft -> submitted -> in_transit -> received
```

Preserved movement types:

```text
TRANSFER_OUT
TRANSFER_IN
```

Preserved inventory rule:

```text
Stock = SUM(quantity_in) - SUM(quantity_out)
```

No mutable stock columns were added. No PDF package was installed. No PDF checklist was created.

## receiveTransfer Guard

`StockTransferService::receiveTransfer()` now verifies the transfer ledger before creating any `TRANSFER_IN` rows.

The receive action still requires:

- Active branch from `BranchContext::requireId()`.
- Transfer locked inside a database transaction.
- Status `in_transit`.
- Active source and destination locations in the same branch.
- At least one transfer item.
- Active products in the same branch.

New runtime integrity guard:

- Existing transfer movements are locked for update.
- A valid `TRANSFER_OUT` row set must already exist for the same transfer reference.
- Outbound ledger rows must match the transfer document by source location, product, nullable batch id, branch, reference type, and reference id.
- Outbound quantities must be positive outbound quantities only.
- Expected transfer item quantities are compared against actual `TRANSFER_OUT` totals by `product_id + inventory_batch_id`.

If the outbound ledger is missing or inconsistent, receive is rejected with a `ValidationException`.

## Duplicate Receive Protection

`receiveTransfer()` now rejects an `in_transit` transfer if any existing `TRANSFER_IN` movement already exists for the same transfer reference.

This protects against:

- Accidental duplicate receive calls.
- Corrupted status/ledger combinations.
- Manual data repair mistakes.
- Concurrent edge cases where status alone is not enough.

The existing status guard still rejects transfers already marked `received` or legacy `completed`.

## Movement Consistency Guard

The new consistency guard verifies:

- `TRANSFER_OUT` exists.
- `TRANSFER_OUT` is tied to the same transfer via `reference_type` and `reference_id`.
- `TRANSFER_OUT.branch_id` matches the transfer branch.
- `TRANSFER_OUT.inventory_location_id` matches the source location.
- `TRANSFER_OUT.product_id` matches transfer item products.
- `TRANSFER_OUT.inventory_batch_id` matches transfer item batch identity when present.
- `TRANSFER_OUT.quantity_out > 0`.
- `TRANSFER_OUT.quantity_in = 0`.
- Proposed full receive does not exceed total valid outbound quantity.

Partial receive remains out of scope, so the expected receive remains a one-time full receive of the shipped transfer quantities.

## Controller / Read-Layer Refactor

`StockTransferController::show()` no longer queries `InventoryMovement` directly.

New read-layer methods:

- `InventoryMovementRepositoryInterface::transferMovements(...)`
- `InventoryMovementRepositoryInterface::lockTransferMovementsForUpdate(...)`
- `StockTransferService::getTransferMovementSummary(...)`

The show controller now asks `StockTransferService` for a movement summary and passes the existing `ledgerMovements` collection to the Blade view. The view did not need a large redesign.

Movement summary includes:

- `ledger_movements`
- `transfer_out_movements`
- `transfer_in_movements`
- `total_out`
- `total_in`
- `in_transit_qty`

## Tests Added / Updated

Updated:

- `tests/Feature/Inventory/StockTransferHardeningTest.php`

Added coverage for:

- Receive rejects `in_transit` transfer when `TRANSFER_OUT` ledger is missing.
- Receive rejects mismatched outbound product ledger.
- Receive rejects existing `TRANSFER_IN` ledger on an `in_transit` transfer.
- Duplicate receive does not add a second `TRANSFER_IN`.
- Movement summary returns outbound/inbound totals and in-transit quantity.
- Show page still opens and displays ledger references through the read-layer path.
- Controller source no longer contains direct `InventoryMovement::query()` usage for show.

Existing StockTransfer tests continue to cover:

- Status guard rejection for non-`in_transit` receive.
- Successful valid receive creates `TRANSFER_IN`.
- Branch isolation.
- Batch propagation.
- No mutable stock columns.
- No legacy `complete` route.

## Quality Gate Result

Commands run:

```bash
php artisan test --filter=StockTransfer
php artisan test --filter=Inventory
vendor/bin/pint --test
```

Final results:

- `php artisan test --filter=StockTransfer` passed: 102 tests, 523 assertions, duration about 27.27 seconds.
- `php artisan test --filter=Inventory` passed: 906 tests, 3673 assertions, duration about 214.63 seconds.
- `vendor/bin/pint --test` passed.

Note:

- An initial focused StockTransfer run caught a strict type mismatch in `in_transit_qty` returning integer `0` instead of float `0.0`; it was fixed and the focused suite was rerun successfully.

## Remaining Out Of Scope

Still out of scope after Sprint 17.3:

- PDF checklist implementation.
- `barryvdh/laravel-dompdf` installation.
- Partial receive.
- Transfer discrepancy workflow.
- Reversal or cancel after shipped.
- Inter-branch transfer.
- `from_branch_id` / `to_branch_id`.
- Schema changes.
- Workflow/status renames.

