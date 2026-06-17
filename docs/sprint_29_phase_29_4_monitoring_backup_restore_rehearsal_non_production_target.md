# Sprint 29 Phase 29.4 — Monitoring Backup Restore Rehearsal on Non-Production Target

## 1. Status

- **Mode:** monitoring backup restore rehearsal planning/SOP only
- **Target:** non-production target only
- **Production server:** not touched
- **Deployment:** no deployment
- **Migration:** no migration
- **Production code change:** no production code change
- **Real backup execution:** no real backup executed
- **Real restore execution:** no real restore executed
- **Backup automation:** no backup automation implemented
- **Monitoring automation:** no monitoring automation implemented
- **Runtime behavior change:** no runtime behavior change
- **Destructive data operation:** no destructive data operation
- **Baseline:** Sprint 29.3 GO at `06c5d81`

## 2. Purpose

- Define a safe monitoring, backup, and restore rehearsal SOP for a non-production target before pilot stabilization gets more technical.
- Protect production data and VPS stability.
- Ensure restore rehearsal is planned without touching production.
- Define evidence required before a future real rehearsal.
- Define operator checklist for health checks, backup inventory, restore simulation, and rollback readiness.
- Keep this phase docs-only and reviewable.
- Prepare future rehearsal execution in a separate controlled phase.

## 3. Non-goals

- No production code change.
- No real backup execution.
- No real restore execution.
- No production/VPS access.
- No deployment.
- No migration/schema change.
- No database mutation.
- No destructive data operation.
- No backup script implementation.
- No monitoring script implementation.
- No cron/scheduler/job/queue/notification implementation.
- No route/controller/service/model/view/config change.
- No RME/payment/receivable/cashier/WhatsApp business rule change.

## 4. Background

- Sprint 28.4 documented Monitoring, Backup, and Restore Rehearsal Planning.
- Sprint 28.6 closed Sprint 28 with a pilot readiness posture.
- Sprint 29.0 prioritized the stabilization backlog.
- Sprint 29.1 documented RME P0/P1 regression planning.
- Sprint 29.2 documented cashier/payment/receivable high-risk planning.
- Sprint 29.3 documented the WhatsApp manual pilot SOP.
- Before technical stabilization execution, the pilot must have a safe monitoring/backup/restore rehearsal SOP.
- Rehearsal must happen on a non-production target first.

## 5. Non-production target definition

- Non-production target must not be the live pilot/production VPS.
- Non-production target may be local staging, a clone VM, a disposable VPS, or an isolated rehearsal environment.
- Production database must not be overwritten.
- Production storage/uploads must not be overwritten.
- Production `.env` must not be copied into an unsafe context without redaction.
- Any copied data must follow privacy and minimum-necessary rules.
- Restore rehearsal must be clearly labeled as rehearsal.
- Rehearsal evidence must not expose sensitive patient/clinical/payment data.

## 6. Monitoring readiness SOP

Checklist (observe only — do not change anything):

- Application health page or login smoke target.
- PHP/Laravel runtime status.
- Queue status if applicable, but do not change it.
- Scheduler/cron observation if applicable, but do not change it.
- Web server status observation.
- Database connectivity observation.
- Disk usage observation.
- Storage permission observation.
- Laravel log error observation.
- Recent failed job/log observation if applicable.
- SSL/domain observation if applicable.
- Backup freshness observation.
- Restore rehearsal readiness observation.
- Evidence capture with sensitive data redacted.

## 7. Backup inventory SOP

Checklist:

- Database backup inventory.
- Runtime/application file backup inventory.
- Storage/uploads backup inventory.
- `.env` handling policy.
- Logs handling policy.
- Backup file naming convention.
- Backup timestamp and timezone.
- Backup size/checksum recording.
- Retention policy observation.
- Offsite copy observation.
- Access control observation.
- Encryption/password storage note.
- Privacy redaction rule.
- Do not store secrets in docs or sprint history.

## 8. Restore rehearsal SOP on non-production target

- Confirm target is non-production.
- Confirm target can be destroyed/recreated safely.
- Confirm source backup is allowed for rehearsal.
- Confirm secrets are handled safely.
- Confirm database restore destination is not production.
- Confirm storage restore destination is not production.
- Confirm app boot smoke target.
- Confirm login smoke target if safe.
- Confirm key module smoke targets only as a future/manual checklist, not executed in this phase.
- Confirm evidence capture.
- Confirm failure handling.
- Confirm cleanup plan.
- Confirm post-rehearsal summary.
- **No restore is executed in Sprint 29.4.**

## 9. Data privacy and safety rules

- Do not expose No. KTP.
- Do not expose unnecessary clinical notes.
- Do not expose payment details in screenshots unless redacted.
- Do not paste .env secrets.
- Do not paste database credentials.
- Do not paste patient-identifiable data into public PR.
- Use anonymized/safe references.
- Store rehearsal evidence in a controlled internal location only.
- Redact WA numbers except last 4 digits if needed.
- Redact invoice/payment identifiers if shared publicly.
- Follow minimum-necessary evidence collection.

## 10. Rehearsal evidence template

| Date/time | Environment | Operator | Source backup reference | Target reference | Database backup present? | Storage backup present? | App boot checked? | Login smoke checked? | Module smoke checked? | Errors observed | Evidence location | Privacy redaction confirmed? | Cleanup confirmed? | Result | Follow-up owner |
| --------- | ----------- | -------- | ----------------------- | ---------------- | ------------------------ | ----------------------- | ----------------- | -------------------- | --------------------- | --------------- | ----------------- | ---------------------------- | ------------------ | ------ | --------------- |
|           |             |          |                         |                  |                          |                         |                   |                      |                       |                 |                   |                              |                    |        |                 |

