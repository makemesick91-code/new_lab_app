# Sprint 13 Inventory Audit - Stock Opname

Date: 2026-06-05

## Audit Scope

Scope: Sprint 13 Stock Opname implementation in the Inventory module.

Inspected:

- Migrations for `trx_stock_opnames` and `trx_stock_opname_items`
- `StockOpname` and `StockOpnameItem` models
- `StockOpnameRepositoryInterface` and `StockOpnameRepository`
- `StockOpnameService`
- `StockOpnamePolicy`
- Stock Opname Form Requests
- Stock Opname controller/routes/views
- Stock Opname model, repository, request, service, and UI-summary tests
- Sprint 12 inventory architecture and inventory rules

## Inventory Rules Verification

| Area | Result | Evidence |
|---|---|---|
| Ledger Compliance | PASS | System quantity comes from `InventoryMovementRepository::currentStock()`. Final stock remains derived from `trx_inventory_movements`. |
| Adjustment Generation | PASS | Adjustment movements are created only in `finalizeOpname()` and only for non-zero variance. |
| Direct Stock Mutation | PASS | No product/location mutable stock field is updated. Variance posts append-only movement rows. |
| Branch Isolation | PASS | Service and repository branch isolation pass, and `StockOpnamePolicy` is registered in `RepositoryServiceProvider`. |
| Duplicate Finalization Protection | PASS | `COMPLETED` and `CANCELLED` states are rejected before posting; duplicate-finalization test passes. |
| Validation Coverage | PASS | Form Requests validate input shape; service validates branch, active records, status transitions, negative counts, duplicate finalization, and shortage stock. |
| Test Coverage | PASS for focused Sprint 13; NOT CLEAN for broader Inventory gate | Sprint 13 focused tests pass. Inventory-focused tests still have four non-Stock-Opname route authorization failures returning 419 CSRF responses. |

## Ledger Compliance

Result: PASS

Checks:

- No mutable stock column was added to Sprint 13 tables.
- `system_quantity` is a historical snapshot, not live stock.
- Current stock is still calculated from `SUM(quantity_in) - SUM(quantity_out)` on `trx_inventory_movements`.
- Stock Opname item quantities are count records, not a stock source of truth.

Important distinction:

```text
trx_stock_opname_items.system_quantity
  = snapshot captured for audit/review

trx_inventory_movements
  = stock source of truth
```

## Adjustment Generation

Result: PASS

Checks:

- `createDraftOpname()` creates snapshots only.
- `updateCountedQuantity()` updates count/variance only.
- `reviewOpname()` updates status only.
- `finalizeOpname()` is the only method that creates adjustment movements.

Movement behavior:

| Variance | Movement |
|---|---|
| Positive | `ADJUSTMENT_IN` |
| Negative | `ADJUSTMENT_OUT` |
| Zero | No movement |

Generated movements use:

- `reference_type = trx_stock_opnames`
- `reference_id = stock_opname.id`

## Direct Stock Mutation

Result: PASS

Checks:

- No direct update to product stock exists.
- No `stock`, `current_stock`, `qty_on_hand`, or equivalent mutable stock source was introduced.
- Finalization writes ledger movements through `InventoryMovementRepositoryInterface`.

## Branch Isolation

Result: PASS

Service/repository result: PASS

- `StockOpnameService` uses `BranchContext::requireId()`.
- Opname mutations use a branch-scoped locked lookup.
- Products and locations are validated through active-branch repository methods.
- `StockOpnameRepository` paginates and finds records using `branch_id`.
- Focused tests prove repository and service branch isolation.

Authorization wiring result: PASS

- `StockOpnamePolicy` exists.
- `StockOpnameController` calls `$this->authorize(...)`.
- `RepositoryServiceProvider` imports `StockOpname` and `StockOpnamePolicy`.
- `RepositoryServiceProvider` maps `StockOpname::class => StockOpnamePolicy::class`.

Impact:

- Policy branch ownership checks are now wired through Laravel Gate registration.
- Service-level branch enforcement remains in place for mutations.

## Duplicate Finalization Protection

Result: PASS

Checks:

- `COMPLETED` opnames throw validation errors on finalize.
- `CANCELLED` opnames cannot be finalized.
- Only `COUNTING` can be finalized.
- Test coverage confirms a second finalize attempt does not create duplicate movements.

## Validation Coverage

Result: PASS

Form Request coverage:

| Request | Coverage |
|---|---|
| `StoreStockOpnameRequest` | Location, date, optional products, notes |
| `UpdateStockOpnameItemRequest` | Required non-negative counted quantity, notes |
| `ReviewStockOpnameRequest` | Optional notes |
| `FinalizeStockOpnameRequest` | Optional notes |
| `CancelStockOpnameRequest` | Required notes |

Service validation coverage:

- Cross-branch location rejected.
- Cross-branch product rejected.
- Inactive product/location rejected.
- Negative counted quantity rejected.
- Empty opname cannot be reviewed/finalized.
- Invalid status transition rejected.
- Shortage finalization checks current location stock.
- Failed finalization rolls back generated movements.

## Test Coverage

Static Pest-style test inventory from repository scan:

| Scope | Count |
|---|---:|
| Total Pest-style tests in `tests` | 375 |
| Inventory Pest-style tests | 59 |
| Sprint 13 Stock Opname tests | 31 |

Executed gates during audit:

| Command | Result |
|---|---|
| `php artisan test tests\Feature\Inventory\StockOpnameModelTest.php tests\Feature\Inventory\StockOpnameRepositoryTest.php tests\Feature\Inventory\StockOpnameRequestTest.php tests\Feature\Inventory\StockOpnameServiceTest.php` | PASS: 31 tests / 99 assertions |
| `php artisan test --filter=StockOpname` | PASS: 31 tests / 99 assertions |
| `php artisan route:list --name=inventory.stock-opnames` | PASS: 9 routes |
| `npm.cmd run build` | PASS |
| `.\vendor\bin\pint` | PASS: formatting applied |
| `php artisan test tests\Feature\Inventory` | NOT CLEAN: 55 passed / 4 failed in non-Stock-Opname route authorization POST tests returning 419 CSRF responses |
| `php artisan test` | FAIL/INCOMPLETE: previously timed out after 120 seconds and showed failures outside Sprint 13 |

Sprint 13 additions cover:

- Model relationships, casts, and status states.
- Repository binding, branch scoping, filters, eager loading.
- Request validation.
- Draft creation from ledger-derived snapshots.
- Count updates and variance calculation.
- Review transition.
- Finalization posting.
- Zero variance behavior.
- Duplicate finalization prevention.
- Read-only after completed/cancelled.
- Branch isolation.
- Cancellation.
- Transaction rollback.
- Variance review screen summary.

Gap:

- No dedicated Stock Opname route authorization test currently proves guest/permission denial and registered policy behavior.

## Findings

1. Stock Opname policy registration finding is resolved.

   Severity: Resolved.

   The provider now registers `StockOpname::class => StockOpnamePolicy::class`.

2. Pint formatting finding is resolved.

   Severity: Resolved.

   `.\vendor\bin\pint` applied formatting to:

   - `app\Modules\Inventory\Controllers\StockOpnameController.php`
   - `tests\Feature\Inventory\StockOpnameRequestTest.php`
   - `tests\Feature\Inventory\StockOpnameServiceTest.php`

3. Inventory-focused suite is not fully clean outside Stock Opname.

   Severity: Medium.

   `InventoryRouteAuthorizationTest` has four non-Stock-Opname POST assertions receiving 419 CSRF responses. The focused Sprint 13 Stock Opname tests pass.

4. Review screen finalization action finding is resolved.

   Severity: Resolved.

   The review screen now renders Finalize only for `COUNTING`, matching `StockOpnameService::finalizeOpname()`.

5. Stock Opname has no lifecycle audit log outside generated movement references.

   Severity: Low.

   Ledger movements reference the opname after finalization, but create/review/cancel lifecycle events are not written to `sys_audit_logs`.

## Recommendations

1. Add route authorization tests for Stock Opname routes, including guest redirect, permission denial, cross-branch route-bound model denial, and manage permission success.
2. Investigate the non-Stock-Opname `InventoryRouteAuthorizationTest` 419 CSRF failures separately from Sprint 13.
3. Consider lifecycle audit logging for create/review/finalize/cancel in a future hardening pass if audit trace beyond the ledger reference is required.
4. Investigate unrelated full-suite failures separately from Sprint 13 documentation; do not claim full release green until they are resolved.

## Audit Conclusion

Sprint 13 preserves the core Sprint 12 inventory architecture: ledger-derived stock, no mutable stock columns, movement-based adjustments, branch/location-aware service rules, policy-backed controller authorization, and transactional finalization.

Inventory compliance is strong at the Stock Opname service, repository, policy, UI, and ledger layers. Release readiness is not fully clean for the wider workspace because non-Stock-Opname Inventory route authorization tests and the full test suite are not currently green.
