# Sprint 26 Phase 26.1 — Pilot WATCH Stabilization Plan + Sprint 26 Backlog Kickoff

## 1. Phase Summary

Sprint 26 Phase 26.1 opens the stabilization cycle after Sprint 25.9 produced a conservative
`WATCH` decision for the pilot.

This phase is docs/report-only and converts the remaining WATCH risks into a structured
stabilization plan and Sprint 26 backlog. It does not change application behavior. It only
translates the Sprint 25.9 WATCH findings into actionable stabilization tracks, a prioritized
backlog, and a recommended Sprint 26 phase plan.

## 2. Mode and Safety Constraints

- Mode: Limit Saver 1
- Scope: Docs/report-only
- Risk level: Low
- No production code changes
- No VPS deployment
- No migrations
- No full test suite
- No database changes
- Graphify update required after documentation changes

## 3. Baseline

| Item | Value |
|---|---|
| Previous phase | Sprint 25 Phase 25.9 |
| Previous decision | WATCH |
| Previous commit | 38a14fd |
| Previous tag | sprint-25-phase-25-9-pilot-feedback-review-go-watch-no-go-report |
| Current phase | Sprint 26 Phase 26.1 |
| Current goal | Convert WATCH findings into stabilization plan and Sprint 26 backlog |
| Branch | feature/sprint-26-phase-26-1-pilot-watch-stabilization-plan-backlog-kickoff |

## 4. Source Documents Reviewed

| Document | Purpose | Key Findings |
|---|---|---|
| `docs/sprint_25_phase_25_9_pilot_feedback_review_go_watch_no_go_report.md` | Final WATCH decision report | Decision = WATCH; not GO (residual risks not all proven low), not NO-GO (no serious blocker found). Lists 6 remaining risks and a continued backlog (P1 restore rehearsal; P2 owner KPI, helper text). |
| `docs/pilot_go_watch_no_go_report.md` | Management-facing GO/WATCH/NO-GO summary | Decision = WATCH. Pilot may continue on a limited basis if daily checklist, monitoring, backup readiness, and support runbook are actively followed. Recommends Sprint 26 stabilization follow-up. |
| `docs/pilot_feedback_backlog.md` | Pilot feedback backlog | 3 tracked items: ODE-001 (Resolved — per-branch receivable summary), S25-FB-006 (Watch — PARTIAL filter was a data reality, no code defect), S25-FB-005 (Backlog — owner KPI confirmation pending). |
| `docs/pilot_daily_operations_checklist.md` | Daily pilot guardrail | Morning + closing checklists, VPS quick commands, daily report template, GO/WATCH/NO-GO status meanings. |
| `docs/pilot_support_runbook.md` | Support escalation guide | Severity levels, first-response checklist, restart/log/backup/rollback SOPs, what-not-to-do, escalation template. |
| `docs/graphify_sprint_25_9_update.md` | Graphify update record | Records `graphify update .` run after Sprint 25.9 docs; docs-only, no code changes. |
| `docs/sprint_25_phase_25_7_pilot_monitoring_backup_readiness_baseline.md` | Monitoring + backup readiness baseline | DB backup readiness (pg_dump), runtime file backup readiness, backup directory inventory; restore rehearsal not yet exercised end-to-end. |

## 5. WATCH Findings Converted to Stabilization Tracks

| Track | Source WATCH Risk | Stabilization Goal | Owner / Role | Output |
|---|---|---|---|---|
| Receivable Validation | Receivable / branch summary accuracy vs source records still requires manual validation | Confirm summary accuracy against sample transactions | Admin / Finance / Owner | Validation checklist |
| Branch Receivable Summary | Users may misread branch-scoped receivable data (e.g. PARTIAL filter) — interpretation/scoping risk | Confirm branch-level summary is clear and accurate | Owner / Admin Cabang | Branch summary review |
| RME Follow-Up | Follow-up flow unchanged in code but needs pilot monitoring for consistency | Confirm follow-up status is usable and consistent | RME / Admin | Follow-up review notes |
| Backup Restore Rehearsal | Backup readiness documented but restore not yet exercised end-to-end | Perform safe restore rehearsal in non-production environment | IT / Admin | Restore rehearsal note |
| Monitoring / Logs | VPS service/log instability could go uncaught; manual monitoring still needed | Keep daily log review and escalation path active | IT / Admin | Daily monitoring notes |
| SOP Adoption | User adoption / SOP consistency across branches not yet proven | Monitor adoption and support usage | PIC Klinik / Admin | SOP adoption notes |
| Owner KPI Confirmation | S25-FB-005 owner dashboard KPI confirmation still pending | Confirm dashboard metrics match owner expectations | Owner / IT | KPI confirmation notes |

