# Sprint 17.2 - Stock Transfer Hardening Design

Date: 2026-06-07
Status: Design only
Module: `app/Modules/Inventory`

## Scope

Sprint 17.2 is a design-only hardening step for the existing ADLMS Stock Transfer workflow.

Create in this step:

- `docs/sprint_17_2_stock_transfer_hardening_design.md`

Do not create or change in this step:

- Migrations
- Routes
- Controllers
- Services
- Repositories
- Requests
- Policies
- Blade UI
- Tests
- Composer dependencies
- PDF packages

This document is not a reimplementation plan for Stock Transfer. Stock Transfer already exists and must remain an incremental hardening target.

## Current Baseline

Stock Transfer is currently implemented inside the Inventory module as a same-branch, inter-location transfer workflow.

Current workflow:

```text
draft -> submitted -> in_transit -> received
draft/submitted -> cancelled
```

Current ledger movement types:

```text
TRANSFER_OUT
TRANSFER_IN
```

Current branch model:

- Transfer header uses one `branch_id`.
- Source and destination are inventory locations inside the same branch.
- Current scope does not use `from_branch_id` or `to_branch_id`.

Current stock model:

- Stock remains ledger-only.
- `trx_inventory_movements` is the stock source of truth.
- Current stock is derived as `SUM(quantity_in) - SUM(quantity_out)`.
- No mutable stock, `current_stock`, `qty_on_hand`, or similar columns may be added.

Current ship/receive behavior:

- `submitTransfer()` changes document status only.
- `shipTransfer()` posts `TRANSFER_OUT` at the source location and moves the transfer to `in_transit`.
- `receiveTransfer()` posts `TRANSFER_IN` at the destination location and moves the transfer to `received`.
- Cancel after `in_transit` remains blocked.
- Partial receive and reversal/cancel after shipped remain out of scope.

## Non Goals

Sprint 17.2 must not introduce:

- Inter-branch transfer.
- `from_branch_id` or `to_branch_id`.
- A replacement stock transfer workflow.
- A single-step complete action.
- Partial receive.
- Receive discrepancy handling.
- In-transit reversal or cancel after shipped.
- Mutable stock columns.
- PDF implementation or package installation.

Full inter-branch transfer is future work and requires explicit schema design, service design, branch authorization design, ledger accounting design, UI design, and tests before implementation.

## Design Goal

The future implementation should harden `StockTransferService::receiveTransfer()` so receiving is allowed only when the existing shipped ledger rows are internally consistent with the transfer document.

Today, status `in_transit` is treated as the main proof that shipping occurred. The hardening design adds an explicit ledger integrity check before any `TRANSFER_IN` movement can be created.

## Receive Ledger Integrity Rules

Before posting `TRANSFER_IN`, the future implementation must verify all of the following inside the same database transaction that receives the transfer.

### Transfer State

- Transfer must belong to the active branch resolved by `BranchContext::requireId()`.
- Transfer must be locked with `lockForUpdate()`.
- Transfer status must be `in_transit`.
- Transfer must have at least one item.
- Destination location must be active and in the same branch.
- Source location must still match the transfer header and belong to the same branch.

### Existing `TRANSFER_OUT` Movements

Existing outbound rows must be queried and locked by:

- `branch_id = transfer.branch_id`
- `reference_type = transfer.getTable()` / `trx_stock_transfers`
- `reference_id = transfer.id`
- `movement_type = TRANSFER_OUT`

Every outbound row must satisfy:

- Same branch as the transfer.
- Same transfer reference type and id.
- `inventory_location_id = transfer.source_inventory_location_id`.
- `product_id` belongs to the transfer item product set.
- `quantity_out > 0`.
- `quantity_in = 0`.

If batch tracking is present on transfer lines, outbound rows must also match the expected `inventory_batch_id` for the product/batch line key. This preserves the current Sprint 15.3 batch contract without expanding scope.

### Expected Product And Quantity Set

The service should build the expected shipped set from `trx_stock_transfer_items`, aggregated by:

```text
product_id + inventory_batch_id
```

If a future implementation intentionally ignores batch identity for non-batch products, it must still aggregate by product and preserve current nullable batch behavior.

For each expected line key:

- Total `TRANSFER_OUT.quantity_out` must equal the transfer item quantity.
- Total `TRANSFER_OUT.quantity_in` must be zero.
- There must be no missing expected product.
- There must be no unexpected outbound product or batch key.
- Outbound location must be the source location, not destination or another location.

### Duplicate Receive Guard

Receiving must be rejected if any existing `TRANSFER_IN` movement already exists for the same transfer reference:

