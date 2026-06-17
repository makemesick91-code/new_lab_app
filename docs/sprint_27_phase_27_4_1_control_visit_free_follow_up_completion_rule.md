# Sprint 27 Phase 27.4.1 — Control Visit Free Follow-up Completion Rule (Hotfix)

**Branch:** `feature/sprint-27-phase-27-4-1-control-visit-free-follow-up-completion-rule`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (merge commit `0dc7913`, PR #8 / Phase 27.4)
**Type:** Business-rule hotfix on Phase 27.4. No migration. No schema change. No new route/service/test file.

## Problem

After Phase 27.4 (carry-over payment allocation), a **free control (follow-up) visit** — a control
visit with no additional treatment cost — got stuck at `cashier_pending` when the cashier paid an
installment toward the *previous* visit's receivable from the control screen.

Root cause: in `RmePaymentService::allocateControlPayment()`, the control-visit completion call lived
**inside** the `if ($remainingPayment > 0)` block and used `completeVisitIfPaid()`, which only fires
when the *control invoice* status is `PAID`. For a free control visit the entire payment is allocated
to the parent receivable, so `$remainingPayment` reaches `0`, the block is skipped, and the control
visit is never completed. The previous-visit receivable effectively (and incorrectly) became a blocker
for the control visit's status.

## Business rule (final)

- **Parent receivable / piutang sebelumnya:** displayed and payable from the control screen. It may stay
  `UNPAID`/`PARTIAL` after payment and **must never block** the control visit's status. It does **not**
  need to be fully settled for the control visit to complete.
- **Control visit completion** depends only on the **control invoice's own remaining balance**, never on
  the combined parent + current total:
  - **Free follow-up** (control invoice `grand_total` 0 → remaining 0): visit becomes `completed` once a
    successful payment is recorded in the batch (even if 100% of it went to the parent receivable).
  - **Control with additional cost:** visit becomes `completed` only when the control invoice remaining
    reaches 0 (fully paid). If remaining > 0 after allocation, the visit stays `cashier_pending`.
- **No payment at all:** a free control visit is **not** auto-completed. Completion only happens through a
  successful cashier payment action.
- **Normal (non-control) flow unchanged:** full payment → `completed`; partial payment → `cashier_pending`
  (existing `pay()` behavior, untouched).
- Parent visit status continues to follow existing behavior (parent visit completes when its own invoice
  is fully paid).

### Worked examples

| Parent remaining | Control invoice | Payment | Parent after | Control invoice after | Control visit |
|---|---|---|---|---|---|
| 300.000 | 0 (free) | 50.000 | PARTIAL (rem 250.000) | UNPAID (rem 0) | **completed** |
| 300.000 | 100.000 | 350.000 | PAID | PARTIAL (rem 50.000) | cashier_pending |
| 300.000 | 100.000 | 400.000 | PAID | PAID | **completed** |
| 300.000 | 0 (free) | — (none) | UNPAID | UNPAID | cashier_pending |

## Change

`app/Modules/RmeInvoice/Services/RmePaymentService.php`

- Removed the inline `completeVisitIfPaid($controlInvoice, $controlVisit)` from the control-payment block.
- After parent allocation **and** the optional control payment, evaluate completion via a new helper
  `completeControlVisitIfSettled($controlInvoice, $controlVisit, paymentMade)`:
  - returns early if no payment was recorded in the batch (`paymentMade = false`),
  - returns early if the control invoice still has a remaining balance,
  - otherwise transitions a `cashier_pending` control visit to `completed`.
- The parent-visit completion (`completeVisitIfPaid` per parent invoice) and the normal `pay()` flow are
  unchanged. DB transaction, FIFO allocation, batch UUID, lab-candidate idempotency, and invoice items are
  untouched. No zero-amount payment is created.

## Tests

`tests/Feature/RME/RmeControlVisitReceivableCarryOverPaymentTest.php` (existing file — 7 cases added):

1. free control visit completes after a partial parent receivable payment (parent PARTIAL, rem 250.000;
   only one parent payment row; no zero-amount control payment; items untouched).
2. free control visit completes even though the parent invoice stays PARTIAL.
3. control with additional treatment stays `cashier_pending` when its invoice is not fully paid (350.000).
4. control with additional treatment completes when its invoice is paid (400.000).
5. no payment does not auto-complete a free control visit.
6. normal non-control partial payment stays `cashier_pending` (regression).
7. regression: an unpaid parent receivable does not block control visit completion.

**Free control billing approach:** modelled as a control invoice carrying a single zero-priced item
(`unit_price 0`). The cashier service accepts `unit_price 0`, so the invoice is created `UNPAID` with
`grand_total 0` / remaining 0 and flows through the existing carry-over allocation path unchanged — no
schema bypass or invoice-validation violation required.

## Test results

- `--filter=RmeControlVisitReceivableCarryOverPayment`: 33 passed (103 assertions).
- `--filter=RmePayment`: 19 passed. `--filter=Cashier`: 57 passed. `--filter=ClinicVisitControlWorkflowTest`: 14 passed.
- `--filter=RME`: 665 passed (2003 assertions).

## Migration

**None.** Reuses existing columns (`rme_invoice.grand_total`, visit statuses, `trx_rme_payments.payment_batch_uuid`).
