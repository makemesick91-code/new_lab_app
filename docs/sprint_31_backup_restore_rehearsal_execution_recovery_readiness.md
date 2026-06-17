# Sprint 31 — Backup Restore Rehearsal Execution & Recovery Readiness

Status: Draft / Local Validation Pending
Baseline: Sprint 30 GO at 53c3442
Scope: Docs / non-production rehearsal execution checklist / recovery readiness test only

## Purpose

Sprint 31 follows Sprint 29.4 (monitoring + backup/restore rehearsal non-production target),
Sprint 29.5 (pilot safety review / final stabilization checklist), and Sprint 30 (pilot
execution bugfix + operational smoke).

It converts the Sprint 29.4 and Sprint 29.5 backup/restore readiness *planning* into a safe,
controlled, auditable **non-production backup/restore rehearsal execution checklist** plus a
recovery readiness closure.

This document:

- Prepares a safe non-production backup/restore rehearsal execution checklist.
- Does **not** execute any real backup or restore.
- Defines readiness, evidence, decision gates, and recovery responsibilities **before** any
  real rehearsal is run in a separate, supervised, operator-confirmed workflow.

All commands in this document are **examples / checklist items only**. They are written for an
operator to run later in a supervised non-production session. Nothing here is executed as part
of the Sprint 31 documentation pass.

## Baseline references

```text
Sprint 29.4 GO: sprint-29-phase-29-4-monitoring-backup-restore-rehearsal-non-production-target-go at b6334fc
Sprint 29.5 GO: sprint-29-phase-29-5-pilot-safety-review-final-stabilization-checklist-go at 721bb55
Sprint 30 GO: sprint-30-pilot-execution-bugfix-operational-smoke-go at 53c3442
```

Lineage references:

- Sprint 29.4 doc: `docs/sprint_29_phase_29_4_monitoring_backup_restore_rehearsal_non_production_target.md`
- Sprint 29.5 doc: `docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md`
- Sprint 30 doc: `docs/sprint_30_pilot_execution_bugfix_operational_smoke.md`
- Backup/restore rehearsal plan: `docs/backup_restore_rehearsal_plan.md`
- Non-production restore runbook: `docs/non_production_restore_runbook.md`
- Evidence template reference: `docs/backup_restore_rehearsal_evidence_template.md`

Baseline HEAD for Sprint 31 work: `53c3442`.

## Non-production rehearsal scope

The rehearsal, when later executed in a separate supervised run, is constrained to:

- **Non-production target only** — never the live pilot/production environment.
- **Isolated database target** — a dedicated rehearsal database, never the production schema.
- **Isolated runtime file target** — a dedicated rehearsal storage path, never production `storage/`.
- **No production overwrite** — no restore step may write over any production database or file.
- **No production credentials in docs** — credentials are referenced by placeholder only.
- **No real patient data exposure** — only sanitized/approved datasets may be used; PHI/PII must
  be redacted unless privacy review has explicitly approved the dataset.
- **No destructive production operation** — no `migrate:fresh`, `db:wipe`, drop, or truncate
  against any production or shared environment.
- **No scheduled automation** — no cron/scheduler/job/queue/notification automation is added.
- **Manual operator-confirmed checklist only** — each step requires explicit operator confirmation.

## Backup inventory checklist

Document only — do **not** run these commands during the Sprint 31 pass.

- [ ] **Database dump source identification** — confirm the non-production DB name and connection.
  - Example: `mysqldump --no-tablespaces -u <NONPROD_USER> -p <NONPROD_DB> > backup_<env>_<UTC>.sql`
- [ ] **Runtime upload/storage directory identification** — confirm the runtime path to capture.
  - Example: `tar -czf storage_<env>_<UTC>.tar.gz storage/app/public`
- [ ] **Environment/config exclusion** — confirm `.env`, secrets, and credentials are excluded
  from the backup archive (config is reconstructed, not backed up with secrets).
- [ ] **Backup file naming convention** — `backup_<env>_<YYYYMMDDTHHMMSSZ>.sql` /
  `storage_<env>_<YYYYMMDDTHHMMSSZ>.tar.gz`.
- [ ] **Checksum / hash evidence** — record a checksum per artifact.
  - Example: `sha256sum backup_<env>_<UTC>.sql > backup_<env>_<UTC>.sql.sha256`
- [ ] **Backup file size evidence** — record byte size per artifact.
  - Example: `ls -l backup_<env>_<UTC>.sql`
- [ ] **Backup storage location evidence** — record the absolute path / bucket of stored artifacts.
- [ ] **Retention note** — record how long the rehearsal artifacts are kept and when deleted.
- [ ] **Privacy redaction note** — record whether the dataset is sanitized or production-derived,
  and the redaction/approval reference.
- [ ] **Operator and reviewer sign-off** — operator and reviewer names + timestamp.

## Restore rehearsal checklist

Document only — do **not** execute. Each command below is a placeholder for a later supervised run.

- [ ] **Target environment confirmation** — confirm environment is non-production.
- [ ] **Restore target DB name confirmation** — confirm the isolated rehearsal DB name.
- [ ] **Restore target file path confirmation** — confirm the isolated rehearsal storage path.
- [ ] **Pre-restore snapshot note** — capture current state of the rehearsal target before restore.
- [ ] **Restore command approval gate** — explicit operator + reviewer approval recorded before any
  restore command is run.
- [ ] **Database restore step placeholder**
  - Example: `mysql -u <NONPROD_USER> -p <NONPROD_REHEARSAL_DB> < backup_<env>_<UTC>.sql`
