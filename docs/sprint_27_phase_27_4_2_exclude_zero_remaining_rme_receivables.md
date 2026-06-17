# Sprint 27 Phase 27.4.2 — Exclude Zero-Remaining Invoices from Active Receivables

**Type:** Hotfix
**Branch:** `feature/sprint-27-phase-27-4-2-exclude-zero-remaining-rme-receivables`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `15b19c7`
(Phase 27.4.1 merge)
**Migration:** None
**Page affected:** `/rme/cashier/receivables` (Piutang RME)

## Bug

After Phase 27.4.1, a **free control visit** could be marked `COMPLETED` once the patient paid an
installment toward a previous-visit receivable. However, the free control invoice itself still showed
up in the **Piutang Aktif** (active receivables) list on `/rme/cashier/receivables`.

Example of the offending row:

| field        | value    |
| ------------ | -------- |
| `grand_total`| `0`      |
| `paid_amount`| `0`      |
| `remaining`  | `0`      |
| `status`     | `UNPAID` |

There was nothing to collect, yet it appeared as a receivable (and could be offered a follow-up). The
same leak applied to any invoice whose payments already covered the balance but whose `status` was still
a stale `UNPAID`/`PARTIAL`.

## Final business rule

An **active receivable** is an invoice that actually owes money:

- `status` in (`UNPAID`, `PARTIAL`)
- `grand_total > 0`
- `paid_amount < grand_total`
- `remaining = grand_total - paid_amount > 0`

Invoices that must **not** appear in Piutang RME:

- `grand_total = 0` (e.g. free control visits)
- `remaining = 0`
- `paid_amount >= grand_total`
- `status = PAID`
- a completed free-control invoice still flagged `UNPAID` at `Rp0`

Zero-value / fully-settled invoices are **kept** in the database as billing and history records. They are
only excluded from the *active receivables* surface.

## Implementation

`paid_amount` is not a stored column — it is the sum of `trx_rme_payments.amount` for the invoice. The
fix is therefore a query-level constraint, added once in the shared query so listing, summary, aging,
count, pagination, follow-up offers, and CSV export all agree.

`app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php`:

```php
private function applyActiveReceivableConstraint(Builder $query): Builder
{
    return $query
        ->where('grand_total', '>', 0)
        ->whereRaw('trx_rme_invoices.grand_total > (SELECT COALESCE(SUM(amount), 0) FROM trx_rme_payments WHERE trx_rme_payments.rme_invoice_id = trx_rme_invoices.id)');
}
```

Applied inside `receivableQuery()`, which is the single source used by both `receivables()` (index +
aging summary + pagination) and `exportReceivables()` (CSV). Because the rule lives in the query, old
rows that are already `UNPAID` at `Rp0` drop out automatically — no data migration or manual SQL.

### Explicitly unchanged

- No new migration.
- No manual / mass data edits (free invoices keep their `UNPAID` `Rp0` rows as history).
- Payment allocation (Phase 27.4) untouched.
- Control-visit completion rule (Phase 27.4.1) untouched.
- No route changes; invoice items untouched.
- Blade view not used to hide rows — the filter is at the query level.

## Tests

Added to existing files (no new test file):

`tests/Feature/RME/CashierBillingTest.php`

1. excludes zero-grand-total unpaid invoices from active receivables
2. excludes zero-remaining settled invoices from active receivables
3. still shows unpaid invoice with positive remaining balance (remaining `600000.00` in export)
4. excludes zero-grand-total invoice from receivable aging summary (count/totals not inflated)
5. excludes zero-grand-total invoice from receivable export (CSV)

`tests/Feature/RME/RmeControlVisitReceivableCarryOverPaymentTest.php`

6. does not list a completed free control zero invoice as an active receivable (parent still shown)
7. keeps a parent invoice with positive remaining visible in receivables

### Results

- `--filter=CashierBillingTest` → 33 passed
- `--filter=RmeControlVisitReceivableCarryOverPayment` → 35 passed
- `--filter=RmeReceivableFollowUp` → 9 passed
- `--filter=ClinicVisitControlWorkflowTest` → 14 passed
- `--filter=RmePayment` → 19 passed
- `--filter=RME` (full RME suite) → 672 passed / 2028 assertions