```text
branch_id = transfer.branch_id
reference_type = transfer.getTable()
reference_id = transfer.id
movement_type = TRANSFER_IN
```

This guard is required even though status should already reject duplicate receive. It protects against corrupted state, manual data repair mistakes, and concurrent edge cases.

### Inbound Must Not Exceed Outbound

Before creating inbound rows, the future implementation must verify:

```text
existing TRANSFER_IN total + proposed TRANSFER_IN total <= existing TRANSFER_OUT total
```

Because partial receive remains out of scope, the normal target is stricter:

```text
proposed TRANSFER_IN total = expected transfer item total = existing TRANSFER_OUT total
```

The service should compare totals per `product_id + inventory_batch_id`, not only at document total level, so one product cannot over-receive while another is under-received.

### Failure Behavior

If any integrity check fails:

- Throw `ValidationException`.
- Do not create any `TRANSFER_IN` movement.
- Do not change transfer status.
- Leave the transfer in `in_transit`.
- Keep existing `TRANSFER_OUT` movements unchanged.

Suggested Indonesian messages:

- `Transfer stok belum memiliki ledger keluar yang valid.`
- `Ledger keluar transfer tidak sesuai dengan dokumen transfer.`
- `Transfer stok sudah memiliki ledger masuk dan tidak bisa diterima ulang.`
- `Jumlah ledger masuk transfer tidak boleh melebihi ledger keluar.`

## Future Implementation Shape

The future code change should remain small and layered.

### Service

Owner:

- `app/Modules/Inventory/Services/StockTransferService.php`

Future service method:

- Keep `receiveTransfer(int $transferId): StockTransfer`.
- Add a private receive integrity helper or delegate to a repository/read service.
- Keep all receive checks and writes inside `DB::transaction()`.
- Preserve existing `BranchContext::requireId()` usage.
- Preserve `lockForUpdate()` on transfer, locations, items, products, batches, and relevant movement rows.

Suggested internal flow:

```text
lock transfer in active branch
validate status = in_transit
lock source and destination locations
lock transfer items
assert receive ledger integrity from existing TRANSFER_OUT rows
assert no existing TRANSFER_IN rows
create TRANSFER_IN rows at destination
update status to received
log activity and created movements
```

### Repository / Read Service

Queries for transfer ledger movements should not live in the controller.

Future implementation should add repository/read-service methods such as:

- `InventoryMovementRepositoryInterface::transferMovements(int $branchId, StockTransfer $transfer): Collection`
- `InventoryMovementRepositoryInterface::lockTransferMovementsForUpdate(int $branchId, StockTransfer $transfer): Collection`

Alternative acceptable ownership:

- Add transfer-specific read methods to `StockTransferRepositoryInterface` if the team prefers the transfer repository to own transfer document read models.

The key architecture requirement is that `StockTransferController::show()` should no longer query `InventoryMovement` directly in a future implementation. It should receive prepared ledger movements from a repository/read service through the controller constructor.

### Controller

Future controller hardening:

- Keep controller thin.
- Keep authorization through `StockTransferPolicy`.
- Replace the direct `InventoryMovement::query()` in `StockTransferController::show()` with a repository/read-service call.
- Preserve current branch-scoped behavior and `isInTransit()` / `isReceived()` ledger panel visibility.

### Tests

No tests are added in this design-only step.

Future implementation should add focused tests under `tests/Feature/Inventory`, preferably in `StockTransferServiceTest.php` or `StockTransferHardeningTest.php`.

Required future tests:

- Receive succeeds when matching `TRANSFER_OUT` rows exist.
- Receive fails when no `TRANSFER_OUT` exists for an `in_transit` transfer.
- Receive fails when `TRANSFER_OUT.reference_type` or `reference_id` does not match the transfer.
- Receive fails when `TRANSFER_OUT.branch_id` does not match transfer branch.
- Receive fails when `TRANSFER_OUT.inventory_location_id` is not the source location.
- Receive fails when outbound product IDs do not match transfer items.
- Receive fails when outbound quantities do not match transfer item quantities.
- Receive fails when `TRANSFER_IN` already exists for the same transfer.
- Receive fails when existing plus proposed `TRANSFER_IN` would exceed `TRANSFER_OUT`.
- Controller show still displays branch-scoped ledger movements through repository/read service after the direct query is moved.

All tests must preserve ledger-derived stock and branch isolation.

## PDF Checklist Design

PDF generation is not implemented in Sprint 17.2.

If a future step adds a printable transfer checklist, the document should be designed for current same-branch, inter-location scope with this title:

```text
Checklist Pengiriman Barang Antar Lokasi
```

The wording should avoid inter-branch language for now. Recommended content:

- Transfer number.
- Transfer date.
- Active branch name.
- Source location.
- Destination location.
- Status.
- Shipped by / shipped at.
- Received by / received at when available.
- Item rows: product code, product name, unit, batch/lot if present, quantity shipped, quantity received confirmation.
- Operator checklist section for packing, quantity verification, source handoff, destination receipt, and notes.
- Signature placeholders for sender and receiver.

Recommended future PDF package:

- `barryvdh/laravel-dompdf`

Reason:

- It is Laravel-oriented.
- It can render Blade-based documents.
- It avoids external binaries, which is safer for the current Windows/local and single-server deployment profile.

Do not install `barryvdh/laravel-dompdf` until a future implementation step explicitly approves dependency changes.

## Future Inter-Branch Transfer

Full inter-branch transfer remains future work.

It must not be simulated by adding `from_branch_id` / `to_branch_id` casually to the current transfer workflow. A future design must answer:

- Whether transfer header keeps `branch_id` as owner or introduces explicit source/destination branch ownership.
- How source branch authorization and destination branch authorization are both enforced.
- Whether source and destination users can act from different active branches.
- How ledger rows are posted for both branches.
- Whether inter-branch transfer affects costing, audit, procurement, or reporting.
- How cross-branch visibility is gated.
- Which indexes and constraints are needed.
- Which tests prove no branch data leaks.

Until that design is approved, current Stock Transfer remains same-branch inter-location transfer only.

## Architecture Consistency Check

Sprint 10 Branch Context:

- Future implementation must resolve active branch through `BranchContext::requireId()`.
- No request field may decide branch ownership.

Sprint 11 Branch Enforcement:

- Transfer headers, locations, products, batches, and movements must be branch-scoped.
- Route-bound transfer models must remain policy-authorized.

Sprint 12 Inventory Core:

- Stock remains `SUM(quantity_in) - SUM(quantity_out)` from `trx_inventory_movements`.
- No mutable stock columns are allowed.
- Ledger rows remain append-only quantity history.

Sprint 15.2 Transfer Receiving:

- Preserve `draft -> submitted -> in_transit -> received`.
- Preserve ship as `TRANSFER_OUT` only.
- Preserve receive as `TRANSFER_IN` only.
- Preserve duplicate ship/receive guards.
- Preserve cancel blocked after ship.
- Do not restore the old `complete` route/action.

Sprint 15.3 Batch & Lot:

- Preserve nullable `inventory_batch_id` propagation on transfer item and movement rows.
- Do not create mutable batch stock.

## Files Reviewed For This Design

- `docs/project_rules.md`
- `docs/system_architecture.md`
- `docs/architecture_rules.md`
- `docs/ai_development_guide.md`
- `docs/inventory_rules.md`
- `docs/ui_design_system.md`
- `docs/sprint_history.md`
- `docs/ai_bootstrap_prompt.md`
- `docs/sprint_15_2_transfer_receiving_design.md`
- `docs/sprint_17_1_stock_transfer_audit.md`
- `.cursor/memory/project.md`
- `.cursor/memory/inventory.md`
- `app/Modules/Inventory/Services/StockTransferService.php`
- `app/Modules/Inventory/Controllers/StockTransferController.php`
- `app/Modules/Inventory/Repositories/StockTransferRepository.php`
- `app/Modules/Inventory/Interfaces/StockTransferRepositoryInterface.php`
- `app/Modules/Inventory/Repositories/InventoryMovementRepository.php`
- `app/Modules/Inventory/Interfaces/InventoryMovementRepositoryInterface.php`
- `app/Modules/Inventory/Models/StockTransfer.php`
- `app/Modules/Inventory/Models/InventoryMovement.php`
- `tests/Feature/Inventory/StockTransferServiceTest.php`
- `tests/Feature/Inventory/StockTransferControllerTest.php`
- `tests/Feature/Inventory/StockTransferHardeningTest.php`

Memory files requested but not present:

- `.cursor/memory/architecture.md`
- `.cursor/memory/sprint-history.md`

## Sprint 17.2 Definition Of Done

Sprint 17.2 design is complete when:

- This document exists.
- No application implementation files were changed.
- No migrations, routes, controllers, services, UI, tests, or dependencies were created.
- `vendor/bin/pint --test` was run and reported.
- `php artisan test --filter=Inventory` was run and reported, or Stock Transfer tests were run first if the full Inventory filter was too slow.

