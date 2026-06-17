# Sprint 29 Phase 29.1 — P0/P1 RME Control Workflow Regression Stabilization Planning

## 1. Status

- **Mode:** P0/P1 RME control workflow regression stabilization planning only
- **Deployment:** no deployment
- **Migration:** no migration
- **Production code change:** no production code change
- **Bug fix execution:** no bug fix implemented
- **Stabilization execution:** no stabilization implemented
- **Runtime behavior change:** no runtime behavior change
- **Integration change:** no integration implemented
- **Destructive data operation:** no destructive data operation
- **Baseline:** Sprint 29.0 GO at `21ff95a`

## 2. Purpose

- Convert the Sprint 29.0 prioritized backlog into a P0/P1 planning lane focused on RME Control Workflow regression risk.
- Define evidence requirements before any implementation.
- Define guardrails for safe future stabilization.
- Prevent accidental overwrite of old RME, odontogram, invoice, or receivable data.
- Protect cashier/payment/receivable behavior connected to control visits.
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
- No direct modification to RME/cashier/payment/receivable code.

## 4. Background

- Sprint 28 closed with pilot readiness and issue triage outputs.
- Sprint 29.0 prioritized the pilot stabilization backlog and separated blocker/high-risk lanes from low-risk polish.
- RME Control Workflow is high risk because it touches patient/RM continuity, new visits, old medical records, odontogram history, invoices, receivables, cashier visibility, and payment allocation.
- P0/P1 planning must happen before implementation so that no stabilization work begins without evidence, reproduction steps, and guardrails.

## 5. Regression risk definition

- **P0 BLOCKER:** data overwrite, wrong patient/RM, old RME overwritten, old odontogram overwritten, invoice/receivable corruption, duplicate payment risk, wrong payment allocation, or pilot cannot safely continue.
- **P1 HIGH:** major RME control workflow behavior risk with a workaround but high operational/cashier impact.
- **NEEDS CONFIRMATION:** unclear report without reproduction/evidence.

## 6. Required evidence before implementation

- Issue ID.
- Reporter role.
- Branch/device/browser.
- Patient/RM reference if necessary.
- Visit/control visit reference if necessary.
- Invoice/receivable reference if necessary.
- Steps to reproduce.
- Expected behavior.
- Actual behavior.
- Screenshot/print/log evidence.
- Frequency.
- Reproducible status.
- Safety impact.
- Rollback consideration.
- Test scenario proposal.
- Privacy review.

## 7. RME Control Workflow invariants

- Same patient/RM must be preserved.
- Control workflow must create a new visit.
- Old RME must not be overwritten.
- Old odontogram must not be overwritten.
- Old invoice must not be overwritten.
- Old receipt/payment history must not be overwritten.
- New control visit must be auditable.
- Parent/previous receivable context must remain traceable.
- Rp0 invoice must not appear in active receivables.
- Control completion must not silently mutate unrelated records.

## 8. Cashier/payment/receivable connected guardrails

- Parent receivable can remain visible/payable in cashier control.
- Parent receivable must not block control completion.
- Payment allocation must remain FIFO previous receivable first.
- Split allocation must remain traceable if parent and current invoice both exist.
- Active receivable follow-up is only for remaining balance > 0.
- Rp0 invoice must not be followed up as active receivable.
- Disputed balance must be escalated, not silently fixed.
- Any payment/receivable mismatch must include invoice identity and evidence.

## 9. P0/P1 triage matrix

