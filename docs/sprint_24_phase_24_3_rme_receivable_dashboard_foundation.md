# Sprint 24 Phase 24.3 — RME Receivable / Piutang Dashboard Foundation

## Goal

Sprint 24 Phase 24.3 adds the first RME receivable dashboard foundation for active RME invoices that still have outstanding balances.

This phase is focused on cashier/owner visibility after Sprint 24.1 introduced partial payments and Sprint 24.2 confirmed the VPS smoke test.

## Scope

Included:

- New Piutang RME page.
- Active receivable invoice list for `UNPAID` and `PARTIAL`.
- Summary cards:
  - Jumlah Invoice
  - Total Tagihan
  - Sudah Dibayar
  - Sisa Piutang
- Filters:
  - Search by invoice number, patient name, or visit number
  - Branch
  - Status
  - Date range
- Action links:
  - Detail invoice
  - Bayar / Bayar Cicilan
- Sidebar entry under Dashboard RME.
- Feature tests for access, display, filtering, and authorization.

Not included:

- No database migration.
- No change to payment posting logic.
- No change to lab candidate generation logic.
- No export/PDF yet.

## Files Changed

- `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php`
- `routes/web.php`
- `resources/views/rme/cashier/receivables.blade.php`
- `resources/views/layouts/partials/sidebar.blade.php`
- `tests/Feature/RME/CashierBillingTest.php`

## Validation

Targeted checks:

- `php artisan route:list | grep "rme.cashier.receivables"`
- `php artisan test --filter=CashierBilling`
- `php artisan test --filter=RmePaymentTest`
- `php artisan view:cache`
- `./vendor/bin/pint --dirty`

## Expected Business Behavior

The cashier can open Piutang RME and immediately see unpaid and partial RME invoices, including remaining balance and quick action to continue installment payment.

This helps the clinic monitor RME receivables after partial payments are enabled.
