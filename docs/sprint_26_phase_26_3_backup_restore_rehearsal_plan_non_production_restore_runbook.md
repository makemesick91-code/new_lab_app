# Sprint 26 Phase 26.3 — Backup Restore Rehearsal Plan + Non-Production Restore Runbook

## 1. Phase Summary

Sprint 26 Phase 26.3 continues the pilot WATCH stabilization cycle by defining a backup restore rehearsal plan and a non-production restore runbook.

This phase converts Sprint 26.1 backlog item `S26-BL-003` (P1, Backup Restore track) into operational backup restore readiness artifacts. It addresses the WATCH risk that the backup restore path has not yet been exercised end-to-end outside production.

## 2. Mode and Safety Constraints

- Mode: Limit Saver 1
- Scope: Docs/runbook-only
- Risk level: Low
- No production code changes
- No VPS deployment
- No migrations
- No database changes
- No production database queries
- No real restore execution
- No `pg_restore`, `psql restore`, `dropdb`, or `createdb` execution
- Graphify update required after documentation changes

## 3. Baseline

| Item | Value |
|---|---|
| Previous phase | Sprint 26 Phase 26.2 |
| Previous commit | fe95c0a |
| Previous tag | sprint-26-phase-26-2-receivable-validation-checklist-branch-sample-audit-plan |
| Pilot status | WATCH |
| Current phase | Sprint 26 Phase 26.3 |
| Current goal | Define backup restore rehearsal plan and non-production restore runbook |
| Source backlog item | S26-BL-003 (P1, Backup Restore) |

## 4. Source Documents Reviewed

| Document | Purpose | Key Findings |
|---|---|---|
| `docs/sprint_26_phase_26_1_pilot_watch_stabilization_plan_backlog_kickoff.md` | Sprint 26 stabilization plan | Section 6.4 defines backup restore rehearsal: non-production restore only; never touch production DB; never `migrate:fresh`/`db:wipe`; acceptance = clean restore into non-production + app boots; evidence = backup timestamp, restore command output, smoke notes. |
| `docs/pilot_watch_stabilization_plan.md` | WATCH stabilization routine | Exit criteria include "Backup restore rehearsal is completed outside production." Escalation if backup file is missing or cannot be verified. |
| `docs/sprint_26_stabilization_backlog.md` | Sprint 26 backlog | `S26-BL-003` — P1, Backup Restore track, "Non-production only", goal: prepare safe restore rehearsal outside production, output: restore rehearsal runbook, suggested phase Sprint 26.3. |
| `docs/sprint_26_phase_26_2_receivable_validation_checklist_branch_receivable_sample_audit_plan.md` | Previous Sprint 26 validation phase | Establishes docs-only PASS/WATCH/FAIL classification, acceptance criteria, escalation triggers, and WATCH-to-GO mapping pattern reused here. |
| `docs/sprint_25_phase_25_7_pilot_monitoring_backup_readiness_baseline.md` | Backup readiness baseline | Production DB `asia_dental_lab_pilot` (host `127.0.0.1`, port `5432`, user `dental_pilot`). Backups stored under `/var/backups/daengtisiams/...` in PostgreSQL custom format with `.dump`, `.dump.list`, and `.dump.sha256` files; `pg_restore -l` verification PASS. Backups stay on VPS, not committed to Git. No restore into production database. |
| `docs/sprint_25_phase_25_8_pilot_daily_operations_checklist_support_runbook.md` | Checklist and support runbook phase | Establishes daily operations checklist and support runbook used as escalation reference. |
| `docs/pilot_support_runbook.md` | Support escalation guide | Severity levels S1–S4 (S1 includes data loss risk). Contains Manual Backup SOP and Rollback SOP; "What NOT To Do During Pilot" forbids destructive DB operations. Escalation template provided. |
| `docs/pilot_daily_operations_checklist.md` | Daily pilot guardrail | Daily operational guardrail referenced for routine backup verification. |
| `docs/rme_pilot_backup_import_guide.md` | Backup import discipline | Direct SQL restore over the Sprint 20+ DB is not allowed; backups placed under `storage/app/imports/`. Reinforces "never restore backup SQL directly over production." |
| `docs/sprint_25_phase_25_9_pilot_feedback_review_go_watch_no_go_report.md` | WATCH decision source | Final decision WATCH; backup readiness present but restore not yet rehearsed end-to-end is a remaining risk. |
| `docs/pilot_go_watch_no_go_report.md` | Management GO/WATCH/NO-GO summary | GO conditions include proven operational readiness; NO-GO triggers include data loss risk. Restore rehearsal supports moving WATCH → GO. |

If a document is missing, write `Not found at review time`. All listed documents were available at review time.

## 5. Backlog Mapping

| Backlog ID | Item | Sprint 26.3 Output |
|---|---|---|
| S26-BL-003 | Backup restore rehearsal plan | `docs/backup_restore_rehearsal_plan.md` |
| Supporting | Non-production restore runbook | `docs/non_production_restore_runbook.md` |
| Supporting | Evidence capture | `docs/backup_restore_rehearsal_evidence_template.md` |

