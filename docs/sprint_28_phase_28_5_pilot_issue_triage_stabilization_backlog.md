# Sprint 28 Phase 28.5 — Pilot Issue Triage & Stabilization Backlog

## Status

- **Mode:** pilot issue triage / stabilization backlog planning only
- **Deployment:** No deployment
- **Migration:** No migration
- **Production code change:** No production code change
- **Bug fix execution:** No bug fix implemented
- **Integration change:** No integration implemented
- **Destructive data operation:** No destructive data operation
- **Baseline:** Sprint 28.4 GO at `1086d0f`

## Purpose

- Turn pilot findings into a structured triage and stabilization backlog.
- Standardize how operator/cashier/support feedback is captured.
- Separate blocker/high/medium/low issues from enhancement requests.
- Protect Sprint 27 RME Control Workflow and Sprint 28 pilot readiness phases.
- Prepare safe next-phase stabilization work without changing runtime behavior.
- Keep this phase docs/backlog only.

## Non-goals

- No bug fix implementation.
- No production code change.
- No migration/schema change.
- No deployment.
- No database mutation.
- No destructive data operation.
- No route/controller/service/model/view change.
- No WhatsApp/API integration.
- No monitoring/backup/restore implementation.
- No business rule change.

## Planning Assumptions

- Pilot operators may report issues from daily runbook/checklists.
- Issues must be reproducible or clearly marked as needs confirmation.
- Patient data must be minimized in issue notes.
- RME control workflow anomalies are treated as blocker until proven otherwise.
- Payment/receivable anomalies require careful evidence before implementation.
- Any stabilization work must be handled in separate future phases/PRs.

## Issue Intake Sources

- Operator smoke checklist findings.
- Daily operation runbook notes.
- Cashier/payment/receivable findings.
- RME control workflow guardrail findings.
- WhatsApp reminder / receivable follow-up planning feedback.
- Monitoring/backup/restore planning findings.
- Laravel log findings.
- Owner/supervisor feedback.
- Support/admin observations.

## Issue Intake Form

| Field | Description |
| --- | --- |
| Issue ID | Unique identifier for the reported issue |
| Date/time | When the issue was observed |
| Reporter role/name | Who reported the issue |
| Branch/device | Branch and device where it occurred |
| Module/page | Affected module or page |
| Patient/RM/visit/invoice reference if needed | Reference only when required for analysis |
| Steps to reproduce | Ordered steps to reproduce |
| Expected result | What should have happened |
| Actual result | What actually happened |
| Screenshot/print/log evidence | Attached evidence reference |
| Severity | BLOCKER / HIGH / MEDIUM / LOW / ENHANCEMENT / NEEDS CONFIRMATION |
| Frequency | How often it occurs |
| Reproducible? | Yes / No / Intermittent |
| Privacy risk? | Whether the report contains sensitive data |
| Assigned owner | Person responsible for triage |
| Triage decision | Classification outcome |
| Next action | Agreed follow-up |

## Severity Classification

| Severity | Definition |
| --- | --- |
| BLOCKER | Pilot cannot continue or data/payment/RME safety risk. |
| HIGH | Major workflow broken but workaround exists. |
| MEDIUM | Partial issue, confusing UI, report/layout mismatch, or limited impact. |
| LOW | Typo, minor documentation gap, minor polish. |
| ENHANCEMENT | Improvement request, not a defect. |
| NEEDS CONFIRMATION | Unclear report, needs reproduction/evidence. |

## Stabilization Lane Categories

- **Lane A:** RME control workflow safety.
- **Lane B:** Cashier/payment/receivable correctness.
- **Lane C:** Patient registration/search/RM identity.
- **Lane D:** Odontogram/RME print and browser print layout.
- **Lane E:** Report export/print.
- **Lane F:** Operator access/menu/role visibility.
- **Lane G:** WhatsApp reminder and receivable follow-up process.
- **Lane H:** Monitoring/log/backup/restore readiness.
- **Lane I:** UX copy/help text/training notes.
- **Lane J:** Technical debt / test hardening.

## RME Control Workflow Triage Guardrails

- Same patient/RM must be preserved.
- Control visit must create a new visit.
- Old RME/odontogram/invoice must not be overwritten.
- Parent receivable can remain visible/payable in cashier control.
- Payment allocation must remain FIFO previous receivable first.
- Parent receivable must not block control completion.
- Rp0 invoice must not appear in active receivables.
- Any report violating these is BLOCKER until triaged.

