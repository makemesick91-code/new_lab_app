# Sprint 24 Phase 24.1 — RME Partial Payment / Cicilan Foundation

## Goal

Enable RME invoices to accept partial/cicilan payments safely while preserving the full-payment workflow, branch isolation, cashier billing rules, and RME-to-lab integration safety.

## Scope Delivered

- Added `PARTIAL` status for RME invoices.
- Allowed RME invoices with `UNPAID` or `PARTIAL` status to receive payment.
- Allowed multiple `trx_rme_payments` records for one RME invoice.
- Rejected payment amount less than or equal to zero.
- Rejected payment amount greater than remaining invoice balance.
- Kept invoice status as `PARTIAL` when cumulative paid amount is less than `grand_total`.
- Set invoice status to `PAID` only when cumulative paid amount fully covers the invoice.
- Kept clinic visit in `CASHIER_PENDING` while invoice is still partial.
- Transitioned clinic visit to `COMPLETED` only after invoice is fully paid.
- Prevented lab candidate generation during partial payment.
- Preserved lab candidate generation only after invoice becomes `PAID`.
- Updated cashier payment UI to show:
  - Grand total
  - Paid amount
  - Remaining balance
  - Partial/cicilan payment guidance
- Updated invoice detail UI to show payment history.
- Preserved branch isolation for active RME-enabled branches only.

## Files Changed

- `app/Modules/RmeInvoice/Models/RmeInvoice.php`
- `app/Modules/RmeInvoice/Services/RmePaymentService.php`
- `app/Modules/RmeInvoice/Controllers/RmePaymentController.php`
- `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php`
- `app/Modules/RmeInvoice/Repositories/RmeInvoiceRepository.php`
- `app/Modules/ClinicVisit/Models/ClinicVisit.php`
- `database/factories/RmeInvoiceFactory.php`
- `resources/views/rme/cashier/payment/create.blade.php`
- `resources/views/rme/cashier/show.blade.php`
- `tests/Feature/RME/RmePaymentTest.php`
- `tests/Feature/RME/LabIntegrationTest.php`
- `tests/Feature/Pilot/RmeLabCandidateE2EValidationTest.php`

## Business Rules

### Partial Payment

When cashier records a payment lower than remaining invoice balance:

- Payment is stored.
- Invoice status becomes `PARTIAL`.
- Visit remains `CASHIER_PENDING`.
- Lab candidate generation is not triggered.
- Remaining balance is calculated from `grand_total - sum(payments.amount)`.

### Full Payment

When cashier records a payment equal to remaining invoice balance:

- Payment is stored.
- Invoice status becomes `PAID`.
- Visit moves to `COMPLETED`.
- Eligible RME lab candidates may be generated.

### Overpayment Guard

Payment is rejected when amount is greater than remaining balance.

### Invalid Amount Guard

Payment is rejected when amount is zero or negative.

## Database Migration

No migration required.

`trx_rme_invoices.status` already uses a string column and can safely store `PARTIAL`.

## Validation

- `php -l` passed for updated PHP files.
- `php artisan view:clear` passed.
- `php artisan view:cache` passed.
- `./vendor/bin/pint --dirty` passed.
- `php artisan test --filter=RmePayment` passed: 19 tests / 37 assertions.
- `php artisan test --filter=LabIntegrationTest` passed: 11 tests / 25 assertions.
- `php artisan test --filter=RmeLabCandidateE2EValidationTest` passed: 10 tests / 61 assertions.
- `php artisan test --filter=CashierBilling` passed: 21 tests / 42 assertions.
- `php artisan test --filter=RME` passed before final naming polish: 553 tests / 1564 assertions.

## Operational Notes

For patient control/follow-up with installment:

- Create a new clinic visit for the control visit.
- Do not edit the old visit.
- Do not create a duplicate invoice for the old treatment.
- Record installment payment against the existing RME invoice.
- If the control visit has a new chargeable treatment, create a new invoice for the new visit.
