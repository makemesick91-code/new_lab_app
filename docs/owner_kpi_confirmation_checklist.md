# Owner KPI Confirmation Checklist

## Purpose

This checklist confirms whether Owner Dashboard KPIs are understandable, useful, and safe enough for pilot business review. It operationalizes backlog item `S26-BL-004` and pilot feedback item `S25-FB-005`.

## Current Status

```text
Pilot Decision: WATCH
Owner KPI Confirmation: Pending
```

## When to Use

Use this checklist:

- During an owner/admin review meeting.
- Before moving the pilot from WATCH to GO.
- When dashboard KPI interpretation is unclear.
- After receivable validation or branch receivable sample audit (Sprint 26.2).
- Before management uses the dashboard as a decision source.

## Review Metadata

| Field | Value |
|---|---|
| Review Date |  |
| Owner / Reviewer |  |
| Admin / IT Reviewer |  |
| Branch Scope |  |
| Dashboard Screen / Report | `/dashboard` (Owner RME/Lab pilot section) |
| Sample Period |  |
| Related Sprint | Sprint 26.4 |

## Reference — KPIs Currently Available

These are the Owner Dashboard KPIs available at review time (read-only, per Sprint 25.4 / 25.5):

| KPI / Area | Source | Notes |
|---|---|---|
| Total remaining RME receivable | Owner RME/Lab KPI section | UNPAID + PARTIAL; PAID/DRAFT/VOID excluded |
| PARTIAL invoice count (Invoice Cicilan) | Receivable KPI | Per selected branch filter |
| UNPAID invoice count (Invoice Belum Dibayar) | Receivable KPI | Per selected branch filter |
| Follow-up posture | Follow-up KPI | overdue / today / scheduled / never-followed-up |
| Branch receivable summary (Ringkasan Piutang per Cabang) | `branchReceivableSummary()` | Per-branch Sisa Piutang + counts + follow-up; "Lihat Piutang" gated by `manage_rme_billing` |
| Branch filter / drilldown | Dashboard | Owner can scope to one active branch or all |

## KPI Confirmation Checklist

| # | KPI / Area | PASS | WATCH | FAIL | BACKLOG | N/A | Notes |
|---|---|---|---|---|---|---|---|
| 1 | Total receivable is understandable |  |  |  |  |  |  |
| 2 | Branch receivable summary is understandable |  |  |  |  |  |  |
| 3 | Branch-level grouping is clear |  |  |  |  |  |  |
| 4 | RME receivable/follow-up visibility is useful |  |  |  |  |  |  |
| 5 | KPI labels are clear |  |  |  |  |  |  |
| 6 | KPI reporting period/range is clear |  |  |  |  |  |  |
| 7 | KPI values can be manually validated if needed |  |  |  |  |  |  |
| 8 | Dashboard helps daily owner review |  |  |  |  |  |  |
| 9 | Dashboard helps weekly business review |  |  |  |  |  |  |
| 10 | No critical KPI appears misleading |  |  |  |  |  |  |
| 11 | Owner knows what action to take from dashboard |  |  |  |  |  |  |
| 12 | KPI limitations are documented |  |  |  |  |  |  |

## Critical KPI Checks

Critical items:

- Total remaining receivable interpretation
- Branch receivable summary interpretation
- Branch grouping correctness (branch scoping)
- Reporting period / as-of clarity
- KPI not misleading owner decision
- Manual validation path available (vs Piutang RME source records)

If any critical KPI is `FAIL`, the pilot should remain `WATCH` for dashboard readiness.

## Owner Review Questions

| Question | Owner Answer | Result |
|---|---|---|
| Which KPI is most important for daily review? |  | PASS/WATCH/FAIL |
| Which KPI is most important for weekly review? |  | PASS/WATCH/FAIL |
| Is the branch receivable summary clear? |  | PASS/WATCH/FAIL |
| Is total receivable clear? |  | PASS/WATCH/FAIL |
| Are KPI labels understandable? |  | PASS/WATCH/FAIL |
| Is the reporting period clear? |  | PASS/WATCH/FAIL |
| Are any values misleading? |  | PASS/WATCH/FAIL |
| What KPI is missing for the GO decision? |  | PASS/WATCH/BACKLOG |
| What KPI can wait until later? |  | PASS/WATCH/BACKLOG |

## Result Classification

| Result | Criteria |
|---|---|
| PASS | KPI is clear, useful, and accepted |
| WATCH | KPI is usable but needs monitoring or explanation |
| FAIL | KPI is misleading, unclear, or not accepted |
| BACKLOG | KPI needs future change |
| N/A | KPI not applicable in current pilot |

## Evidence Required

Attach or reference:

- Dashboard screenshot
- Review notes
- Owner/Admin comments
- Related receivable validation evidence if applicable (`docs/receivable_validation_checklist.md`)
- Backlog reference for unresolved items
- Sign-off status

## Escalation

Escalate to IT/Admin/Owner if:

- KPI is misleading.
- Owner cannot interpret dashboard values.
- Branch receivable summary is not trusted.
- KPI requires code/UI change.
- A business decision cannot be made from the available dashboard.
- Owner requests a new critical KPI for the GO decision.

## Final Review Result

```text
Result: PASS / WATCH / FAIL / BACKLOG / N/A
```

## Owner/Admin Sign-Off

| Role | Name | Date | Sign-Off |
|---|---|---|---|
| Owner/Management |  |  | Yes/No |
| Admin/Finance |  |  | Yes/No |
| IT/Admin |  |  | Yes/No |

## Reviewer Notes

Write notes here.
