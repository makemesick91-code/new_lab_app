# Sprint 25 Phase 25.9 — Pilot Feedback Review + Go/Watch/No-Go Report

## 1. Phase Summary

Sprint 25.9 is a **docs/report-only** phase. It reviews the outcomes of Sprint 25.1
through Sprint 25.8 and consolidates them into a single GO / WATCH / NO-GO decision
for the DaengtisiaMS / ADLMS pilot before the pilot is continued or expanded to the
next stage.

No production code, migration, deployment, VPS configuration, or database change is
performed in this phase. The report only reads existing documentation and records a
conservative recommendation backed by the evidence already captured in the repository.

## 2. Scope

### In Scope

- Review documents from Sprint 25.1–25.8.
- Review pilot feedback backlog.
- Review Owner Dashboard status.
- Review RME receivable / follow-up / branch receivable summary status.
- Review VPS deploy / smoke status.
- Review monitoring / log / backup readiness status.
- Review remaining risks.
- Review continued backlog.
- Produce a GO / WATCH / NO-GO recommendation.

### Out of Scope

- No production code change.
- No VPS deploy.
- No migration.
- No full test suite run.
- No server configuration change.
- No production database change.

## 3. Source Documents Reviewed

| Document | Purpose | Review Notes |
|---|---|---|
| `docs/pilot_feedback_backlog.md` | Pilot feedback intake / backlog | Three tracked items: S25-FB-006 (TRIAGED, data only), S25-FB-005 (TRIAGED, awaiting owner approval), ODE-001 (IMPLEMENTED in 25.5). |
| `docs/pilot_daily_operations_checklist.md` | Daily pilot operations checklist | Morning/closing checks, VPS read-only quick commands, daily report template, GO/WATCH/NO-GO status meanings. |
| `docs/pilot_support_runbook.md` | Support escalation / runbook | Environment, S1–S4 severity, first response, restart/log/backup/rollback SOPs, what-not-to-do, escalation template. |
| `docs/sprint_25_phase_25_1_pilot_stabilization_rc_smoke_baseline.md` | RC smoke baseline | Local RC gate all PASS; targeted Sprint 24 regression coverage used; Decision GO. |
| `docs/sprint_25_phase_25_2_pilot_feedback_intake_stabilization_backlog.md` | Feedback intake | Intake/backlog framing; feedback tracking established (document body minimal at review time). |
| `docs/sprint_25_phase_25_3_pilot_feedback_triage_quick_fix_batch_1.md` | Triage + quick fixes | No P0/P1 reproduced; Quick Fix Batch 1 = NO CODE FIX REQUIRED; Decision GO. |
| `docs/sprint_25_phase_25_4_owner_dashboard_pilot_review_enhancements.md` | Owner Dashboard enhancements | Review/enhancement backlog only (ODE-001..006); no production logic changed. |
| `docs/sprint_25_phase_25_5_owner_dashboard_branch_receivable_summary.md` | Branch receivable summary | Read-only per-branch receivable table implemented; 6 dashboard tests; branch isolation respected. |
| `docs/sprint_25_phase_25_6_vps_owner_dashboard_branch_receivable_summary_smoke.md` | VPS deploy / smoke | Deploy + smoke PASS; VPS HEAD `f87b3d5`; Laravel log CLEAN after smoke. |
| `docs/sprint_25_phase_25_7_pilot_monitoring_backup_readiness_baseline.md` | Monitoring + backup readiness | VPS git/system/service health baseline; DB + runtime backup readiness; log baseline CLEAN. |
| `docs/sprint_25_phase_25_8_pilot_daily_operations_checklist_support_runbook.md` | Checklist + support runbook | Daily ops checklist and support runbook created; operational guardrails documented. |

> Where a source document body was minimal/empty at review time (Sprint 25.2),
> it is noted explicitly above rather than inferred.

## 4. Sprint 25.1–25.8 Review Summary

