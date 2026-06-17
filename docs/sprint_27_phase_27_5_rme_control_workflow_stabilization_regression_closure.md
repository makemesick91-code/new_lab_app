# Sprint 27 Phase 27.5 — RME Control Workflow Stabilization & Regression Closure

**Project:** DaengtisiaMS / ADLMS
**Branch:** `feature/sprint-27-phase-27-5-rme-control-workflow-stabilization-regression-closure`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Mode:** Stabilization, regression closure, operator documentation
**Migration:** No migration expected
**Deployment:** Not deployed in this phase unless reviewed and approved separately

---

## 1. Summary of Previous Phases

### Phase 27.4 — RME Control Receivable Carry-over Payment Allocation

Phase 27.4 introduced carry-over receivable handling for RME control visits.

When a patient returns for a control visit and still has unpaid or partial invoices from previous visits, the control cashier page may display both:

1. previous visit receivables, and
2. current control invoice balance.

Payment entered from the control cashier screen is allocated with FIFO priority:

1. oldest parent or previous invoice first,
2. then remaining amount to the current control invoice.

Invoices remain separate. Parent invoice items are not moved into the control invoice.

### Phase 27.4.1 — Free Control Visit Completion Rule

Phase 27.4.1 fixed the completion rule for free control visits.

A free control visit with a Rp0 control invoice may be completed after a payment is made toward the previous receivable, even if the parent invoice remains `UNPAID` or `PARTIAL`.

Parent receivable status must not block the control visit status.

### Phase 27.4.2 — Exclude Zero Remaining RME Invoices from Receivables

Phase 27.4.2 fixed the active receivables rule.

A zero-value or zero-remaining invoice may remain stored as billing/history, but must not appear in active RME receivables, receivable aging, or receivable export.

---

## 2. Final Business Rules for Control Workflow

1. A control patient uses the same patient/RM identity.
2. Every control still creates a new visit.
3. A control visit must not overwrite the old visit, old medical record, old odontogram, old invoice, or old invoice items.
4. If the patient has old receivables, the control cashier page may show and accept payment for those receivables.
5. Payment allocation uses FIFO:
   - parent or previous invoice first,
   - current control invoice after old receivables are allocated.
6. Previous receivables are not blockers for control visit completion.
7. For a free control:
   - the control invoice may be Rp0,
   - a payment toward previous receivable may complete the control visit,
   - parent invoice may remain `UNPAID` or `PARTIAL`,
   - no zero-amount payment row is required for the control invoice.
8. For a paid control with additional treatment:
   - the control visit completes only when the current control invoice is fully paid,
   - parent receivable does not need to be fully paid.
9. Invoice control gratis Rp0 must not appear in active receivables.
10. Active receivables must include only invoices with positive remaining balance.
11. Rp0 invoices may remain as billing/history records, but they are not active receivables.
12. Receipt must show allocation when one payment batch is split between parent invoice and control invoice.

---

## 3. Scenario Matrix

| Scenario | Expected Result | Regression Area |
|---|---|---|
| Kontrol gratis tanpa parent receivable | Control visit remains safe as Rp0 billing/history; no active receivable entry is created for the Rp0 invoice. | zero receivable exclusion |
| Kontrol gratis dengan parent `UNPAID` | Payment from control screen is allocated to parent first; control visit may complete after payment; parent may remain `UNPAID`/`PARTIAL`. | free control completion |
| Kontrol gratis dengan parent `PARTIAL` | Payment reduces parent remaining balance; control visit completion is based on control invoice remaining 0, not parent remaining. | carry-over + completion |
| Kontrol berbiaya tambahan dengan parent `PARTIAL` | Payment is allocated parent first, then control invoice; visit completes only when control invoice is fully paid. | paid control completion |
| Kontrol berbiaya tambahan dengan parent `PAID` | No carry-over blocks payment; current control invoice behaves like normal billing. | normal RME payment |
| Parent receivable still has remaining > 0 | Parent invoice remains visible in active receivables. | receivable listing |
| Invoice kontrol Rp0 | Stored as invoice/history but hidden from active receivables, aging, and export. | zero remaining exclusion |
| Export piutang | Export must not include Rp0 or zero-remaining control invoice. | receivable export |
| Receipt after split allocation | Receipt shows parent allocation and control allocation from the same payment batch. | receipt allocation |

---

## 4. Operator Checklist

### A. Cara daftar kontrol

