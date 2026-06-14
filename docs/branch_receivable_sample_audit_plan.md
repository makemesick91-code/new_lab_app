# Branch Receivable Sample Audit Plan

## Purpose

This document defines a manual sample audit plan for branch receivable summary during the pilot WATCH stabilization period.

It implements Sprint 26 backlog item `S26-BL-002` and addresses the branch interpretation/scoping risk recorded as S25-FB-006.

## Audit Goal

The goal is to confirm that branch receivable summary is accurate, explainable, and safe enough to support owner review.

The branch receivable summary is a read-only aggregate (delivered in Sprint 25.5 via
`OwnerDashboardRmeLabKpiService::branchReceivableSummary()`). Branch isolation is test-verified;
the main residual risk is users misreading branch-scoped data, not a code defect. This audit
confirms that interpretation and accuracy hold during real pilot usage.

## Audit Scope

In scope:

- Active pilot branches
- Branch receivable summary
- Owner Dashboard receivable values
- Manual comparison samples
- RME follow-up consistency where applicable

Out of scope:

- Production database modification
- Code changes
- VPS deployment
- Full accounting audit
- Automated reconciliation

## Branch Selection

| Branch | Active in Pilot | Included in Audit | Notes |
|---|---|---|---|
| Branch 1 | Yes/No | Yes/No |  |
| Branch 2 | Yes/No | Yes/No |  |
| Branch 3 | Yes/No | Yes/No |  |

## Sample Size

Recommended sample:

| Branch Type | Minimum Sample |
|---|---|
| Active branch | Minimum 5 records |
| High-volume branch | Minimum 10 records |
| Low-volume branch | All available records |
| Branch with prior mismatch | Minimum 10 records or all available records |

## Sample Mix

Try to include:

- Unpaid receivable
- Paid receivable
- Partially paid receivable (if any; pilot is full-payment-only, so may be `N/A`)
- Old outstanding receivable
- Recent receivable
- Follow-up item
- Different doctor/clinic/patient context where applicable

## Audit Steps

| Step | Activity | Owner | Output |
|---|---|---|---|
| 1 | Confirm active branches | Admin/Finance | Branch list |
| 2 | Select sample records | Admin/Finance | Sample table |
| 3 | Capture branch summary value | Owner/Admin | Screenshot/notes |
| 4 | Compare sample manually | Finance/Admin | Comparison notes |
| 5 | Classify each sample | Finance/Admin | PASS/WATCH/FAIL |
| 6 | Summarize findings | IT/Admin | Audit summary |
| 7 | Escalate mismatch | IT/Admin/Owner | Backlog/escalation item |

## Sample Audit Table

| Sample ID | Branch | Source Ref | Summary Amount | Manual Amount | Difference | Status | Notes |
|---|---|---|---:|---:|---:|---|---|
| 1 |  |  |  |  |  | PASS/WATCH/FAIL/N/A |  |
| 2 |  |  |  |  |  | PASS/WATCH/FAIL/N/A |  |
| 3 |  |  |  |  |  | PASS/WATCH/FAIL/N/A |  |

## Branch Summary Result

| Branch | Samples Checked | PASS | WATCH | FAIL | Overall Result | Notes |
|---|---:|---:|---:|---:|---|---|
| Branch 1 |  |  |  |  | PASS/WATCH/FAIL |  |
| Branch 2 |  |  |  |  | PASS/WATCH/FAIL |  |
| Branch 3 |  |  |  |  | PASS/WATCH/FAIL |  |

## Acceptance Criteria

Branch receivable summary is acceptable when:

- No critical branch scoping mismatch is found.
- No unexplained amount mismatch is found.
- No duplicate receivable appears in sampled records.
- No missing receivable appears in sampled records.
- Owner/Admin can understand the summary.
- Any minor issue is documented as WATCH/backlog.

## Escalation Trigger

Escalate if:

- One or more sampled records appear in the wrong branch.
- Total amount mismatch is unexplained.
- Dashboard summary cannot be reproduced manually.
- Duplicate/missing receivable is found.
- Owner/Admin cannot trust or interpret the summary.

Use the escalation template in `docs/pilot_support_runbook.md`.

## Audit Conclusion

```text
Conclusion: PASS / WATCH / FAIL
```

## Notes

Write audit notes here.