| Phase | Area | Result | Pilot Impact |
|---|---|---|---|
| 25.1 | Pilot Stabilization / RC Smoke Baseline | Local RC gate all PASS (CashierBilling, RmeReceivableFollowUp, OwnerDashboard KPI/drilldown suites), routes/view cache/pint/diff PASS; Decision GO | Stable RC baseline confirmed before pilot stabilization continued |
| 25.2 | Pilot Feedback Intake / Stabilization Backlog | Feedback intake/backlog framing established | Feedback channel and tracking structure in place |
| 25.3 | Feedback Triage + Quick Fix Batch 1 | No P0/P1 reproduced; NO CODE FIX REQUIRED; logs CLEAN; services active; Decision GO | First feedback triage pass found no production blocker |
| 25.4 | Owner Dashboard Pilot Review Enhancements | Review/enhancement backlog only (ODE-001..006); no production logic changed | Owner review questions captured; enhancement candidates prioritized |
| 25.5 | Owner Dashboard Branch Receivable Summary Table | Read-only per-branch receivable summary implemented; 6 dashboard tests; PAID excluded; branch isolation enforced | Owner gains per-branch receivable visibility |
| 25.6 | VPS Deploy + Smoke | Deploy + smoke PASS; VPS HEAD `f87b3d5`; dashboard/RME routes PASS; log CLEAN | Branch receivable summary verified live on VPS |
| 25.7 | Monitoring + Backup Readiness | VPS git/system/service health baseline; DB + runtime backup readiness; log baseline 0 bytes / CLEAN | Operational visibility + backup readiness baseline established |
| 25.8 | Daily Operations Checklist + Support Runbook | Daily ops checklist + support runbook created with SOPs and escalation template | Operational guardrails documented for pilot continuation |

## 5. Pilot Feedback Status

Based on `docs/pilot_feedback_backlog.md`:

- **Total tracked items:** 3 (S25-FB-006, S25-FB-005, ODE-001).
- **Resolved / Implemented:** ODE-001 — per-branch RME receivable summary table (`Resolved`).
- **Triaged, no code fix:** S25-FB-006 — PARTIAL filter "branch mismatch" was a data
  reality (the only PARTIAL invoice is in Cabang Antang `branch_id=3`, not Cabang Landak
  `branch_id=2`); no code defect (`Watch` — confirm via manual data checks during pilot).
- **Triaged, awaiting decision:** S25-FB-005 — confirm dashboard KPIs needed for business
  review; enhancement candidates documented, waiting for owner approval (`Backlog`).
- **Open blocking items:** None reproduced as P0/P1 (`Not Applicable` for blockers).
- **Items to carry to Sprint 26 / stabilization backlog:** S25-FB-005 owner KPI
  confirmation and unimplemented ODE-002/004/005/006 enhancement candidates (`Backlog`).

Status legend used: `Resolved`, `Watch`, `Backlog`, `Blocked`, `Not Applicable`.

## 6. Owner Dashboard Status

- **Review readiness:** Owner Dashboard is usable for early owner review; Sprint 25.4
  documented the owner review questions and enhancement backlog.
- **Branch-level visibility:** Branch filter / drilldown covered by existing tests
  (`OwnerDashboardBranchFilterDrilldownTest` PASS in 25.1 gate).
- **Receivable visibility:** Sprint 25.5 added a read-only per-branch RME receivable
  summary table ("Ringkasan Piutang per Cabang") aggregating UNPAID + PARTIAL remaining
  balances (PAID excluded), with PARTIAL/UNPAID counts.
- **Operational usefulness:** Gives the owner a single per-branch receivable snapshot;
  the branch-filtered "Lihat Piutang" action is gated by `manage_rme_billing`.
- **Known limitations:** Export and several enhancement candidates (ODE-002/004/005/006)
  remain proposed/not implemented; KPI tooltip/helper text not yet added.
- **Watch during pilot:** Confirm owner-facing numbers match operational expectation;
  validate per-branch totals against source receivable views periodically.

## 7. RME Receivable / Follow-Up / Branch Receivable Summary Status