1. Open menu RME visit registration.
2. Select existing patient.
3. Set visit type to `Kontrol`.
4. Select the previous visit from the visit history selector.
5. Save the new control visit.
6. Verify the new visit number is different from the previous visit.
7. Verify the previous visit remains unchanged.

### B. Cara buat billing kontrol gratis

1. Open cashier/billing page for the control visit.
2. Create billing with Rp0 control item or no additional charge according to operator flow.
3. Verify invoice total is Rp0.
4. Keep the invoice as billing/history.
5. Do not treat the Rp0 invoice as active receivable.

### C. Cara bayar cicilan tagihan lama dari halaman kontrol

1. Open cashier page for the control visit.
2. Check the carry-over receivable section.
3. Confirm old invoice number and remaining balance.
4. Enter payment amount.
5. Save payment.
6. Verify allocation is applied to previous invoice first.
7. If payment exceeds previous receivable, verify remaining amount is applied to current control invoice.

### D. Cara cek status visit completed

1. After payment, return to the control visit detail.
2. For free control, verify status becomes `COMPLETED` after a payment batch is recorded.
3. For paid control, verify status becomes `COMPLETED` only after the control invoice itself is fully paid.
4. Verify old parent receivable status does not block control completion.

### E. Cara cek piutang aktif

1. Open RME active receivables.
2. Verify parent invoice appears if remaining balance is still positive.
3. Verify Rp0 control invoice does not appear.
4. Verify paid or zero-remaining invoice does not appear.

### F. Cara cek receipt

1. Open the cashier receipt after payment.
2. Verify total payment amount.
3. Verify parent allocation.
4. Verify control allocation.
5. Verify invoice numbers remain separate.

### G. Cara export piutang

1. Open RME active receivables.
2. Apply optional filter if needed.
3. Click export.
4. Verify export contains only active receivables with positive remaining balance.
5. Verify Rp0 control invoice is not included.

---

## 5. Developer Regression Checklist

### Recommended test commands

```bash
php artisan test --filter=RmeControlWorkflowStabilizationClosure
php artisan test --filter=RmeControlVisitReceivableCarryOverPayment
php artisan test --filter=CashierBillingTest
php artisan test --filter=ClinicVisitControlWorkflowTest
php artisan test --filter=RmePayment
php artisan test --filter=Cashier
php artisan test --filter=RME
```

### Manual smoke checklist

1. Create parent RME visit.
2. Create parent invoice with positive grand total.
3. Leave parent invoice `UNPAID` or `PARTIAL`.
4. Create control visit from the parent visit.
5. Create Rp0 control invoice.
6. Pay partial parent receivable from the control cashier page.
7. Verify control visit is `COMPLETED`.
8. Verify parent invoice remains active if remaining balance is still positive.
9. Verify Rp0 control invoice does not appear in active receivables.
10. Verify receipt shows allocation.
11. Export receivables and verify Rp0 invoice is excluded.

### VPS deployment notes

This phase is stabilization and documentation-first. If later deployed to VPS:

1. Do not run `migrate:fresh`.
2. Do not truncate or delete pilot data.
3. Do not modify pilot data manually.
4. Use normal safe deployment flow only after review.
5. Run focused smoke on cashier control flow, active receivables, receipt, and export.
6. Confirm no migration is pending unless a later reviewed phase adds one.

---

## 6. Optional Dusk Note

Dusk is optional and must only be run if:

1. local dev server is running safely on `127.0.0.1:8000`,
2. test database is safe,
3. Dusk will not touch pilot or important development data,
4. fixture cleanup is confirmed.

If these conditions are not met, skip Dusk and rely on feature tests plus manual smoke.

---

## 7. No Migration Expected

Phase 27.5 does not require schema changes.

No migration is expected because the workflow uses existing fields and behavior from:

1. control visit fields,
2. payment batch UUID,
3. RME invoice grand total,
4. payment sum / remaining calculation,
5. existing visit status transitions.

---

## 8. Closure Decision

Phase 27.5 is considered ready for review when:

1. this closure document exists,
2. sprint history is updated,
3. closure checklist test passes,
4. control carry-over regression tests pass,
5. cashier billing regression tests pass,
6. clinic visit control workflow tests pass,
7. RME payment and cashier filters pass,
8. full RME focused suite passes,
9. Pint passes,
10. `git diff --check` is clean,
11. there are no migrations,
12. there are no destructive changes,
13. no commit, push, merge, or deploy is performed before review.
