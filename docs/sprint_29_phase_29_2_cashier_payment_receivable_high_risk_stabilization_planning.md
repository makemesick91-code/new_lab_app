# Sprint 29 Phase 29.2 — Cashier Payment Receivable High-Risk Stabilization Planning

## 1. Status

- **Mode:** cashier/payment/receivable high-risk stabilization planning only
- **Deployment:** no deployment
- **Migration:** no migration
- **Production code change:** no production code change
- **Bug fix execution:** no bug fix implemented
- **Stabilization execution:** no stabilization implemented
- **Runtime behavior change:** no runtime behavior change
- **Integration change:** no integration implemented
- **Destructive data operation:** no destructive data operation
- **Baseline:** Sprint 29.1 GO at `39b4fd9`

## 2. Purpose

- Convert the Sprint 29.0 prioritized backlog and Sprint 29.1 RME regression planning into a high-risk cashier/payment/receivable stabilization plan.
- Define evidence requirements before any future implementation.
- Protect invoice identity, remaining balance, FIFO allocation, receipt traceability, and receivable follow-up correctness.
- Protect cashier behavior connected to RME control visits.
- Prevent silent payment, invoice, or receivable mutation.
- Keep this phase planning-only and reviewable.
- Prepare a future implementation plan without changing runtime behavior now.

## 3. Non-goals

- No production code change.
- No bug fix implementation.
- No stabilization implementation.
- No migration/schema change.
- No deployment.
- No database mutation.
- No destructive data operation.
- No route/controller/service/model/view change.
- No WhatsApp/API integration.
- No monitoring/backup/restore implementation.
- No business rule change.
- No direct modification to cashier/payment/receivable/RME code.

## 4. Background

- Sprint 28 closed with pilot readiness, receivable follow-up planning, and issue triage.
- Sprint 29.0 prioritized the pilot stabilization backlog.
- Sprint 29.1 documented P0/P1 RME Control Workflow regression planning.
- Cashier/payment/receivable is high risk because it affects invoices, receipts, remaining balance, patient trust, owner reports, follow-up piutang, and RME control visit payment context.
- Planning must happen before any implementation so that financial-impacting changes are evidence-driven and reviewable.

## 5. High-risk definition

- **P0 BLOCKER:** payment allocation corruption, duplicate payment entry, wrong invoice paid, wrong remaining balance, Rp0 invoice shown as an active receivable, receivable follow-up sent to a fully paid invoice, lost receipt/payment trace, or cashier cannot safely continue.
- **P1 HIGH:** major cashier/payment/receivable workflow risk with a workaround but high financial/operational impact.
- **NEEDS CONFIRMATION:** unclear report without invoice identity, reproduction steps, or evidence.

## 6. Required evidence before implementation

- Issue ID.
- Reporter role.
- Branch/device/browser.
- Patient/RM reference if necessary.
- Visit/control visit reference if necessary.
- Invoice reference.
- Receivable reference.
- Payment/receipt reference if any.
- Steps to reproduce.
- Expected behavior.
- Actual behavior.
- Screenshot/print/log evidence.
- Frequency.
- Reproducible status.
- Financial impact.
- RME/control visit relation if any.
- Rollback consideration.
- Test scenario proposal.
- Privacy review.

## 7. Cashier/payment/receivable invariants

- Invoice identity must be preserved.
- Payment must be linked to the correct invoice/receivable context.
- Remaining balance must be accurate.
- Payment allocation must remain FIFO previous receivable first.
- Split allocation must remain traceable if parent and current invoice both exist.
- Parent receivable can remain visible/payable in cashier control.
- Parent receivable must not block control completion.
- Rp0 invoice must not appear in active receivables.
- Active receivable follow-up is only for remaining balance > 0.
- Fully paid invoice must not be followed up as an active receivable.
- Payment receipt allocation must remain traceable.
- Duplicate payment entry must be prevented or escalated.
- Disputed balance must be escalated, not silently fixed.
- No cashier action should silently mutate unrelated RME, invoice, payment, or receivable records.

