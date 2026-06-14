# Dashboard Business Review Criteria

## Purpose

This document defines business review criteria for determining whether the Owner Dashboard can support pilot management decisions. It complements `docs/owner_kpi_confirmation_checklist.md` and supports the WATCH-to-GO discipline established in Sprint 25.9 / Sprint 26.1.

## Business Review Goal

The dashboard should help owner/admin answer:

- What is the current operational/financial condition?
- Which branch needs attention?
- Which receivable needs follow-up?
- Are dashboard values understandable and trustworthy enough for pilot review?
- What needs to be fixed before full GO?

## Review Scope

In scope:

- Owner Dashboard business usability.
- KPI interpretation.
- Branch receivable summary usefulness ("Ringkasan Piutang per Cabang").
- Receivable/follow-up visibility.
- Owner/admin decision support.
- Evidence and sign-off.

Out of scope:

- New feature implementation.
- UI redesign.
- Database changes.
- Production deploy.
- Formal accounting audit.
- Full test suite.

## Business Review Criteria

| Criteria | PASS | WATCH | FAIL | BACKLOG | Notes |
|---|---|---|---|---|---|
| Dashboard supports daily owner review |  |  |  |  |  |
| Dashboard supports weekly business review |  |  |  |  |  |
| Branch receivable summary is useful |  |  |  |  |  |
| Total receivable is explainable |  |  |  |  |  |
| RME follow-up visibility is actionable |  |  |  |  |  |
| KPI labels are clear |  |  |  |  |  |
| KPI date/range context is clear |  |  |  |  |  |
| Owner can identify branch needing attention |  |  |  |  |  |
| Owner can identify follow-up action |  |  |  |  |  |
| Manual validation evidence path exists |  |  |  |  |  |
| Dashboard limitations are documented |  |  |  |  |  |
| No critical misleading KPI remains |  |  |  |  |  |

## Management Decision Support

| Business Question | Dashboard Should Answer | Result | Notes |
|---|---|---|---|
| How much receivable is outstanding? | Total remaining receivable / summary | PASS/WATCH/FAIL |  |
| Which branch has receivable exposure? | Branch receivable summary | PASS/WATCH/FAIL |  |
| Which items need follow-up? | Follow-up visibility (overdue/today/scheduled/never) | PASS/WATCH/FAIL |  |
| Is the pilot stable enough for business review? | KPI + stabilization evidence | PASS/WATCH/FAIL |  |
| What should be prioritized next? | Backlog and WATCH items | PASS/WATCH/FAIL |  |

## GO Readiness Criteria for Dashboard

The dashboard can support a future `GO` only when:

- Critical KPI values are accepted by owner/admin.
- Branch receivable summary is understandable.
- Receivable validation evidence is available or scheduled (Sprint 26.2).
- KPI limitations are documented.
- No critical dashboard interpretation risk remains unresolved.
- Owner/admin sign-off is captured or explicitly accepted as pending.

This criteria set maps to the GO condition in `docs/pilot_go_watch_no_go_report.md`: "Owner confirms the dashboard/receivable numbers match business expectation over time."

## WATCH Criteria

The dashboard remains `WATCH` when:

- A KPI is usable but needs repeated validation.
- Owner accepts the dashboard with known limitations.
- Branch summary is useful but not fully signed off.
- KPI period/range needs explanation.
- Some requested KPI is moved to backlog (e.g. ODE-002/004/005/006).

## NO-GO Trigger for Dashboard Area

The dashboard area becomes `NO-GO` if:

- A critical KPI is misleading.
- Owner cannot use the dashboard for review.
- Branch receivable summary is not trusted.
- Values cannot be explained or validated.
- The dashboard causes a wrong management decision.

## Review Output

At the end of review, classify dashboard readiness:

```text
Dashboard Business Review Result: PASS / WATCH / FAIL / BACKLOG
```

## Notes

Write business review notes here.
