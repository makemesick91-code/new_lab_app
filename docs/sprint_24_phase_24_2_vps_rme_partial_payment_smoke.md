# Sprint 24 Phase 24.2 — VPS RME Partial Payment Smoke Test

## Deployment Context

Sprint 24 Phase 24.2 validates the deployed RME partial payment workflow on VPS after:

- Sprint 24 Phase 24.1 — RME Partial Payment / Cicilan Foundation
- Sprint 24 Phase 24.2.1 — Fix RME New Patient Visit Branch Consistency

## Deployed Head

- Base feature: `feature/sprint-24-phase-24-1-rme-partial-payment-foundation`
- Hotfix deployed: `hotfix/sprint-24-phase-24-2-1-rme-new-patient-branch-consistency`
- Partial payment commit: `ed36d6a`
- Branch consistency hotfix commit: `bc5e480`

## Smoke Test Result

| Check | Result |
|---|---|
| Partial payment on RME invoice | PASS |
| Invoice changes to PARTIAL after partial payment | PASS |
| Visit remains CASHIER_PENDING after partial payment | PASS |
| Overpayment is rejected | PASS |
| Final settlement changes invoice to PAID | PASS |
| Final settlement changes visit to COMPLETED | PASS |
| Payment history visible | PASS |
| Lab candidate generated only after full payment | PASS |
| Laravel log error | NONE |

## Manual Smoke Details

### Smoke A — Partial Payment

Result: PASS

Expected behavior confirmed:

- Cashier can record payment lower than remaining balance.
- Invoice status becomes `PARTIAL`.
- Paid amount and remaining balance are displayed.
- Visit remains `CASHIER_PENDING`.
- Payment history is visible.
- Lab candidate is not generated while invoice is still partial.

### Smoke B — Overpayment Guard

Result: PASS

Expected behavior confirmed:

- Payment amount greater than remaining balance is rejected.
- Invoice remains `PARTIAL`.
- Payment record is not added.
- Remaining balance does not change.

### Smoke C — Final Settlement

Result: PASS

Expected behavior confirmed:

- Cashier can pay the exact remaining balance.
- Invoice status becomes `PAID`.
- Remaining balance becomes zero.
- Visit status becomes `COMPLETED`.
- Payment history contains installment and final settlement records.
- Lab candidate is generated only after invoice is fully paid.

## Conclusion

Sprint 24 Phase 24.2 is approved.

RME partial payment is safe for pilot use on VPS, including installment payment, overpayment guard, final settlement, visit completion, and RME-to-lab candidate generation after full payment.
