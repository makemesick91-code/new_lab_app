# Sprint 27 Phase 27.4 — RME Control Visit Receivable Carry-Over Payment Allocation

## Summary

When a patient returns for a **control visit** and still owes money from a previous visit, the control cashier screen shows both balances. Payment entered at control is allocated **FIFO** to the oldest unpaid/partial parent invoice(s) first; any remainder reduces the control invoice for today.

Invoices remain separate. Parent invoice items are never moved into the control invoice.

## Business rule (FIFO)

1. Collect unpaid/partial invoices from the control visit's parent chain (oldest ancestor first).
2. Apply payment to parent invoice(s) until exhausted or fully paid.
3. Apply any remaining amount to the control invoice.
4. Reject payment above **Total Harus Dibayar** = carry-over remaining + control remaining.
5. Do not create zero-amount payment rows.

## Examples

Parent invoice remaining: **Rp300.000**  
Control invoice remaining: **Rp100.000**  
Total payable: **Rp400.000**

| Payment | Parent result | Control result |
| --- | --- | --- |
| Rp250.000 | PARTIAL, sisa Rp50.000 | UNPAID Rp100.000 |
| Rp300.000 | PAID | UNPAID Rp100.000 |
| Rp350.000 | PAID | PARTIAL, sisa Rp50.000 |
| Rp400.000 | PAID | PAID |

## Technical notes

- **Service:** `App\Modules\RmeInvoice\Services\RmeControlReceivableService`
- **Allocation:** `RmePaymentService::allocateControlPayment()`
- **Migration:** `2026_06_17_100001_add_payment_batch_uuid_to_trx_rme_payments_table.php` (nullable `payment_batch_uuid`)
- **Receipt:** shows **Alokasi Pembayaran** when batch payments exist
- **Lab candidates:** post-commit generation remains idempotent per paid invoice

## Migration notes

- Additive only — safe for VPS pilot (`php artisan migrate --force`)
- No `migrate:fresh`, no truncate, no patient prefix changes

## Manual smoke checklist

1. Create parent visit with UNPAID/PARTIAL invoice.
2. Create control visit from parent.
3. Create control invoice (today's charges only).
4. Open control cashier page — verify **Piutang Kunjungan Sebelumnya** and **Total Harus Dibayar**.
5. Pay less than parent outstanding — parent reduces, control unchanged.
6. Pay more than parent outstanding — parent PAID, remainder hits control.
7. Pay full total — both PAID; visits complete per existing rules.
8. Receipt shows allocation split.
9. Piutang report reflects updated balances.
10. Parent RM/odontogram/invoice items unchanged.

## Safety notes

- No invoice item mutation
- No patient duplication
- No destructive migration
- Branch isolation via RME-enabled branch set
- Consent checkbox still required before payment

## Tests

- `tests/Feature/RME/RmeControlVisitReceivableCarryOverPaymentTest.php` — 26 passed / 77 assertions
- `tests/Browser/RmeControlCashierSmokeTest.php` (Dusk — run after local additive migration)
- Full RME suite (`--filter=RME`): **658 passed / 1977 assertions** (2026-06-17)
- Related filters all green: `RmePayment` (19), `Cashier` (55), `ClinicVisitControlWorkflowTest` (14)

## VPS deploy notes (later)

- Backup DB before pull/migrate.
- Run only `php artisan migrate --force` for `2026_06_17_100001_add_payment_batch_uuid_to_trx_rme_payments_table.php` (additive, nullable).
- Never `migrate:fresh` / `db:wipe`; reset `storage` + `bootstrap/cache` permissions after deploy.