## 6. Backup Restore Rehearsal Objectives

Define objectives:

- Confirm backup files are usable.
- Confirm restore flow is understood before full GO.
- Define safe non-production restore rehearsal.
- Prevent accidental production database modification.
- Define evidence required for restore readiness.
- Define escalation path for restore failure.
- Support future WATCH-to-GO decision.

## 7. Rehearsal Scope

In scope:

- Non-production restore planning.
- Restore checklist.
- Backup file verification checklist.
- Evidence capture template.
- Acceptance criteria.
- Escalation triggers.
- Rollback/safety notes.

Out of scope:

- Real restore execution in this phase.
- Production database restore.
- Production database query.
- VPS deployment.
- Migration.
- Full test suite.
- Production configuration change.
- Backup automation changes.

## 8. Safety Guardrails

State clearly:

- Never restore into production database.
- Never run destructive database commands against production.
- Use clearly named non-production database only.
- Confirm database name before any future restore rehearsal.
- Confirm host, port, database, and user before any future restore rehearsal.
- Capture command plan before execution in a future phase.
- Require explicit approval before any real restore rehearsal.
- Stop immediately if environment cannot be verified.

Production identifiers below are listed **for avoidance only** — they must never be used as restore targets: production DB `asia_dental_lab_pilot`, host `127.0.0.1`/port `5432`, user `dental_pilot`, VPS `145.79.13.224`, path `/var/www/asia-dental-lab-v2`.

## 9. Non-Production Restore Target Recommendation

Recommended future restore rehearsal target:

| Field | Recommended Value |
|---|---|
| Environment | Local or isolated staging/non-production |
| Database name | `asia_dental_lab_restore_rehearsal` |
| Production database | `asia_dental_lab_pilot` — must not be used |
| VPS production path | `/var/www/asia-dental-lab-v2` — reference only, do not modify |
| VPS IP | `145.79.13.224` — reference only, do not deploy |
| Backup source | Latest verified backup file (PostgreSQL custom format `.dump` from Sprint 25.7 baseline) |
| Restore method | PostgreSQL restore into non-production database only |

## 10. Restore Rehearsal Acceptance Criteria

Restore rehearsal can be considered successful only when:

- Backup file exists and has expected timestamp.
- Backup file size is reasonable and non-zero.
- Restore target is confirmed non-production.
- Restore command plan is reviewed before execution.
- Restore completes without fatal error in future rehearsal.
- Basic application/database sanity checks are defined.
- Evidence is captured.
- No production database or VPS production config is modified.
- Any issue is added to Sprint 26 backlog.

## 11. Escalation Triggers

Escalate if:

- Backup file is missing.
- Backup file is zero-size or suspiciously small.
- Backup timestamp is stale.
- Restore target cannot be verified as non-production.
- Restore command references production database.
- Restore rehearsal fails.
- Restored data is incomplete or unreadable.
- Permission error blocks restore.
- Application cannot connect to restored non-production database in future rehearsal.
- Evidence cannot be captured.

Escalate using the escalation template in `docs/pilot_support_runbook.md`. Treat data loss risk as S1 (Critical) per the runbook severity levels.

## 12. WATCH-to-GO Readiness Impact

Sprint 26.3 supports future GO decision if:

- Restore rehearsal plan is documented.
- Non-production restore runbook is available.
- Evidence template is available.
- Safety guardrails prevent production impact.
- Future restore rehearsal can be executed with clear approval and rollback notes.

This maps directly to the Stabilization Exit Criteria in `docs/pilot_watch_stabilization_plan.md`
("Backup restore rehearsal is completed outside production") and to the GO conditions in
`docs/pilot_go_watch_no_go_report.md`.

Pilot remains WATCH until restore readiness is proven or explicitly accepted by owner/IT.

## 13. Recommended Next Actions

1. Review `docs/backup_restore_rehearsal_plan.md`.
2. Review `docs/non_production_restore_runbook.md`.
3. Use `docs/backup_restore_rehearsal_evidence_template.md` when restore rehearsal is actually executed in a future phase.
4. Do not execute restore in Sprint 26.3.
5. Plan a future execution phase if owner/IT approves non-production restore rehearsal.
6. Keep pilot status as WATCH until restore rehearsal evidence is available.

## 14. Validation Commands

```bash
git status --short
git diff --stat
git diff --check
graphify update .
```

## 15. Files Changed

- `docs/sprint_26_phase_26_3_backup_restore_rehearsal_plan_non_production_restore_runbook.md`
- `docs/backup_restore_rehearsal_plan.md`
- `docs/non_production_restore_runbook.md`
- `docs/backup_restore_rehearsal_evidence_template.md`
- `docs/graphify_sprint_26_3_update.md`

## 16. Final Notes

Sprint 26.3 is docs/runbook-only. It prepares backup restore rehearsal discipline before any future restore execution. It does not perform restore, deployment, migration, or production database access.