- [ ] **Runtime file restore step placeholder**
  - Example: `tar -xzf storage_<env>_<UTC>.tar.gz -C <REHEARSAL_STORAGE_PATH>`
- [ ] **Laravel cache clear placeholder**
  - Example: `php artisan config:clear && php artisan cache:clear && php artisan route:clear`
- [ ] **App health check placeholder**
  - Example: `php artisan about`
- [ ] **Route availability check placeholder**
  - Example: `php artisan route:list`
- [ ] **Login/access smoke placeholder** — confirm login page reachable and auth works.
- [ ] **RME/cashier/reporting smoke placeholder** — confirm core clinic/lab paths load.
- [ ] **Evidence capture** — record command output references, screenshots, and result per step.

## Recovery readiness gates

All gates must be satisfied (and recorded) before any real rehearsal execution is scheduled.

- [ ] **Backup inventory complete** — all backup inventory checklist items recorded.
- [ ] **Checksum recorded** — checksum/hash captured for each backup artifact.
- [ ] **Non-production target verified** — target confirmed not production.
- [ ] **Restore target empty/approved** — restore target is empty or its overwrite is approved.
- [ ] **Rollback path documented** — documented way to discard the rehearsal target safely.
- [ ] **Operator assigned** — named operator responsible for execution.
- [ ] **Reviewer assigned** — named reviewer responsible for verification.
- [ ] **Escalation contact assigned** — named escalation contact for incidents.
- [ ] **Privacy review complete** — dataset sanitization/approval confirmed.
- [ ] **GO / WATCH / NO-GO decision recorded** — explicit decision captured before execution.

## Post-restore smoke checklist

After a later supervised restore, confirm:

- [ ] **Application boots** — app starts without fatal error.
- [ ] **Login page reachable** — login route returns successfully.
- [ ] **Role/menu check** — pilot roles see correct menus/permissions.
- [ ] **RME visit page smoke** — RME visit list/detail loads.
- [ ] **Odontogram/medical record smoke** — odontogram and medical record render.
- [ ] **Cashier invoice/payment smoke** — invoice creation and payment recording reachable.
- [ ] **Receivable/piutang smoke** — receivable/piutang tracking reachable.
- [ ] **Print/export smoke** — receipt/print/export readiness confirmed.
- [ ] **WhatsApp manual reminder SOP evidence only** — manual reminder SOP referenced; no send.
- [ ] **Monitoring evidence placeholder** — monitoring evidence location recorded.
- [ ] **Backup/restore evidence location recorded** — final evidence path captured.

## Evidence template

| Field | Value |
| --- | --- |
| Date/time (UTC) | `<YYYY-MM-DDTHH:MM:SSZ>` |
| Environment | `<non-production env name>` |
| Operator | `<operator name>` |
| Reviewer | `<reviewer name>` |
| Backup identifier | `<backup_<env>_<UTC>>` |
| Restore target | `<rehearsal DB / path>` |
| Scenario | `<backup | restore | post-restore smoke>` |
| Expected result | `<expected outcome>` |
| Actual result | `<actual outcome>` |
| Evidence path | `<path to logs / screenshots / checksums>` |
| Issue severity | `<P0 | P1 | P2 | none>` |
| Decision | `<GO | WATCH | NO-GO>` |
| Follow-up owner | `<owner name>` |

## Incident and escalation matrix

| Severity | Definition | Owner | Action | Decision |
| --- | --- | --- | --- | --- |
| **P0** | Data loss, production overwrite risk, unrecoverable restore, or credential exposure | Escalation contact + reviewer | Halt immediately, preserve evidence, isolate target, notify owner | NO-GO until resolved |
| **P1** | Restore mismatch, missing evidence, failed critical smoke, or permission blocker | Operator + reviewer | Pause, document gap, remediate, re-verify affected step | WATCH until cleared |
| **P2** | Documentation gap, non-critical evidence mismatch, or training gap | Operator | Log follow-up, fix before closure | GO with follow-up |

## Go / Watch / No-Go criteria

- **GO** — Safe to proceed to a controlled non-production rehearsal execution in a separate
  supervised run; all readiness gates satisfied, no open P0/P1.
- **WATCH** — Proceed only with documented mitigations for open P1/P2 items; re-verify before closure.
- **NO-GO** — Stop due to safety, privacy, data integrity, or recovery risk (any open P0, or an
  unverified non-production target, or incomplete privacy review).

## Explicit out of scope

This Sprint 31 pass explicitly performed **no production action** and changed no runtime behavior:

- No production code change.
- No migration.
- No deployment.
- No production/VPS access.
- No real backup execution.
- No real restore execution.
- No destructive operation.
- No monitoring automation.
- No backup automation.
- No restore automation.
- No cron/scheduler/job/queue/notification change.
- No runtime behavior change.
- No route/controller/service/model/view/config/seeder change.
- No WhatsApp automation/send.

## Validation commands

```bash
php artisan test --filter=Sprint31BackupRestoreRehearsalExecutionRecoveryReadiness
vendor/bin/pint --test tests/Feature/Sprint31/Sprint31BackupRestoreRehearsalExecutionRecoveryReadinessTest.php
git diff --check
```

## PR readiness marker

GO CANDIDATE FOR PR REVIEW

## Next sprint recommendation

```text
Sprint 32 — Go-Live Readiness, Training, Handover & SLA
```

Sprint 32 should focus on training (operator + clinical staff), handover (knowledge transfer and
ownership), SOP operationalization (turning the rehearsal/safety checklists into routine
operational procedure), an SLA/support model (response times, escalation, on-call), and final
go-live readiness sign-off before production cutover.