## 6. Stabilization Plan

### 6.1 Receivable Validation

- **Objective:** Confirm the RME receivable / branch receivable summary matches source records.
- **Manual validation method:** Pick a sample of paid/PARTIAL invoices and reconcile totals
  against the Piutang RME views and the per-branch summary table.
- **Sample size recommendation:** Start with 5–10 invoices per branch per validation cycle.
- **Acceptance criteria:** Sampled summary totals match source records with no unexplained
  variance.
- **Escalation trigger:** Any mismatch between summary totals and source records.
- **Output document or checklist:** Receivable validation checklist (Sprint 26.2 backlog item
  `S26-BL-001`).

### 6.2 Branch Receivable Summary Review

- **Objective:** Confirm branch-level receivable summary is correctly scoped and clearly
  interpreted.
- **Branch scoping checks:** Confirm the selected branch returns only that branch's data
  (e.g. the only PARTIAL invoice belongs to Cabang Antang `branch_id=3`, not Cabang Landak
  `branch_id=2`, per S25-FB-006).
- **UI interpretation checks:** Confirm labels and filters do not mislead users into reading
  another branch's data.
- **Owner/Admin review checklist:** Owner and Admin Cabang jointly review a branch summary
  sample.
- **Acceptance criteria:** Branch scoping is confirmed correct; owner/admin agree the view is
  not misleading.
- **Escalation trigger:** Branch scoping appears incorrect, or repeated user misreads.

### 6.3 RME Follow-Up Review

- **Objective:** Confirm RME receivable follow-up status is usable and consistent during the
  pilot.
- **Follow-up status review:** Observe follow-up status values during real pilot usage
  (logic unchanged across Sprint 25; full-payment-only rule remains in force).
- **Expected user behavior:** Staff use follow-up status consistently without manual
  workarounds.
- **Acceptance criteria:** Follow-up status remains consistent and interpretable across the
  pilot window.
- **Escalation trigger:** Follow-up status becomes inconsistent or is misused.

### 6.4 Backup Restore Rehearsal

- **Objective:** Prove the backup restore path end-to-end without touching production.
- **Non-production restore rehearsal only:** Restore the latest pg_dump into a separate,
  non-production database/environment.
- **Do not touch production database:** Never run restore against the live VPS pilot DB;
  never `migrate:fresh` or `db:wipe`.
- **Acceptance criteria:** A backup file restores cleanly into a non-production environment and
  the app boots against it.
- **Evidence to capture:** Backup timestamp, restore command output, post-restore smoke notes.
- **Escalation trigger:** Backup file missing/corrupt or restore fails.

### 6.5 Monitoring and Log Review

- **Objective:** Maintain operational visibility and catch VPS/log issues early.
- **Daily monitoring rhythm:** Run the daily operations checklist (morning + closing).
- **What to check:** Service health, Laravel log scan, route/cache quick check, dashboard access.
- **What to record:** Daily report template entry with GO/WATCH/NO-GO status.
- **Escalation trigger:** New errors in Laravel log or service instability per the support
  runbook.

### 6.6 SOP Adoption Monitoring

- **Objective:** Confirm staff consistently follow the daily checklist and support runbook.
- **Daily checklist usage:** Track whether morning/closing checklists are actually completed.
- **Support runbook usage:** Confirm escalations follow the runbook template.
- **User feedback capture:** Capture owner/user feedback at closing into the feedback backlog.
- **Acceptance criteria:** Checklist and runbook are used consistently across branches.

