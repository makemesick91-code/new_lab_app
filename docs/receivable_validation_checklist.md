# Receivable Validation Checklist

## Purpose

This checklist validates receivable data during the pilot WATCH stabilization period.

It implements Sprint 26 backlog item `S26-BL-001` and supports the future WATCH-to-GO decision.

## When to Use

Use this checklist:

- During daily or weekly pilot review.
- Before owner KPI confirmation.
- Before moving pilot status from WATCH to GO.
- When receivable summary or branch summary looks inconsistent.
- When finance/admin needs manual evidence.

## Validation Information

| Field | Value |
|---|---|
| Review Date |  |
| Reviewer |  |
| Branch |  |
| Source Screen / Report |  |
| Sample Period |  |
| Related Sprint | Sprint 26.2 |

## Checklist

| # | Check Item | PASS | WATCH | FAIL | N/A | Notes |
|---|---|---|---|---|---|---|
| 1 | Branch is correct for selected sample |  |  |  |  |  |
| 2 | Receivable amount matches manual reference |  |  |  |  |  |
| 3 | Paid/unpaid/partial status is understandable |  |  |  |  |  |
| 4 | Follow-up status is consistent with manual notes |  |  |  |  |  |
| 5 | Sample appears in correct branch summary |  |  |  |  |  |
| 6 | Sample does not appear in wrong branch |  |  |  |  |  |
| 7 | Owner Dashboard value is explainable |  |  |  |  |  |
| 8 | No duplicate receivable item found |  |  |  |  |  |
| 9 | No missing receivable item found |  |  |  |  |  |
| 10 | Difference, if any, is documented |  |  |  |  |  |

> Note: The pilot runs under the full-payment-only rule (partial/cicilan deferred).
> If no partially paid records exist, mark check #3's partial portion as `N/A` and note the reason.

## Result Classification

| Result | Criteria |
|---|---|
| PASS | All critical checks pass or differences are explainable |
| WATCH | Minor mismatch found and needs repeated validation |
| FAIL | Critical mismatch found or summary cannot be trusted |
| N/A | Sample not applicable |

## Critical Checks

These checks are critical:

- Branch correctness
- Amount correctness
- Paid/unpaid/partial status correctness
- Missing/duplicate receivable detection
- Wrong branch detection

If any critical check is `FAIL`, keep pilot status as `WATCH` or escalate to `NO-GO` for receivable area until resolved.

## Evidence Required

Attach or reference:

- Screenshot of dashboard/summary
- Manual comparison source
- Sample record reference
- Reviewer notes
- Difference explanation
- Backlog item reference if mismatch found

Use `docs/receivable_validation_evidence_template.md` to record evidence.

## Escalation

Escalate to IT/Admin/Owner if:

- Amount mismatch is unexplained.
- Branch scoping is wrong.
- Paid item appears unpaid.
- Unpaid item is missing.
- Duplicate receivable appears.
- Follow-up status is inconsistent.
- Owner KPI interpretation is misleading.

Use the escalation template in `docs/pilot_support_runbook.md`.

## Final Review Result

```text
Result: PASS / WATCH / FAIL / N/A
```

## Reviewer Notes

Write notes here.