## 11. P0/P1 escalation matrix

| Priority | Risk type | Trigger | Immediate action | Evidence required | Future phase candidate |
| -------- | --------- | ------- | ---------------- | ----------------- | ---------------------- |
| P0 | Production backup missing | No recent production backup found | Stop rehearsal; escalate to owner/IT | Backup inventory listing (redacted) | Backup policy phase |
| P0 | Production restore target confusion | Restore target identity unclear | Stop; re-confirm non-production target | Target identity note | Target isolation phase |
| P0 | Production data overwrite risk | Restore could hit production | Abort immediately; lock action | Command/plan screenshot (redacted) | Restore safeguard phase |
| P0 | `.env`/secret leakage risk | Secrets exposed in evidence/PR | Purge exposure; rotate secrets | Redacted incident note | Secret handling phase |
| P0 | Patient/clinical/payment data leakage risk | Sensitive data exposed | Remove exposure; notify owner | Redacted incident note | Privacy hardening phase |
| P0 | Database backup unreadable | Backup file cannot be opened | Stop; report corrupted backup | Checksum/error note | Backup integrity phase |
| P0 | Storage backup missing | Uploads/storage not in backup set | Stop; escalate gap | Storage inventory note | Backup coverage phase |
| P0 | Restore rehearsal cannot boot app | App fails to boot after restore | Stop; capture boot error | Boot log (redacted) | Restore reliability phase |
| P1 | Backup older than expected | Backup timestamp stale | Note staleness; schedule review | Timestamp note | Backup freshness phase |
| P1 | Checksum/size not recorded | Integrity metadata missing | Record checksum/size next run | Inventory note | Backup integrity phase |
| P1 | Log errors after restore | Non-fatal errors in logs | Record errors; triage later | Redacted log excerpt | Stabilization backlog |
| P1 | Storage permission mismatch | Permission symptoms after restore | Note mismatch; plan fix | Permission note | Permission hardening phase |
| NEEDS CONFIRMATION | Unclear evidence or environment identity | Environment/evidence ambiguous | Pause; confirm before proceeding | Clarification note | Confirmation follow-up |

## 12. Pilot daily monitoring checklist

- Check app reachability.
- Check login smoke.
- Check Laravel log high-severity errors.
- Check disk usage.
- Check database connectivity.
- Check backup freshness.
- Check storage permission symptoms.
- Check failed jobs/queues if applicable.
- Check SSL/domain if applicable.
- Check last known GO tag/commit deployed if applicable.
- Record incident notes.
- Escalate P0/P1 issues.
- Do not modify production during the monitoring checklist.

## 13. Future implementation/rehearsal sequencing

Planning-only — no execution in Sprint 29.4:

- **Phase A:** confirm non-production target.
- **Phase B:** collect backup inventory evidence.
- **Phase C:** perform non-production restore rehearsal in a future separate phase.
- **Phase D:** app boot and login smoke in non-production.
- **Phase E:** limited module smoke in non-production.
- **Phase F:** document restore duration, errors, and gaps.
- **Phase G:** refine backup/restore SOP.
- **Phase H:** future GO/NO-GO for production backup policy.
- **No execution in Sprint 29.4.**

## 14. Out-of-scope implementation list

- No controller changes.
- No model changes.
- No service changes.
- No repository changes.
- No route changes.
- No Blade/view changes.
- No migration changes.
- No seeder changes.
- No config/env changes.
- No queue/job/notification changes.
- No cron/scheduler changes.
- No backup script changes.
- No restore script changes.
- No monitoring agent changes.
- No VPS/deployment changes.
- No RME/payment/receivable/cashier/WhatsApp code changes.

## 15. Risk and mitigation

- Accidentally touching production.
- Wrong restore target.
- Backup file missing.
- Backup file corrupted.
- Storage backup incomplete.
- `.env` or secret leakage.
- Sensitive patient/clinical/payment data leakage.
- Evidence stored in an unsafe place.
- Rehearsal target not isolated.
- App cannot boot after restore.
- Permissions mismatch.
- Scope creep into implementation.
- GO tag created on wrong commit.

Mitigation: keep the phase docs-only, confirm non-production target identity before any future execution, redact all evidence, record checksum/size, and require explicit approval before a real rehearsal phase.

## 16. GO/NO-GO decision

**GO if:**

- Rehearsal SOP document is complete.
- Sprint history is updated.
- Focused test passes.
- No production code changed.
- No migration.
- No deployment.
- No production/VPS access.
- No real backup.
- No real restore.
- No destructive operation.
- No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change.
- No runtime behavior change.
- Non-production target rules, monitoring checklist, backup inventory, restore rehearsal SOP, privacy rules, evidence template, escalation matrix, and future sequencing are documented.

**NO-GO if:**

- Any production code is changed.
- Any migration/deploy/destructive command is introduced.
- Any production/VPS action is performed.
- Any real backup/restore is executed.
- Any monitoring/backup/restore automation is implemented.
- Any runtime behavior changes.
- Any route/controller/service/model/view/config/seeder changes.
- Any privacy rule/evidence template is missing.
- Sprint history/test is missing.

## 17. Safety confirmation

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
- No RME/payment/receivable/cashier/WhatsApp business rule change.

## 18. Final decision

Sprint 29 Phase 29.4 posture: GO CANDIDATE FOR PR REVIEW

GO CANDIDATE FOR PR REVIEW

## 19. Validation plan

- `php artisan test --filter=Sprint29Phase294MonitoringBackupRestoreRehearsalNonProductionTarget`
- `vendor/bin/pint --test tests/Feature/Sprint29/Sprint29Phase294MonitoringBackupRestoreRehearsalNonProductionTargetTest.php`
- `git diff --check`