| Priority | Risk type | Trigger | Required evidence | Immediate action | Future phase candidate |
| --- | --- | --- | --- | --- | --- |
| P0 BLOCKER | Wrong patient/RM | Control visit binds to wrong patient/RM | Patient/RM reference, repro steps, screenshot | Freeze affected flow, escalate, no silent fix | Sprint 29.1 implementation follow-up |
| P0 BLOCKER | Old RME overwrite | Existing RME mutated by control visit | RME reference, before/after evidence, repro | Freeze affected flow, escalate, no silent fix | Sprint 29.1 implementation follow-up |
| P0 BLOCKER | Old odontogram overwrite | Existing odontogram mutated by control visit | Odontogram reference, before/after evidence | Freeze affected flow, escalate, no silent fix | Sprint 29.1 implementation follow-up |
| P0 BLOCKER | Invoice/receivable corruption | Old invoice/receivable altered or corrupted | Invoice/receivable identity, repro, evidence | Freeze affected flow, escalate, no silent fix | Sprint 29.1 implementation follow-up |
| P0 BLOCKER | Duplicate or wrong payment allocation | Payment allocated twice or to wrong receivable | Payment/invoice identity, allocation trail | Freeze affected flow, escalate, no silent fix | Sprint 29.1 implementation follow-up |
| P1 HIGH | Parent receivable visibility issue | Parent receivable not visible/payable in cashier control | Cashier reference, repro, screenshot | Document workaround, plan targeted test | Sprint 29.1/29.2 follow-up |
| P1 HIGH | Control completion friction | Control completion blocked or confusing | Visit reference, repro, role affected | Document workaround, plan targeted test | Sprint 29.1 follow-up |
| P1 HIGH | Print/report mismatch affecting RME control evidence | Control visit print/report shows wrong identity | Print/report sample, visit reference | Document workaround, plan targeted test | Sprint 29.1/29.5 follow-up |
| NEEDS CONFIRMATION | Unclear operator report | Report lacks reproduction/evidence | Request repro steps and evidence | Hold until evidence provided | TBD after confirmation |

## 10. Stabilization planning checklist

- Confirm reproduction steps.
- Confirm expected vs actual behavior.
- Confirm affected role.
- Confirm RME/cashier/payment/receivable guardrail.
- Confirm whether issue is P0 or P1.
- Confirm privacy-safe evidence.
- Confirm rollback/safety note.
- Confirm targeted regression test plan.
- Confirm no implementation in this phase.
- Confirm future phase assignment.

## 11. Future regression test planning

Planning-only. Candidate test scenarios for a future implementation phase:

- Control visit creates a new visit for the same patient/RM.
- Existing RME record remains unchanged after control visit.
- Existing odontogram record remains unchanged after control visit.
- Existing invoice remains unchanged after control visit.
- Parent receivable remains traceable.
- Parent receivable does not block control completion.
- Payment allocation prioritizes previous receivable first.
- Rp0 invoice is excluded from active receivables.
- Control visit print/report shows correct visit identity.
- Cashier can distinguish parent/current invoice context.

## 12. Future implementation sequencing

Planning-only. No implementation in Sprint 29.1.

- **Phase A:** P0 evidence confirmation.
- **Phase B:** targeted regression tests only.
- **Phase C:** minimal fix planning.
- **Phase D:** code change in isolated future PR.
- **Phase E:** cashier/RME smoke validation.
- **Phase F:** pilot verification and GO/NO-GO.

**No implementation in Sprint 29.1.**

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
- No RME workflow code changes.

## 14. Risk and mitigation

| Risk | Mitigation |
| --- | --- |
| Misclassified pilot issue | Require evidence and triage matrix before assigning priority. |
| Missing reproduction steps | Hold as NEEDS CONFIRMATION until repro is provided. |
| Privacy leakage in screenshots/logs | Require privacy review on every evidence item. |
| Accidentally fixing without test plan | Forbid implementation until targeted regression test plan exists. |
| Regression in patient/RM binding | Enforce same-patient/RM invariant in future tests. |
| Regression in old RME/odontogram preservation | Enforce no-overwrite invariants in future tests. |
| Regression in invoice/receivable visibility | Enforce parent-receivable traceability invariants. |
| Regression in payment allocation | Enforce FIFO previous-receivable-first invariant. |
| Scope creep into implementation | Restrict this phase to docs/planning/checklist test only. |
| GO tag created on wrong commit | Create GO tag only on the PR merge commit after merge. |

## 15. GO/NO-GO decision for Sprint 29.1

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

Sprint 29 Phase 29.1 posture: GO CANDIDATE FOR PR REVIEW

**GO CANDIDATE FOR PR REVIEW**

## 18. Validation plan

- `php artisan test --filter=Sprint29Phase291P0P1RmeControlWorkflowRegressionStabilizationPlanning`
- `vendor/bin/pint --test tests/Feature/Sprint29/Sprint29Phase291P0P1RmeControlWorkflowRegressionStabilizationPlanningTest.php`
- `git diff --check`
