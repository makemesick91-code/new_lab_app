# Sprint 28 Phase 28.4 — Monitoring, Backup & Restore Rehearsal Planning

## Status

**Mode:** Monitoring / backup / restore rehearsal planning only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Backup execution:** No real backup executed
**Restore execution:** No real restore executed
**Destructive data operation:** No destructive data operation
**Baseline:** Sprint 28.3 GO at `7f54016`

## Purpose

Sprint 28 Phase 28.4 prepares the pilot's monitoring, backup verification, and restore
rehearsal posture before any deeper implementation or production rollout. It is planning and
checklist work only; nothing here executes monitoring tooling, backup automation, or a real
restore.

Goals:

- Prepare a safe pilot monitoring checklist.
- Prepare a backup verification checklist.
- Prepare a restore rehearsal plan without executing any real restore.
- Protect the Sprint 27 RME Control Workflow and the Sprint 28 pilot operations.
- Provide support/admin readiness before deeper implementation or production rollout.
- Keep this phase planning-only and non-destructive.

This phase is documentation / planning / checklist test only. It does not change RME, payment,
receivable, cashier, odontogram, invoice, route, service, controller, model, view, migration,
seeder, queue, job, notification, cron, scheduler, supervisor, or configuration behavior.

## Non-goals

- No monitoring tool implementation.
- No backup automation implementation.
- No restore execution.
- No database export/import execution.
- No server service restart.
- No cron/scheduler/supervisor change.
- No route/controller/service/model/view change.
- No migration/schema change.
- No production data mutation.
- No business rule change.

## Planning Assumptions

- The pilot app is already running from a prior deployed baseline.
- The current branch work is docs/test only.
- Support/admin may check logs, backup inventory, and disk usage manually.
- A restore rehearsal must be planned against a safe non-production target only.
- Production/pilot data must not be overwritten.
- Any future restore rehearsal must require explicit approval and a separate execution phase.

## Monitoring Planning Checklist

This is a manual monitoring checklist. Each row is a planning checkpoint, not an implemented
monitoring feature.

| # | Check | Planned action |
|---|-------|----------------|
| 1 | App reachability check | Confirm the pilot app responds at its base URL. |
| 2 | Login page response check | Confirm the login page loads and responds. |
| 3 | Key menu/route quick check | Spot-check key menus/routes load without error. |
| 4 | Laravel log review | Review `storage/logs/laravel.log` for new errors. |
| 5 | PHP-FPM/web server error log review | Review PHP-FPM / web server error logs as applicable. |
| 6 | Disk usage check | Check disk usage is within safe headroom. |
| 7 | Database connectivity symptom check | Watch for DB connection errors in logs/symptoms. |
| 8 | Queue/scheduler status note | Note queue/scheduler status if applicable. |
| 9 | Backup job/presence check | Confirm a recent backup job/file is present. |
| 10 | Operator feedback issue count check | Count operator-reported issues for the day. |
| 11 | Critical blocker escalation check | Confirm any critical blocker is escalated. |

Checklist:

- [ ] App reachability check.
- [ ] Login page response check.
- [ ] Key menu/route quick check.
- [ ] Laravel log review.
- [ ] PHP-FPM/web server error log review as applicable.
- [ ] Disk usage check.
- [ ] Database connectivity symptom check.
- [ ] Queue/scheduler status note if applicable.
- [ ] Backup job/presence check.
- [ ] Operator feedback issue count check.
- [ ] Critical blocker escalation check.

## Backup Readiness Checklist

Verification-only. This phase does not execute a real backup.

| # | Item | Planned verification |
|---|------|----------------------|
| 1 | Database backup presence | Confirm a database backup exists. |
| 2 | Runtime file backup presence | Confirm runtime/application file backup exists. |
| 3 | Storage/uploads backup presence | Confirm storage/uploads backup exists. |
| 4 | Backup timestamp verification | Confirm the latest backup timestamp is recent. |
| 5 | Backup file size sanity check | Confirm backup size is plausible, not near-empty. |
| 6 | Backup location/inventory note | Record where backups are stored. |
| 7 | Backup retention note | Record how long backups are retained. |
| 8 | Backup access permission note | Record who can access backups. |
| 9 | Backup encryption/privacy note | Note encryption/privacy posture if applicable. |
| 10 | Backup owner/responsibility | Record who owns the backup process. |
| 11 | Backup failure escalation | Record escalation path when backup is missing/failed. |

Checklist:

- [ ] Database backup presence.
- [ ] Runtime file backup presence.
- [ ] Storage/uploads backup presence.
- [ ] Backup timestamp verification.
- [ ] Backup file size sanity check.
- [ ] Backup location/inventory note.
- [ ] Backup retention note.
- [ ] Backup access permission note.
- [ ] Backup encryption/privacy note if applicable.
- [ ] Backup owner/responsibility.
- [ ] Backup failure escalation.

## Restore Rehearsal Planning

Planning-only. No real restore is executed in this phase. Execution steps are documented for a
future, explicitly approved phase only.