- **RME receivable summary:** Covered by `CashierBillingTest` and `RmeReceivableFollowUpTest`
  (PASS in 25.1 gate). Full-payment-only pilot rule remains in force.
- **Follow-up flow:** `OwnerDashboardReceivableFollowUpKpiTest` PASS; follow-up logic
  unchanged across Sprint 25 (no payment/follow-up logic modified).
- **Branch receivable summary:** Implemented in 25.5 as a read-only aggregate; branch
  isolation verified by test (selected branch returns only that branch; no operational
  records created when computed).
- **Smoke result (25.6):** Branch receivable summary deployed and smoke-tested on VPS;
  routes PASS; Laravel log CLEAN after smoke.
- **Risks:** Data accuracy / branch scoping interpretation — S25-FB-006 shows the main
  current risk is user misreading branch-scoped data, not a code defect.
- **Recommendation:** During pilot, perform periodic **manual validation** of receivable
  totals and branch scoping to confirm the summary matches source records.

## 8. VPS Deploy / Smoke Status

- **VPS path:** `/var/www/asia-dental-lab-v2`.
- **VPS IP:** `145.79.13.224`.
- **Last deploy (25.6):** `git pull --ff-only` PASS; optimize/config/route/view cache
  rebuilt PASS; VPS HEAD `f87b3d5`; branch `feature/sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary`.
- **Smoke (25.6):** Dashboard route, RME receivables route PASS; `php8.3-fpm` and `nginx`
  active; Laravel log CLEAN after smoke.
- **Sprint 25.9 note:** This phase performs **no redeploy** — it only reviews prior deploy
  evidence.
- **Watch on VPS:** Service health (php8.3-fpm, nginx), Laravel log error scan, and disk/
  storage state per the daily operations checklist.

## 9. Monitoring / Log / Backup Readiness Status

Based on Sprint 25.7 and 25.8:

- **Daily monitoring:** Sprint 25.8 daily operations checklist covers morning/closing checks
  (dashboard, login, Owner Dashboard KPI, RME, Kasir/Piutang RME, branch filter, inventory/
  lab access, storage/file access, log scan, service health).
- **Log review:** Sprint 25.7 log baseline = 0 bytes / CLEAN; support runbook defines a
  Laravel log handling SOP.
- **Backup readiness:** Sprint 25.7 documented DB backup readiness (`pg_dump`/`pg_restore`),
  runtime file backup readiness, and a backup directory inventory; Sprint 25.8 runbook adds
  a Manual Backup SOP.
- **Support escalation:** Sprint 25.8 support runbook provides S1–S4 severity, first response
  checklist, restart SOP, rollback SOP, what-not-to-do, and an escalation template.
- **Daily checklist:** Available with a daily report template and GO/WATCH/NO-GO semantics.
- **Limitations:** Backup **restore** has not been fully rehearsed end-to-end; monitoring is
  manual (no automated alerting documented).
- **Manual checks needed:** Run the daily checklist, scan logs daily, and verify a backup
  checkpoint each closing per the runbook.

## 10. Remaining Risks

| Risk | Area | Severity | Likelihood | Mitigation | Status |
|---|---|---|---|---|---|
| Users misread branch-scoped receivable data (e.g. PARTIAL filter) | Pilot feedback | Medium | Medium | Daily checklist + manual data validation; reinforce branch context in SOP | Watch |
| VPS service/log instability not caught early | VPS | Medium | Low | Daily service health + log scan per checklist; support runbook restart SOP | Watch |
| Receivable / branch summary accuracy vs source records | Receivable accuracy | Medium | Medium | Periodic manual validation of per-branch totals against Piutang RME views | Watch |
| Backup restore not fully rehearsed end-to-end | Backup readiness | Medium | Low | Schedule a restore rehearsal in a safe environment; follow Manual Backup SOP | Backlog |
| User adoption / SOP consistency across branches | Adoption | Medium | Medium | Daily checklist adherence; capture owner/user feedback at closing | Watch |
| Owner KPI confirmation (S25-FB-005) still pending | Reporting | Low | Medium | Obtain owner approval before implementing further dashboard KPIs | Backlog |

