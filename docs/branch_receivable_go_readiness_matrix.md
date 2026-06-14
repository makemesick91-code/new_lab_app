# Branch Receivable GO Readiness Matrix

## Purpose

This matrix summarizes whether branch receivable summary is ready to support a future GO decision. It consolidates the outputs of `docs/branch_receivable_review_notes.md` and `docs/branch_receivable_sample_audit_execution_report.md` against the GO conditions in `docs/pilot_go_watch_no_go_report.md`.

## Current Pilot Status

```text
Pilot Decision: WATCH
Branch Receivable GO Readiness: Pending Evidence
```

## Readiness Dimensions

| Dimension | PASS | WATCH | FAIL | Pending Evidence | Notes |
| ---------------------------------- | ---- | ----- | ---- | ---------------- | ----- |
| Branch scoping correctness |  |  |  |  |  |
| Amount explainability |  |  |  |  |  |
| Paid/partial/unpaid interpretation |  |  |  |  |  |
| Manual validation path |  |  |  |  |  |
| Owner/Admin understanding |  |  |  |  |  |
| KPI non-misleading |  |  |  |  |  |
| Evidence completeness |  |  |  |  |  |
| Backlog/escalation coverage |  |  |  |  |  |

## Per-Branch GO Readiness

| Branch | Audit Result | Owner Confidence | Evidence Status | GO Readiness | Notes |
| ------ | ------------------------------------ | ----------------------- | ---------------------------- | --------------------------- | ----- |
|  | PASS/WATCH/FAIL/PENDING EVIDENCE/N/A | High/Medium/Low/Pending | Complete/Partial/Pending/N/A | Ready/WATCH/Blocked/Pending |  |
|  | PASS/WATCH/FAIL/PENDING EVIDENCE/N/A | High/Medium/Low/Pending | Complete/Partial/Pending/N/A | Ready/WATCH/Blocked/Pending |  |
|  | PASS/WATCH/FAIL/PENDING EVIDENCE/N/A | High/Medium/Low/Pending | Complete/Partial/Pending/N/A | Ready/WATCH/Blocked/Pending |  |

## GO Readiness Rules

Branch receivable can support GO when:

- All active pilot branches are PASS or explicitly accepted WATCH.
- No critical FAIL is open.
- No wrong-branch sample is unresolved.
- No unexplained amount mismatch is unresolved.
- Owner/Admin understands the branch receivable summary.
- Evidence is attached or explicitly accepted as pending non-blocking.
- Escalations are tracked in backlog.

## WATCH Rules

Keep branch receivable as WATCH when:

- Evidence is incomplete but no blocker is known.
- Minor mismatch needs repeated validation.
- Owner accepts summary with documented limitation.
- Manual validation path exists but is not fully completed.
- Follow-up is tracked in backlog.

## NO-GO / Blocker Rules

Treat branch receivable as GO-blocking if:

- Branch scoping is wrong.
- Amount mismatch is unexplained and material.
- Owner/Admin cannot trust the summary.
- KPI interpretation is misleading.
- Missing/duplicate receivable appears in sample and remains unresolved.
- Evidence required for GO is unavailable and not accepted as non-blocking.

## Final Readiness Result

```text
Branch Receivable GO Readiness Result: READY / WATCH / BLOCKED / PENDING EVIDENCE / N/A
```

> Default at creation time: `PENDING EVIDENCE`. The pilot remains `WATCH` until real branch receivable audit evidence is captured and accepted.

## Recommended Next Action

| Result | Next Action |
| ---------------- | ------------------------------------------------- |
| READY | Use as evidence for future GO/WATCH/NO-GO closure |
| WATCH | Continue pilot monitoring and repeat validation |
| BLOCKED | Escalate before GO decision |
| PENDING EVIDENCE | Collect audit evidence before final decision |
| N/A | Mark not applicable with reason |

## Notes

Write readiness notes here. This matrix is structural until branch receivable audit evidence is collected and mapped to Owner Dashboard KPI readiness.