- Restore target must be non-production.
- Restore source backup selected by timestamp.
- Restore objective defined before rehearsal.
- Restore owner assigned.
- Restore window planned.
- Restore pre-checklist prepared.
- Restore execution steps documented for future phase only.
- Restore verification checklist prepared.
- Rollback/cleanup checklist prepared for future phase.
- Evidence/log notes prepared.
- Explicit approval required before any real rehearsal.

## Restore Verification Checklist

To be run only against a non-production restore target in a future approved phase.

- [ ] App boots on non-production target.
- [ ] Login works on restored target.
- [ ] Key menus load.
- [ ] Patient search sample works.
- [ ] Visit/RME sample exists.
- [ ] Odontogram sample exists when applicable.
- [ ] Cashier/invoice sample exists when applicable.
- [ ] Receivable sample exists when applicable.
- [ ] Reports/export sample can be checked when applicable.
- [ ] No production/pilot data overwritten.

## RME / Payment / Receivable Safety Notes

Monitoring, backup, and restore planning must not weaken the Sprint 27 RME Control Workflow GO
behavior or the Sprint 28 pilot rules:

- Control visits remain protected: same patient/RM but new visit.
- Old RME/odontogram/invoice must not be overwritten.
- Payment allocation remains FIFO previous receivable first.
- Parent receivable must not block control completion.
- Rp0 invoice must remain excluded from active receivables.
- Monitoring/backup/restore planning must not change any business rule.
- Restore rehearsal must never be executed against active pilot data without explicit approval.

## Support / Admin Daily Evidence Format

Record one row per monitoring/backup check.

| Field | Description |
|-------|-------------|
| Date/time | When the check was performed. |
| Checked by | Support/admin role and name. |
| App reachable status | Reachable / not reachable. |
| Login/menu status | Login and key menus OK / not OK. |
| Laravel log status | Clean / new errors observed. |
| Disk usage status | Safe / warning / critical. |
| Backup presence status | Present / missing. |
| Backup timestamp | Latest backup timestamp. |
| Backup location/reference | Where the backup is stored. |
| Operator issue count | Number of operator-reported issues. |
| Critical blocker count | Number of critical blockers. |
| Follow-up owner | Who owns the follow-up. |
| Notes/evidence path | Free-text notes and evidence location. |

## Incident Escalation Rules

Escalate immediately when any of the following occur:

- App unreachable.
- Login fails for multiple users.
- Laravel log has repeated critical errors.
- Disk usage near full.
- Backup missing or stale.
- Backup size suspiciously small.
- Restore rehearsal target accidentally points to production.
- Any evidence of overwritten RME/odontogram/invoice.
- Receivable/payment allocation anomaly.
- Rp0 invoice appears in active receivables.
- Patient data privacy concern.

## Future Implementation Candidate Backlog

Planning-only. Nothing here is implemented in this phase.

- Monitoring dashboard candidate.
- Daily health check command candidate.
- Backup inventory page candidate.
- Backup alert candidate.
- Restore rehearsal SOP execution phase candidate.
- Backup retention policy candidate.
- Offsite backup candidate.
- Audit log/evidence attachment candidate.
- Branch-aware monitoring candidate.
- Operator issue dashboard candidate.

## Risk and Mitigation

| Risk | Mitigation |
|------|------------|
| Missing backup risk | Verify backup presence daily; escalate when missing. |
| Stale backup risk | Verify backup timestamp; escalate stale backups. |
| Corrupt backup risk | Sanity-check size; plan restore rehearsal on non-production target. |
| Restore tested on wrong target risk | Require non-production target and explicit approval. |
| Production overwrite risk | Never restore over active pilot/production data. |
| Patient data privacy risk | Protect backup access; note encryption/privacy posture. |
| Disk full risk | Monitor disk usage; escalate near-full warnings. |
| Silent Laravel error risk | Review Laravel log daily for new/repeated errors. |
| Operator issue not escalated risk | Track issue/blocker counts and follow-up owners. |
| False GO due incomplete evidence risk | Require complete evidence before any GO. |

## GO / NO-GO

**GO** only if:

- The monitoring/backup/restore rehearsal planning document is complete.
- `docs/sprint_history.md` is updated.
- The focused test passes.
- No production code change.
- No migration.
- No deployment.
- No real backup execution.
- No real restore execution.
- No destructive operation.
- No business rule change.

**NO-GO** if:

- Any production code change.
- Any migration or deploy is introduced.
- Any backup/restore execution is performed in this phase.
- Any runtime behavior change.
- Any destructive data operation.
- The workflow plan is incomplete.
- Sprint history or test is missing.

## Recommended Next Phase

Sprint 28 Phase 28.5 may be one of:

- Pilot issue triage and stabilization backlog.
- WhatsApp reminder manual pilot SOP.
- Monitoring/backup/restore rehearsal execution on a non-production target.
- WhatsApp reminder technical design, planning-only.

## Validation Plan

- `php artisan test --filter=Sprint28Phase284MonitoringBackupRestoreRehearsalPlanning`
- `vendor/bin/pint --test tests/Feature/Sprint28/Sprint28Phase284MonitoringBackupRestoreRehearsalPlanningTest.php`
- `git diff --check`

## Decision

GO CANDIDATE FOR PR REVIEW