## 11. Continued Backlog

| Priority | Backlog Item | Reason | Suggested Sprint |
|---|---|---|---|
| P1 | Rehearse DB backup restore end-to-end in a safe environment | Backup readiness documented but restore not yet exercised | Sprint 26 |
| P2 | Confirm owner dashboard KPIs (S25-FB-005) and implement approved KPIs | Triaged, awaiting owner approval | Sprint 26 |
| P2 | ODE-002 KPI helper text / tooltip for receivable & follow-up cards | Improves owner interpretation, reduces data-misread risk | Sprint 26 |
| P2 | ODE-006 monthly business review snapshot | Owner-requested reporting value | Sprint 26 / 27 |
| P3 | ODE-004 owner dashboard export summary | Convenience reporting | Later |
| P3 | ODE-005 RME → Lab funnel clarity polish | UX polish only | Later |

> All items above are documentation-level proposals. No code for these has been written
> in Sprint 25.9.

## 12. GO / WATCH / NO-GO Criteria

### GO

Pilot may continue / expand if:

- No production blocker.
- Core pilot flow stable.
- Owner Dashboard sufficient for early review.
- Critical feedback already triaged.
- Support runbook and daily checklist available.
- Monitoring and backup readiness at least minimally available.

### WATCH

Pilot may continue **under supervision** if:

- Non-blocking residual risks remain.
- UX / data validation backlog still open.
- Manual monitoring still required.
- Receivable / branch summary needs periodic validation.

### NO-GO

Pilot must **not** continue if:

- A core transaction flow is broken.
- Branch / receivable data is unreliable.
- VPS is unstable.
- Backup readiness is unavailable.
- No support process exists.

## 13. Final Decision

```text
Decision: WATCH
```

Reasoning:

- Sprint 25.1–25.8 built a stabilization baseline, feedback intake, a feedback triage pass
  with no production blocker, Owner Dashboard review enhancements, a per-branch receivable
  summary, a VPS deploy + smoke, a monitoring + backup readiness baseline, a daily operations
  checklist, and a support runbook.
- However, the pilot is still in progress and carries residual non-blocking risks (data
  interpretation, receivable accuracy validation, un-rehearsed backup restore, adoption/SOP
  consistency) that require ongoing supervision.
- The safest, most conservative decision is **WATCH**, not full GO. The pilot may continue on
  a limited basis with the daily checklist, monitoring, backup readiness, and support runbook
  acting as guardrails.

This is intentionally **not** full GO (residual risks are not all proven low) and **not**
NO-GO (no serious blocker was found in the reviewed documents).

## 14. Recommended Next Actions

1. Continue the pilot with status **WATCH**.
2. Run the daily operations checklist (`docs/pilot_daily_operations_checklist.md`).
3. Review the pilot feedback backlog (`docs/pilot_feedback_backlog.md`) regularly.
4. Monitor the Owner Dashboard and the branch receivable summary.
5. Manually validate receivable / follow-up data during the pilot.
6. Verify backup readiness per the support runbook (`docs/pilot_support_runbook.md`).
7. Plan Sprint 26 for stabilization follow-up / pilot hardening (restore rehearsal, owner KPI
   confirmation, dashboard helper text).

## 15. Validation

Commands run for this docs-only phase:

```bash
git status --short
git diff --stat
git diff --check
graphify update .
```

## 16. Files Changed

- `docs/sprint_25_phase_25_9_pilot_feedback_review_go_watch_no_go_report.md`
- `docs/pilot_go_watch_no_go_report.md`
- `docs/graphify_sprint_25_9_update.md`

## 17. Final Notes

Sprint 25.9 is **docs/report-only**. It does not change production code, migrations,
deployment, VPS configuration, or the database. The final decision is a conservative
**WATCH**, with the daily checklist, monitoring, backup readiness, and support runbook as
pilot guardrails.