## 8. RME control visit connected guardrails

- Same patient/RM must be preserved.
- Control workflow must create a new visit.
- Old RME/odontogram/invoice must not be overwritten.
- Parent/previous receivable context must remain traceable.
- Current control invoice must remain distinguishable from parent/previous invoice.
- Cashier must be able to distinguish parent/current invoice context.
- Payment allocation and receipt evidence must stay auditable.
- Any cross-over issue between control visit and receivable payment is P0/P1 until triaged.

## 9. P0/P1 triage matrix

| Priority | Risk type | Trigger | Required evidence | Immediate action | Future phase candidate |
| --- | --- | --- | --- | --- | --- |
| P0 BLOCKER | Wrong invoice paid | Payment posted to an invoice the cashier did not intend | Invoice ref, payment/receipt ref, steps, screenshot | Freeze affected invoice, escalate, do not silently fix | Phase B regression + Phase C minimal fix |
| P0 BLOCKER | Wrong remaining balance | Displayed/stored balance differs from expected after payment | Invoice ref, before/after balance, steps | Escalate, capture evidence, no silent edit | Phase B regression + Phase C minimal fix |
| P0 BLOCKER | Duplicate payment entry | Same payment recorded more than once | Payment/receipt refs, steps, frequency | Escalate, flag duplicate, do not auto-delete | Phase B regression + Phase C minimal fix |
| P0 BLOCKER | Payment allocation corruption | FIFO/split allocation produces inconsistent ledger | Invoice/receivable refs, allocation trace | Escalate, preserve trace, no silent fix | Phase B regression + Phase C minimal fix |
| P0 BLOCKER | Rp0 invoice appears in active receivables | Zero-remaining invoice listed as active piutang | Invoice ref, receivable list evidence | Escalate, confirm exclusion rule | Phase B regression + Phase C minimal fix |
| P0 BLOCKER | Fully paid invoice receives receivable follow-up | Follow-up triggered on paid invoice | Invoice ref, follow-up evidence | Escalate, suppress erroneous follow-up plan | Phase F receivable follow-up verification |
| P0 BLOCKER | Receipt/payment trace missing | Receipt or payment record not auditable | Payment/receipt ref, steps | Escalate, preserve audit trail | Phase B regression + Phase C minimal fix |
| P1 HIGH | Parent receivable visibility issue | Parent receivable not clearly shown/payable in control | Parent + current invoice refs | Document, triage, workaround note | Phase B regression |
| P1 HIGH | Split allocation confusing but traceable | Allocation correct but UI unclear | Invoice/receivable refs, screenshot | Document clarity issue | Phase B/C UI clarity plan |
| P1 HIGH | Cashier control completion friction | Control completion harder than expected | Visit/invoice refs, steps | Document friction | Phase E smoke validation |
| P1 HIGH | Receivable report/export mismatch | Report/export totals differ from ledger | Report/export evidence, invoice refs | Document mismatch | Phase F receivable verification |
| NEEDS CONFIRMATION | Unclear cashier/operator report | Report lacks invoice identity/steps/evidence | Whatever is available | Request full evidence before triage | Re-triage after evidence |

## 10. Stabilization planning checklist

- Confirm invoice identity.
- Confirm receivable identity.
- Confirm payment/receipt identity.
- Confirm remaining balance before/after.
- Confirm parent/current invoice context.
- Confirm expected vs actual behavior.
- Confirm affected role.
- Confirm whether issue is P0 or P1.
- Confirm privacy-safe evidence.
- Confirm financial impact.
- Confirm rollback/safety note.
- Confirm targeted regression test plan.
- Confirm no implementation in this phase.
- Confirm future phase assignment.

## 11. Future regression test planning

Planning-only. Candidate test scenarios for a future implementation phase:

- Payment is posted to the intended invoice.
- Remaining balance updates correctly after partial payment.
- Remaining balance becomes zero after full payment.
- Rp0 invoice is excluded from active receivables.
- Fully paid invoice is excluded from receivable follow-up.
- FIFO allocation applies previous receivable first.
- Split allocation remains traceable when parent/current invoice both exist.
- Payment receipt shows correct allocation.
- Duplicate payment entry is prevented or clearly detected.
- Cashier can distinguish parent/current invoice context.
- Receivable report/export excludes zero remaining invoices.
- RME control completion is not blocked by parent receivable.

## 12. Future implementation sequencing

Planning-only. No implementation in Sprint 29.2.

- **Phase A:** P0/P1 evidence confirmation.
- **Phase B:** targeted cashier/payment/receivable regression tests only.
- **Phase C:** minimal fix planning.
- **Phase D:** isolated future PR for code change.
- **Phase E:** cashier/RME smoke validation.
- **Phase F:** receivable follow-up verification.
- **Phase G:** pilot verification and GO/NO-GO.

No implementation in Sprint 29.2 — sequencing above is documentation of intent only.

## 13. Out-of-scope implementation list

- No controller changes.
- No model changes.
- No service changes.
- No repository changes.
- No route changes.
- No Blade/view changes.
- No migration changes.
- No seeder changes.
- No config/env changes.
- No queue/job/notification changes.
- No payment allocation code changes.
- No invoice calculation code changes.
- No receivable follow-up code changes.
- No RME workflow code changes.

## 14. Risk and mitigation

- **Misclassified cashier issue** → require full evidence and P0/P1 triage before action.
- **Missing invoice/payment evidence** → mark NEEDS CONFIRMATION, do not implement.
- **Privacy leakage in screenshots/logs** → privacy review on every evidence item.
- **Accidentally fixing without test plan** → enforce Phase B regression before Phase C/D.
- **Regression in invoice identity** → invariant + regression test candidate.
- **Regression in remaining balance** → invariant + before/after balance check.
- **Regression in FIFO allocation** → invariant + FIFO regression test candidate.
- **Regression in Rp0 receivable exclusion** → invariant + exclusion regression test candidate.
- **Regression in receipt traceability** → invariant + audit trail test candidate.
- **Regression in parent/current invoice context** → invariant + context-distinction test candidate.
- **Scope creep into implementation** → out-of-scope list + planning-only posture.
- **GO tag created on wrong commit** → GO tag only after PR merge, on the merge commit.

## 15. GO/NO-GO decision

**GO if:**

- Planning document is complete.
- Sprint history is updated.
- Focused test passes.
- No production code changed.
- No migration.
- No deployment.
- No destructive operation.
- No bug fix/stabilization implementation.
- No runtime behavior change.
- No RME/payment/receivable/cashier business rule change.
- P0/P1 evidence requirements and guardrails are documented.
- Future implementation sequencing is clear.

**NO-GO if:**

- Any production code is changed.
- Any migration/deploy/destructive command is introduced.
- Any fix/stabilization is implemented.
- Any runtime behavior changes.
- Any RME/payment/receivable/cashier rule changes.
- P0/P1 evidence requirements are missing.
- Future test planning is missing.
- Sprint history/test is missing.

## 16. Safety confirmation

- No production code change.
- No migration.
- No deployment.
- No destructive operation.
- No bug fix implementation.
- No stabilization implementation.
- No runtime behavior change.
- No route/controller/service/model/view/config/seeder change.
- No RME/payment/receivable/cashier business rule change.

## 17. Final decision

Sprint 29 Phase 29.2 posture: GO CANDIDATE FOR PR REVIEW

GO CANDIDATE FOR PR REVIEW

## 18. Validation plan

- `php artisan test --filter=Sprint29Phase292CashierPaymentReceivableHighRiskStabilizationPlanning`
- `vendor/bin/pint --test tests/Feature/Sprint29/Sprint29Phase292CashierPaymentReceivableHighRiskStabilizationPlanningTest.php`
- `git diff --check`