## Cashier / Receivable Triage Guardrails

- Verify invoice identity before classifying issue.
- Verify remaining balance > 0 before receivable follow-up.
- Verify Rp0 invoices are excluded from active receivables.
- Verify payment receipt shows correct allocation.
- Verify split allocation behavior when parent/current invoice both exist.
- Verify no duplicate payment entry.
- Disputed balance must be escalated and not silently fixed.

## Pilot Backlog Template

| Backlog ID | Lane | Severity | Title | Evidence | Reproducible | Owner | Proposed next phase | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| _example_ | Lane A | BLOCKER | _short title_ | _evidence path_ | Yes | _name_ | Sprint 28.6 | Open |

## GO/NO-GO Decision Matrix

- **GO** to stabilization planning if all blocker issues are documented and scoped.
- **GO** to technical implementation only if issue has evidence, reproduction steps, expected/actual behavior, and safety impact.
- **NO-GO** if blocker issue lacks owner.
- **NO-GO** if RME/payment/receivable anomaly is unresolved and affects pilot safety.
- **NO-GO** if data overwrite risk is suspected.
- **NO-GO** if backup/restore readiness is unknown for risky stabilization.
- **NO-GO** if patient privacy risk is not triaged.

## Support / Admin Daily Triage Checklist

- Review new operator notes.
- Review cashier/payment notes.
- Review RME control workflow guardrail findings.
- Review Laravel logs.
- Review backup/monitoring notes.
- Group issues by severity.
- Assign owner.
- Mark reproducibility status.
- Prepare next-day action summary.
- Escalate blockers.

## Privacy and Evidence Rules

- Minimize patient data.
- Avoid screenshots containing unnecessary sensitive data.
- Use patient/RM/visit references only when needed.
- Do not expose No. KTP in issue reports unless explicitly required for identity bug analysis and approved.
- Do not share logs/screenshots outside approved support channel.
- Redact sensitive clinical/payment details when possible.

## Future Stabilization Candidate Backlog

Planning-only, no implementation:

- RME control workflow regression stabilization.
- Cashier/payment/receivable regression stabilization.
- Report export/print polish.
- Odontogram/RME print layout polish.
- Patient search/registration UX polish.
- Role/menu visibility hardening.
- WhatsApp manual pilot SOP.
- Monitoring/backup/restore rehearsal execution on non-production target.
- Operator training notes.
- Test hardening for high-risk pilot workflows.

## Risk and Mitigation

| Risk | Mitigation |
| --- | --- |
| Vague issue reports | Require intake form fields before triage. |
| Missing reproduction steps | Mark as NEEDS CONFIRMATION until reproduced. |
| Patient privacy leakage | Apply privacy and evidence rules; redact data. |
| Misclassified severity | Re-triage with severity classification table. |
| Silent data overwrite risk | Treat as BLOCKER; verify RME control guardrails. |
| Payment/receivable misallocation risk | Verify FIFO allocation and evidence before action. |
| Fixing symptoms without root cause | Require root-cause note before next-phase work. |
| Scope creep into implementation | Keep this phase docs/backlog only. |
| Merge without validation | Run focused test, pint, and diff check before merge. |
| GO tag created on wrong commit | Create GO tag only on PR merge commit. |

## GO / NO-GO

**GO if:**

- Pilot issue triage/backlog document is complete.
- Sprint history updated.
- Focused test passes.
- No production code change.
- No migration.
- No deployment.
- No bug fix implementation.
- No destructive operation.
- No business rule change.

**NO-GO if:**

- Any production code changes.
- Any migration or deploy is introduced.
- Any bug fix is implemented in this planning phase.
- Any runtime behavior changes.
- Any destructive data operation occurs.
- Triage/backlog plan is incomplete.
- Sprint history/test missing.

## Recommended Next Phase

Sprint 28 Phase 28.6 may be one of:

- Pilot stabilization backlog prioritization
- WhatsApp reminder manual pilot SOP
- Monitoring/backup/restore rehearsal execution on non-production target
- RME/cashier high-risk regression stabilization planning
- Sprint 28 closure GO/NO-GO report

## Validation Plan

- `php artisan test --filter=Sprint28Phase285PilotIssueTriageStabilizationBacklog`
- `vendor/bin/pint --test tests/Feature/Sprint28/Sprint28Phase285PilotIssueTriageStabilizationBacklogTest.php`
- `git diff --check`

---

GO CANDIDATE FOR PR REVIEW
