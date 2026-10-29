# SECURITY-F4B1-CLINIC-CALENDAR-INVENTORY-LEDGER-1

**Status:** implemented and verified locally; not merged, not deployed.
**Type:** `RUNTIME_FIX`. **Module:** Inventory. **Base:** canonical after `CICD-GOVERNANCE-EVALUATION-MEMO-1`.
Part 1 of 4 of audit item F-4b. Owner decision **D15 (approved 2026-10-28)**: inventory business dates
use Asia/Makassar; UTC event timestamps and immutable historical ledger records are preserved;
explicitly entered business dates keep their validated meaning; no historical backfill.
No migration, no route, no permission. No stored row is rewritten.

## Why four pull requests

The complete F-4b change moves 83 inventory sites, in 24 application files, onto the clinic calendar.
`sprint:scope-audit` refuses a `RUNTIME_FIX` with more than 10 changed application files, because at that
size it looks like a refactor. F-4b is therefore split by concern, the same way F-4a was split by
module. The type was not changed to pass the gate.

Each part carries the identical `App\Modules\Inventory\Support\InventoryBusinessCalendar`, so each one
merges on its own, and merging two of them adds the same file twice without conflict. Siblings: SECURITY-F4B2-CLINIC-CALENDAR-INVENTORY-EXPIRY-1, SECURITY-F4B3-CLINIC-CALENDAR-INVENTORY-REPORTS-1, SECURITY-F4B4-CLINIC-CALENDAR-INVENTORY-ANALYTICS-1.

## Finding

The application timezone is UTC and the clinic runs on WITA (UTC+8). Between 00:00 and 08:00 WITA
the UTC date is still yesterday, so code that took "today" from `now()` used the previous clinic
day for eight hours of every day. This part covers stock movement and document dates, document numbers, the outbound expiry guard and receipt-date validation.

## Change

`InventoryBusinessCalendar` is not a second clock. `ClinicalClock` stays the single authority for
today's clinic date; the calendar places that date at 00:00 in the application frame, the frame the
inventory date casts (`movement_date`, `expiry_date`, `received_date`) use, so date comparisons and
`diffInDays()` stay in one frame. Comparing a date cast with `ClinicalClock::today()` directly would
mix two frames eight hours apart.

Files: `InventoryStockService.php`, `GoodsReceiptService.php`, `StockOpnameService.php`, `StockTransferService.php`, `PurchaseOrderService.php`, `ValidatesGoodsReceiptBatchInput.php`, `ValidatesInventoryBatchInput.php`, `ValidatesGoodsReceiptInput.php`, `_batch-item-fields.blade.php`, `_form.blade.php`, `_form.blade.php`, `_form.blade.php`, `_batch-fields.blade.php`, `create.blade.php`, `_form.blade.php`.

What does not change, by D15:

- event timestamps (`created_at`, `posted_at`, `approved_at`, ...) stay UTC instants;
- a business date the user enters is stored exactly as entered;
- no stored date is rewritten, and no backfill runs.

## Verification

`tests/Feature/Inventory/InventoryClinicCalendarBoundaryLedgerTest.php` freezes the clock at instants where the UTC date and the clinic date differ (00:01 and 07:59
WITA), plus a control where they agree (23:59 WITA) or a month or year boundary. It fails against the
original code and passes with this change.
