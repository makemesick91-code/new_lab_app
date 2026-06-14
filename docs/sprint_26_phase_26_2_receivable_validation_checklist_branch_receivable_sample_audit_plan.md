# Sprint 26 Phase 26.2 — Receivable Validation Checklist + Branch Receivable Sample Audit Plan

## 1. Phase Summary

Sprint 26 Phase 26.2 continues the pilot WATCH stabilization cycle by defining a receivable validation checklist and branch receivable sample audit plan.

This phase converts Sprint 26.1 backlog items `S26-BL-001` and `S26-BL-002` into operational validation artifacts.

## 2. Mode and Safety Constraints

- Mode: Limit Saver 1
- Scope: Docs/checklist-only
- Risk level: Low
- No production code changes
- No VPS deployment
- No migrations
- No database changes
- No production database queries
- No full test suite
- Graphify update required after documentation changes

## 3. Baseline

| Item | Value |
|---|---|
| Previous phase | Sprint 26 Phase 26.1 |
| Previous commit | 1718a85 |
| Previous tag | sprint-26-phase-26-1-pilot-watch-stabilization-plan-backlog-kickoff |
| Pilot status | WATCH |
| Current phase | Sprint 26 Phase 26.2 |
| Current goal | Define receivable validation checklist and branch receivable sample audit plan |

## 4. Source Documents Reviewed

| Document | Purpose | Key Findings |
|---|---|---|
| `docs/sprint_26_phase_26_1_pilot_watch_stabilization_plan_backlog_kickoff.md` | Sprint 26 stabilization plan | Receivable Validation track requires confirming summary accuracy vs sample transactions; Branch Receivable Summary track flags interpretation/scoping risk (e.g. PARTIAL filter misread). |
| `docs/pilot_watch_stabilization_plan.md` | WATCH stabilization routine | Stabilization goals include validating receivable/branch summary accuracy and confirming RME follow-up consistency; escalation triggers include data not matching manual sample and incorrect branch scoping. |
| `docs/sprint_26_stabilization_backlog.md` | Sprint 26 backlog | `S26-BL-001` (Receivable validation checklist, P1, needed before GO) and `S26-BL-002` (Branch receivable sample audit, P1, confirm branch scoping). |
| `docs/sprint_25_phase_25_9_pilot_feedback_review_go_watch_no_go_report.md` | WATCH decision source | Branch receivable summary is a read-only aggregate (25.5) with verified branch isolation; main residual risk is user misreading branch-scoped data (S25-FB-006), not a code defect; recommends periodic manual validation. |
| `docs/pilot_go_watch_no_go_report.md` | Management GO/WATCH/NO-GO summary | GO conditions: receivable and branch-scoping data validated as consistently accurate during pilot; owner confirms numbers match expectation over time. |
| `docs/pilot_feedback_backlog.md` | Feedback backlog | Source of S25-FB-005 (owner KPI confirmation) and S25-FB-006 (branch-scoped data interpretation) feedback items carried into Sprint 26 receivable tracks. |
| `docs/sprint_25_phase_25_5_owner_dashboard_branch_receivable_summary.md` | Branch summary implementation reference | Summary computed by `OwnerDashboardRmeLabKpiService::branchReceivableSummary()`; read-only; branch isolation test-verified; full-payment-only pilot rule remains in force. |

If a document is missing, write `Not found at review time`.

## 5. Backlog Mapping

| Backlog ID | Item | Sprint 26.2 Output |
|---|---|---|
| S26-BL-001 | Receivable validation checklist | `docs/receivable_validation_checklist.md` |
| S26-BL-002 | Branch receivable sample audit | `docs/branch_receivable_sample_audit_plan.md` |
| Supporting | Evidence capture | `docs/receivable_validation_evidence_template.md` |

## 6. Receivable Validation Objectives

Define objectives:

- Confirm receivable summary can be trusted during pilot.
- Compare dashboard/summary values against manual sample evidence.
- Identify branch scoping or calculation mismatch early.
- Define escalation path for mismatch.
- Provide evidence for future WATCH-to-GO decision.

## 7. Validation Scope

In scope:

- Receivable summary shown in Owner Dashboard / branch receivable summary.
- Branch-level receivable grouping.
- Sample invoice/payment/follow-up records where available.
- RME receivable / follow-up status consistency.
- Manual comparison notes.
- Evidence checklist.

Out of scope:

- Code changes.
- Database changes.
- Production database query automation.
- VPS deploy.
- Migration.
- Full test suite.
- Financial reconciliation as legal/accounting audit.

## 8. Sample Size Recommendation

Use conservative pilot sample rules:

| Branch Count | Minimum Sample |
|---|---|
| 1 branch | Minimum 10 receivable-related records |
| 2–3 branches | Minimum 5 records per branch or 15 total, whichever is higher |
| More than 3 branches | Minimum 5 records per branch for active branches |
| Low transaction branch | Use all available records and mark as low-volume branch |

Sample should include, if available:

- Unpaid item
- Partially paid item
- Paid item
- Recent item
- Older outstanding item
- Follow-up item
- Different branch item
- Different doctor/clinic/patient context if applicable

> Note: The pilot operates under the full-payment-only rule (partial/cicilan deferred).
> If partially paid items are not present in the dataset, mark that sample row as `N/A`
> and record the reason rather than forcing an entry.

## 9. Acceptance Criteria

Receivable validation passes when:

- Sample records match expected branch.
- Receivable amount matches manual source or explainable calculation.
- Paid/unpaid/partial status is understandable.
- Branch receivable summary does not mix branches incorrectly.
- RME follow-up status is consistent with manual notes.
- No critical mismatch is found.
- Any minor mismatch is documented and added to backlog.

## 10. Escalation Triggers

Escalate if:

- Amount mismatch cannot be explained.
- Branch scoping appears wrong.
- Paid item appears unpaid without reason.
- Unpaid item is missing from summary.
- Follow-up status is inconsistent.
- Owner Dashboard KPI is misleading.
- Same mismatch appears in multiple samples.
- Finance/Admin cannot reproduce the summary manually.

Escalate using the escalation template in `docs/pilot_support_runbook.md`.

## 11. Branch Receivable Sample Audit Plan

Define sample audit plan:

| Step | Activity | Owner | Evidence |
|---|---|---|---|
| 1 | Select active branches | Admin/Finance | Branch list |
| 2 | Select sample records per branch | Admin/Finance | Sample list |
| 3 | Capture dashboard/summary value | Owner/Admin | Screenshot/notes |
| 4 | Compare with manual source | Finance/Admin | Manual comparison |
| 5 | Mark result PASS/WATCH/FAIL | Finance/Admin | Audit result |
| 6 | Escalate mismatch | IT/Admin | Issue note |
| 7 | Update backlog | IT/Admin | Backlog item |

Full audit plan detail lives in `docs/branch_receivable_sample_audit_plan.md`.

## 12. Result Classification

Use these result statuses:

| Status | Meaning |
|---|---|
| PASS | Data matches or difference is explainable |
| WATCH | Minor issue or needs repeated validation |
| FAIL | Critical mismatch or unreliable summary |
| N/A | Sample not available or not applicable |

## 13. Evidence Requirements

Evidence should include:

- Review date
- Reviewer
- Branch
- Sample reference
- Dashboard/summary value
- Manual comparison value
- Difference
- Result status
- Notes
- Escalation/backlog reference if needed

Evidence is captured with `docs/receivable_validation_evidence_template.md`.

## 14. WATCH-to-GO Readiness Impact

Sprint 26.2 supports future GO decision if:

- Receivable sample audit is mostly PASS.
- No critical FAIL remains unresolved.
- Branch summary is accepted by Owner/Admin.
- Follow-up status is understandable.
- Evidence is sufficient for Sprint 26 closure report.

This maps directly to the GO conditions in `docs/pilot_go_watch_no_go_report.md`
("receivable and branch-scoping data validated as consistently accurate during the pilot")
and to the Stabilization Exit Criteria in `docs/pilot_watch_stabilization_plan.md`.

## 15. Recommended Next Actions

1. Use `docs/receivable_validation_checklist.md` during pilot review.
2. Use `docs/branch_receivable_sample_audit_plan.md` for sample audit.
3. Capture evidence with `docs/receivable_validation_evidence_template.md`.
4. Convert any mismatch into Sprint 26 backlog items.
5. Continue WATCH until validation is complete.
6. Use results in future Sprint 26 closure GO/WATCH/NO-GO report (S26-BL-008).

## 16. Validation Commands

```bash
git status --short
git diff --stat
git diff --check
graphify update .
```

## 17. Files Changed

- `docs/sprint_26_phase_26_2_receivable_validation_checklist_branch_receivable_sample_audit_plan.md`
- `docs/receivable_validation_checklist.md`
- `docs/branch_receivable_sample_audit_plan.md`
- `docs/receivable_validation_evidence_template.md`
- `docs/graphify_sprint_26_2_update.md`

## 18. Final Notes

Sprint 26.2 is docs/checklist-only. It prepares manual validation and audit discipline before any future implementation or pilot GO decision.
