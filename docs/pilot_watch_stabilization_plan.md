# Pilot WATCH Stabilization Plan

## Purpose

This document defines the stabilization plan for continuing the pilot under `WATCH` status.
It is an operational guide for IT, Admin, and Owner to keep the pilot running safely while the
residual non-blocking risks from Sprint 25.9 are validated and closed.

## Current Status

```text
Pilot Decision: WATCH
```

WATCH means the pilot may continue **under supervision**. It is not a full GO (residual risks
are not all proven low) and not a NO-GO (no serious blocker was found).

## Stabilization Goals

- Continue pilot without full GO declaration.
- Monitor residual non-blocking risks.
- Validate receivable and branch summary accuracy.
- Confirm RME follow-up consistency.
- Rehearse backup restore safely outside production.
- Confirm Owner Dashboard KPIs.
- Improve SOP/checklist adoption.

## Daily Stabilization Routine

| Check | Owner | Frequency | Evidence |
|---|---|---|---|
| Review pilot feedback backlog | Admin/IT | Daily | Notes / issue list |
| Review VPS/log symptoms | IT | Daily | Log notes |
| Confirm dashboard access | Owner/Admin | Daily | Screenshot or notes |
| Check branch receivable summary | Admin/Finance | Daily/Weekly | Sample validation |
| Check RME follow-up usage | RME/Admin | Daily/Weekly | Follow-up notes |
| Confirm backup availability | IT | Daily/Weekly | Backup timestamp |
| Review SOP/checklist adherence | PIC Klinik | Daily | Checklist status |

## Weekly Stabilization Routine

| Check | Owner | Frequency | Evidence |
|---|---|---|---|
| Receivable sample audit | Finance/Admin | Weekly | Audit notes |
| Owner KPI review | Owner/IT | Weekly | KPI confirmation |
| Support runbook review | IT/Admin | Weekly | Support notes |
| Backup restore rehearsal planning | IT | Weekly until done | Rehearsal plan |

## Escalation Triggers

Escalate if:

- Receivable data does not match manual sample validation.
- Branch scoping looks incorrect.
- Owner Dashboard shows misleading KPI values.
- VPS errors affect pilot flow.
- Backup file is missing or cannot be verified.
- RME follow-up status becomes inconsistent.
- Users repeatedly skip SOP/checklist steps.

Escalate using the escalation template in `docs/pilot_support_runbook.md`.

## Stabilization Exit Criteria

Pilot can move from `WATCH` to `GO` when:

- Receivable sample validation passes.
- Branch receivable summary is accepted by owner/admin.
- RME follow-up flow is stable.
- Backup restore rehearsal is completed outside production.
- Monitoring/log review has no critical recurring issues.
- Daily checklist and support runbook are actively used.
- Owner confirms KPI expectations are met.

## Related Documents

- `docs/sprint_26_phase_26_1_pilot_watch_stabilization_plan_backlog_kickoff.md` — phase report
- `docs/sprint_26_stabilization_backlog.md` — Sprint 26 backlog
- `docs/pilot_daily_operations_checklist.md` — daily checklist
- `docs/pilot_support_runbook.md` — support escalation guide
- `docs/pilot_go_watch_no_go_report.md` — management-facing WATCH summary
- `docs/sprint_25_phase_25_9_pilot_feedback_review_go_watch_no_go_report.md` — WATCH decision

## Stabilization Notes

This plan does not require code changes. Any discovered issue that requires a code change must
be converted into a separate Sprint 26 backlog item with its own implementation phase, branch,
validation, and rollback notes. Backup restore rehearsal is **non-production only** — never run
restore, `migrate:fresh`, or `db:wipe` against the live VPS pilot database.