### 6.7 Owner KPI Confirmation

- **Objective:** Confirm Owner Dashboard KPIs match the owner's business review needs
  (S25-FB-005).
- **KPI list to confirm:** Receivable totals, follow-up KPIs, per-branch receivable summary,
  and any owner-requested business-review metrics.
- **Owner review questions:** Which KPIs are essential for monthly business review? Are current
  figures interpreted correctly?
- **Acceptance criteria:** Owner confirms the KPI set and interpretation.
- **Backlog trigger:** Any new/changed KPI request becomes a scoped implementation backlog item
  (not done in this docs-only phase).

## 7. Sprint 26 Backlog Kickoff

| Priority | Backlog Item | Track | Reason | Suggested Phase |
|---|---|---|---|---|
| P1 | Receivable validation checklist | Receivable Validation | Needed before full GO | Sprint 26.2 |
| P1 | Backup restore rehearsal plan | Backup Restore | Restore path must be proven outside production | Sprint 26.3 |
| P1 | Owner KPI confirmation checklist | Owner KPI | Owner dashboard must match business review needs | Sprint 26.4 |
| P2 | Branch receivable sample audit | Branch Summary | Reduce branch scoping risk | Sprint 26.5 |
| P2 | RME follow-up monitoring sheet | RME Follow-Up | Monitor pilot consistency | Sprint 26.6 |
| P2 | SOP adoption review | SOP Adoption | Confirm operational discipline | Sprint 26.7 |
| P3 | Pilot stabilization closure report | Closure | Prepare final GO/WATCH/NO-GO after stabilization | Sprint 26.8 |

> The full backlog (with detailed items and IDs) is maintained in
> `docs/sprint_26_stabilization_backlog.md`.

## 8. Recommended Sprint 26 Phase Plan

| Phase | Title | Type | Risk | Description |
|---|---|---|---|---|
| 26.1 | Pilot WATCH Stabilization Plan + Backlog Kickoff | Docs-only | Low | Current phase |
| 26.2 | Receivable Validation Checklist + Sample Audit Plan | Docs/checklist | Low | Validate receivable accuracy |
| 26.3 | Backup Restore Rehearsal Plan | Docs/runbook | Low | Prepare safe restore test |
| 26.4 | Owner KPI Confirmation Checklist | Docs/checklist | Low | Confirm dashboard metrics |
| 26.5 | Branch Receivable Review Notes | Docs/report | Low | Confirm branch scoping |
| 26.6 | RME Follow-Up Monitoring Notes | Docs/report | Low | Track follow-up pilot use |
| 26.7 | SOP Adoption Review | Docs/report | Low | Confirm checklist/runbook usage |
| 26.8 | Sprint 26 Stabilization Closure GO/WATCH/NO-GO | Docs/report | Low | Decide next readiness status |

## 9. Acceptance Criteria

Sprint 26.1 is complete when:

- Stabilization tracks are defined.
- WATCH risks from Sprint 25.9 are mapped to action items.
- Sprint 26 backlog exists.
- Suggested Sprint 26 phase plan exists.
- No production code changed.
- No VPS deploy performed.
- No migration performed.
- No full test suite run.
- Graphify update is run and documented.
- Git diff confirms docs-only changes.

## 10. Validation Commands

```bash
git status --short
git diff --stat
git diff --check
graphify update .
```

## 11. Files Changed

- `docs/sprint_26_phase_26_1_pilot_watch_stabilization_plan_backlog_kickoff.md`
- `docs/pilot_watch_stabilization_plan.md`
- `docs/sprint_26_stabilization_backlog.md`
- `docs/graphify_sprint_26_1_update.md`

## 12. Final Notes

Sprint 26.1 does not change application behavior. It only converts the Sprint 25.9 WATCH
decision into a structured stabilization plan and Sprint 26 backlog. The pilot remains at
status `WATCH` — this phase does not declare GO, does not deploy to the VPS, does not run
migrations, and does not run the full test suite. Any discovered issue that requires a code
change must be scoped into a separate implementation phase with its own branch, validation,
and rollback notes.
