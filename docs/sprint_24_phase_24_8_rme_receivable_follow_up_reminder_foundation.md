# Sprint 24 Phase 24.8 — RME Receivable Follow-up / Reminder Foundation

## Goal

Add a foundation for recording follow-up / reminder actions against active RME
receivables (UNPAID / PARTIAL invoices) on top of the existing Piutang RME
dashboard, **without changing any payment or invoice status logic**.

## Scope

- Follow-up history per RME invoice (tracking only).
- Latest follow-up summary + next reminder/due indicator on the Piutang RME page.
- Follow-up create/store flow reachable from the Piutang RME action column.
- Follow-up status and channel value sets.
- No WhatsApp / SMS / email sending. No scheduler. No payment changes.

## Migration / Table

`database/migrations/2026_06_18_100001_create_trx_rme_receivable_follow_ups_table.php`

Table `trx_rme_receivable_follow_ups` (additive only):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | PK |
| `rme_invoice_id` | FK → `trx_rme_invoices` | cascade on delete |
| `branch_id` | FK → `mst_branches`, nullable | null on delete |
| `user_id` | FK → `users`, nullable | null on delete |
| `status` | string(40) | required |
| `channel` | string(40), nullable | |
| `contacted_at` | timestamp, nullable | |
| `next_follow_up_date` | date, nullable | drives reminder indicator |
| `note` | text, nullable | |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(rme_invoice_id, created_at)`, `(branch_id, next_follow_up_date)`, `(status)`.

## Model / Controller / Request / View / Routes

- Model: `app/Modules/RmeInvoice/Models/RmeReceivableFollowUp.php`
  - fillable, casts (`contacted_at` datetime, `next_follow_up_date` date),
    relations `invoice()`, `branch()`, `user()`, status/channel constants.
- `RmeInvoice` model: added `followUps()` (HasMany) and `latestFollowUp()`
  (HasOne `latestOfMany`). No payment logic touched.
- Request: `app/Modules/RmeInvoice/Requests/StoreRmeReceivableFollowUpRequest.php`.
- Controller: `app/Modules/RmeInvoice/Controllers/RmeReceivableFollowUpController.php`
  - `create(RmeInvoice $rmeInvoice)` and `store(...)`.
  - Authorizes via `RmeInvoicePolicy@view` (manage + active RME branch),
    then `abort_unless($invoice->isPayable())` for active-receivable gating.
  - On store: copies `branch_id` from invoice, `user_id` from auth user,
    redirects to `rme.cashier.receivables`.
- View updated: `resources/views/rme/cashier/receivables.blade.php`
  - New columns "Follow-up Terakhir" and "Reminder Berikutnya" + due badge.
  - New action "Tambah Follow-up" (only for payable invoices).
- New view: `resources/views/rme/cashier/follow-ups/create.blade.php`
  - Form (Status, Channel, Tanggal Kontak, Reminder Berikutnya, Catatan)
    + follow-up history list.
- `RmeInvoiceController@receivables` eager-loads `latestFollowUp.user` (no N+1).
- Routes (under existing `rme.` prefix + `permission:manage_rme_billing` group):
  - `GET  rme/cashier/receivables/{rmeInvoice}/follow-ups/create`
    → `rme.cashier.receivables.follow-ups.create`
  - `POST rme/cashier/receivables/{rmeInvoice}/follow-ups`
    → `rme.cashier.receivables.follow-ups.store`

## Status values

`NEW`, `CONTACTED`, `PROMISED`, `FOLLOW_UP_LATER`, `ESCALATED`, `CLOSED`.
Closing a follow-up never closes/pays the invoice.

## Channel values

`WHATSAPP`, `PHONE`, `IN_PERSON`, `OTHER` (nullable). Tracking only — no sending.

## Permission rules

- Reuses `manage_rme_billing` (same as Piutang RME / cashier billing).
- Authorization via `RmeInvoicePolicy@view`: manage permission **and** invoice
  belongs to an active RME-enabled branch.
- Follow-up create/store rejected (403) for non-payable invoices
  (`PAID`, `VOID`, `DRAFT`).

## Branch-aware behavior

- `branch_id` is copied from the invoice, never user-supplied.
- Cross-branch / non-RME-branch invoices are forbidden via the policy.

## Due / reminder indicator

Computed from `latestFollowUp.next_follow_up_date`:

- past date → "Jatuh Tempo"
- today → "Hari Ini"
- future → "Terjadwal"
- none → "Belum Ada"

## Validation commands

```bash
php artisan test --filter=RmeReceivableFollowUpTest
php artisan test --filter=CashierBillingTest
php artisan route:list | grep "rme.cashier.receivables"
php artisan view:clear && php artisan view:cache
./vendor/bin/pint --dirty
git diff --check
```

Result: RmeReceivableFollowUpTest 9 passed (18 assertions);
CashierBillingTest 28 passed (74 assertions).

## Out of scope

- No WhatsApp sending / gateway integration.
- No external reminder/notification service.
- No payment posting or invoice paid/partial/unpaid transition changes.
- No automated scheduler / cron reminders yet.
- No VPS smoke yet.
