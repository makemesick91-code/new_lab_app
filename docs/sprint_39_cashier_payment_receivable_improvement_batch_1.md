# Sprint 39 — Cashier, Payment & Receivable Improvement Batch 1

Status: Draft / Local Validation Pending
Baseline: Sprint 38 GO at 253f025
Scope: Controlled cashier/payment/receivable improvement Batch 1 / local implementation / targeted regression only

## Purpose

Sprint 39 follows Sprint 38 RME Workflow Improvement Batch 1. It implements the first controlled
cashier/payment/receivable workflow improvement batch after the Sprint 38 RME results.

It focuses on:

- cashier verification clarity (treatment consent / `TTD Surat Persetujuan Tindakan` status),
- payment / remaining-balance clarity (invoice total, amount paid, remaining balance, payment status),
- manual receivable / piutang follow-up context (patient WA number),
- WA manual follow-up clarity (no automated WhatsApp, no follow-up automation),
- RME-to-cashier workflow continuity (privacy + WA + consent alignment with Sprint 38),
- targeted regression coverage.

Sprint 39 does not deploy and does not touch production / VPS. It does not rewrite financial
calculations — discovery confirmed the existing services already enforce the business rules, so this
sprint adds UI clarity and regression tests on top of the existing baseline.

## Baseline references

```
Sprint 37 GO: sprint-37-controlled-roadmap-execution-batch-1-governance-review-go at 078be4e
Sprint 38 GO: sprint-38-rme-workflow-improvement-batch-1-go at 253f025
```

Sprint 38 feature reference:

```
Sprint 38 feature commit: beb8eb8
```

## Implemented scope summary

Discovery findings (existing baseline, unchanged):

- `RmeInvoice::remainingAmount()` = `max(0, grand_total - paidAmount)` and
  `RmePaymentService::refreshInvoiceStatus()` drive `PAID` / `PARTIAL` status.
- Overpayment is guarded in **both** `RmePaymentService::pay()` (throws when amount > remaining) and
  `CreateRmePaymentRequest` (validation message "Pembayaran tidak boleh melebihi sisa tagihan." /
  "... total yang harus dibayar.").
- The receivable queue (`RmeInvoiceController::receivables` + `RmeControlReceivableService`) already
  excludes fully paid / zero-remaining invoices (status `UNPAID`/`PARTIAL` with remaining > 0).
- `ClinicVisit::hasVerifiedConsent()` already models cashier consent verification.
- No. KTP is not rendered in any cashier/payment/receivable/receipt view (Sprint 38 hardening).

Implemented this sprint (local UI clarity + tests only):

- **Cashier verification clarity result** — added a read-only `Status Persetujuan Tindakan (TTD)`
  verification badge (Terverifikasi / Belum Diverifikasi) to the cashier clinical summary partial
  (visible on the cashier billing detail), reusing `hasVerifiedConsent()`. Verification / checklist
  status only — no digital signature capture, no upload.
- **Payment/remaining-balance clarity result** — confirmed the cashier payment screen already shows
  Grand Total, Dibayar, Sisa Tagihan and the payment status badge; preserved the overpayment guard
  help text. Regression tests assert this clarity.
- **Receivable/piutang follow-up context result** — surfaced the patient WA number in the receivable
  list (under patient/visit) and in the follow-up form invoice info.
- **WA manual follow-up result** — added explicit copy on the receivable list, the follow-up form and
  the cashier clinical summary that WhatsApp follow-up is performed manually by the cashier and the
  system sends no automated WhatsApp message and runs no follow-up automation.
- **Consent checklist/status result** — surfaced read-only consent verification status in cashier
  context; preserved as verification/checklist only.
- **Zero-remaining receivable exclusion result** — preserved (no query/service change); regression
  test asserts fully paid invoices are excluded and partially paid invoices appear.
- **Overpayment/validation result** — preserved (no calculation change); regression test asserts an
  over-remaining payment is rejected.
- **KTP/privacy result** — preserved; regression test asserts No. KTP is never rendered in the
  cashier billing detail / receivable views.
- **Tests added/updated** — `tests/Feature/Sprint39/Sprint39CashierPaymentReceivableImprovementBatch1Test.php`
  (doc/history checklist + functional cashier/payment/receivable clarity, exclusion, overpayment and
  privacy assertions).
- **Migration added** — none. No schema change was necessary; the financial baseline already exists.
- **Deferred items** — partial/cicilan policy changes, payment-method-level reporting, and owner
  dashboard receivable analytics are deferred to Sprint 40. No financial recalculation was performed.

## Safety boundaries

- no production/VPS access
- no deployment
- no production migration
- no external WhatsApp send
- no WhatsApp automation
- no signature upload/capture integration
- no risky financial calculation rewrite
- no backup/restore/rollback execution
- no destructive operation
- no `.env` change
- no dependency/package install
- no GO tag

## Validation commands

```bash
php artisan test --filter=Sprint39CashierPaymentReceivableImprovementBatch1
php artisan test --filter=Payment
php artisan test --filter=Receivable
php artisan test --filter=Cashier
php artisan test --filter=Invoice
vendor/bin/pint --test
git diff --check
```

(Filters adjusted to the actual targeted tests used. `--filter=Cashier` / `--filter=Receivable`
exercise the existing `CashierBillingTest` and `RmeReceivableFollowUpTest` suites alongside the new
Sprint 39 tests.)

## PR readiness

GO CANDIDATE FOR PR REVIEW

## Next sprint recommendation

Sprint 40 — Reporting, Export & Owner Dashboard Improvement

Sprint 40 should focus on controlled reporting / export and owner / admin dashboard improvement,
building on the Sprint 37 roadmap governance and the Sprint 39 cashier/payment/receivable results
(receivable aging/follow-up analytics, payment/method reporting clarity, and owner dashboard
financial visibility) — local implementation and targeted regression only, consistent with the
Sprint 39 safety boundaries.
