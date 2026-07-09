# ROLL-5 — Backup & Restore Drill Runbook

Part of **ROLL-5-1 — Five Branch Controlled Production Rollout Readiness**.

> ⚠️ **Production DB must NEVER be overwritten by a restore drill.** A restore
> drill is performed **only** against a staging/test database. `migrate:fresh`
> and `db:wipe` are forbidden on any environment.

The ROLL-5 readiness command detects restore-drill evidence:

```bash
php artisan rollout:five-branch-readiness --json | jq '.signals[] | select(.key=="restore_drill_evidence")'
```

If no evidence is found, the `restore_drill_evidence` signal is **WATCH** with a
clear action hint — it does not FAIL the rollout, but it must be resolved before
declaring GO for a stage.

---

## 1. Backup file location

- Automated deploy backups: `storage/app/backups/deploy/*.sql` (ENT-12, written by `scripts/backup-vps.sh` / `scripts/deploy-vps-runner.sh`).
- Manual backup: `bash scripts/backup-vps.sh` (fail-fast, `pg_dump`, retention prune).

## 2. Restore target — staging/test ONLY

- The restore target database **must differ** from the production database name.
- Reuse the ENT-12 rehearsal harness `scripts/restore-rehearsal.sh` which:
  - selects the latest verified backup,
  - restores into a **scratch** database (`REHEARSAL_DB`, aborts if equal to production),
  - verifies the restored table count,
  - drops **only** the scratch database,
  - writes non-sensitive `restore-rehearsal.json` evidence.
- The production restore helper `scripts/restore_postgres.sh <backup>` is a
  **separate, explicit, human-run** step — never invoked by a drill.

## 3. Production DB never overwritten

- No drill step targets the production database.
- No drill step calls `scripts/restore_postgres.sh`.
- No drill step runs `migrate:fresh`, `db:wipe`, `schema:drop`, or `migrate:reset`.

## 4. Verification checklist (on the staging/test restore)

- [ ] App boots against the restored DB (`php artisan about` / `php artisan migrate:status` consistent).
- [ ] Migrations consistent — no pending destructive drift.
- [ ] A sample authenticated route renders (e.g. `/dashboard` → 200 after login) — **no patient PII printed to the drill log**.
- [ ] Health endpoints OK: `/health/live` 200, `/health/ready` 200.
- [ ] No KTP/NIK/raw patient notes exposed in any drill output or evidence file.

## 5. RPO / RTO draft (5-branch controlled rollout)

| Objective | Draft target (5 branches) | Notes |
|---|---|---|
| **RPO** (Recovery Point Objective) | ≤ 24 h | Daily automated backup before each deploy; increase frequency per branch load. |
| **RTO** (Recovery Time Objective) | ≤ 4 h | Single VPS pilot restore from latest `pg_dump`; revisit for HA/national scale. |

> These are **controlled-rollout drafts**, not an HA/DR certification. National
> scale RPO/RTO requires a separate scale-validation sprint.

## 6. Evidence template

Record each drill in `storage/app/rollout/restore-drill.json` (or attach to the
sprint evidence). ROLL-5 detects any of the configured evidence paths.

```json
{
  "date": "YYYY-MM-DDTHH:MM:SS+07:00",
  "backup_file": "storage/app/backups/deploy/pre_auto_deploy_YYYYMMDD-HHMMSS.sql",
  "restore_target": "daengtisiams_rehearsal (scratch, non-production)",
  "restore_command": "bash scripts/restore-rehearsal.sh",
  "verification": {
    "app_boots": true,
    "migrations_consistent": true,
    "sample_route_ok": true,
    "health_ready_ok": true,
    "no_pii_exposed": true
  },
  "rpo_target_hours": 24,
  "rto_target_hours": 4,
  "operator": "<name>",
  "decision": "GO"
}
```

After a successful drill, re-run:

```bash
php artisan rollout:five-branch-readiness --stage=1
```

and confirm `restore_drill_evidence` is now **GO**.
