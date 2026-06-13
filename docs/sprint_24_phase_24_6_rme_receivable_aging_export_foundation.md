# Sprint 24 Phase 24.6 — RME Receivable Aging + Export Foundation

## Goal

Add aging buckets and a CSV export foundation to the existing Piutang RME
(RME Receivable) dashboard introduced in Sprint 24.3.

## Scope

- Aging buckets for active RME receivables (`UNPAID`, `PARTIAL` only).
- Aging summary cards (count + remaining receivable per bucket).
- Optional `aging_bucket` filter that combines with existing filters.
- CSV export of the filtered receivable list (no external package).

## Files changed

- `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php`
  - Refactored `receivables()` to share filter/query helpers.
  - Added `exportReceivables()` (StreamedResponse CSV).
  - Added helpers: `rmeBranches()`, `receivableFilters()`, `receivableQuery()`,
    `invoiceAgeDays()`, `agingBucketForDays()`, `applyAgingBucket()`,
    `receivableRemaining()`, `agingSummary()`.
  - Added `AGING_BUCKETS` constant.
- `resources/views/rme/cashier/receivables.blade.php`
  - Aging summary section, aging filter dropdown, Export CSV button,
    Umur / Bucket column.
- `routes/web.php`
  - New route `rme.cashier.receivables.export`.
- `tests/Feature/RME/CashierBillingTest.php`
  - 4 focused tests.

## Aging buckets

Age measured in days from the invoice date to today.

| Bucket | Days |
| ------ | ---- |
| `0-7`   | 0–7   |
| `8-14`  | 8–14  |
| `15-30` | 15–30 |
| `>30`   | 31+   |

Date source: `trx_rme_invoices.created_at` (no `invoice_date` / `issued_at`
column exists; no migration created).

Remaining balance = `grand_total - sum(payments.amount)`, floored at 0.
Only `UNPAID` and `PARTIAL` invoices in RME-enabled, active branches are
included. `PAID`, `VOID`, `DRAFT` are excluded.

## Export route

- Name: `rme.cashier.receivables.export`
- Path: `GET rme/cashier/receivables/export`
- Controller: `RmeInvoiceController@exportReceivables`
- Middleware/permission: same group as receivables (`permission:manage_rme_billing`)
- Returns: streamed `text/csv` download, filename `piutang-rme-YYYYMMDD-HHMMSS.csv`

## Export columns (Indonesian)

```
No Invoice
Pasien
No Kunjungan
Cabang
Status
Tanggal Invoice
Umur Hari
Bucket Aging
Grand Total
Sudah Dibayar
Sisa Piutang
```

## Filters supported

Both the page and the export accept and combine:

- `search`
- `branch_id`
- `status` (`UNPAID` / `PARTIAL`)
- `date_from`, `date_to` (on `created_at`)
- `aging_bucket` (`0-7`, `8-14`, `15-30`, `>30`)

## Validation commands

```bash
php artisan test --filter=CashierBillingTest
php artisan route:list | grep "rme.cashier.receivables"
php artisan view:clear && php artisan view:cache
./vendor/bin/pint --dirty
git diff --check
```

Result: `CashierBillingTest` 28 passed (74 assertions), both routes registered,
view cache OK, Pint clean, `git diff --check` clean.

## Out of scope

- No migration / no schema change.
- No Excel package (CSV only).
- No payment posting logic change.
- No full test suite run.
- No VPS smoke yet (deferred to a later phase).
