# ROLL-5 — Backup & Restore Drill Runbook

Part of **ROLL-5-1 — Five Branch Controlled Production Rollout Readiness** and
**ROLL-5-1A — Staging Restore Drill Evidence & Stage-1 GO Clearance**.

> ⚠️ **Production DB must NEVER be overwritten by a restore drill.** A restore
> drill is performed **only** against a staging/test/disposable database.
> `migrate:fresh`, `db:wipe`, `schema:drop`, and `migrate:reset` are forbidden on
> any environment. `production_overwrite` in the evidence MUST be exactly `false`.

The ROLL-5 readiness command detects and **validates** restore-drill evidence:

```bash
php artisan rollout:restore-drill-evidence            # validate evidence (GO/WATCH/FAIL)
php artisan rollout:restore-drill-evidence --json
php artisan rollout:restore-drill-evidence --strict   # exit non-zero on FAIL
php artisan rollout:five-branch-readiness --json | jq '.signals[] | select(.key=="restore_drill_evidence")'
```

If no evidence is found, the `restore_drill_evidence` signal is **WATCH** (never a
fake GO). Missing/stale/incomplete = WATCH; unsafe (production overwrite, failed
restore, invalid schema, leaked secret/PII) = **FAIL**. It must be **GO** before
declaring Stage-1 GO.

---

## 1. Backup file location

- Automated deploy backups: `storage/app/backups/deploy/*.sql` (ENT-12, written by `scripts/backup-vps.sh` / `scripts/deploy-vps-runner.sh`).
- Manual backup: `bash scripts/backup-vps.sh` (fail-fast, `pg_dump`, retention prune).

## 2. Restore target — staging / test / disposable ONLY

Two supported safe options. Prefer **Option 2** (disposable DB) when a staging
host with existing DB credentials is available and production is untouched.

**Option 1 — dedicated staging VPS/database:** restore the latest production
backup into the staging database only, verify app boot + health/read-only routes
+ migrations, and record the ROLL-5-1A evidence JSON.

**Option 2 — disposable local/staging PostgreSQL DB (preferred):** run the
fail-fast drill helper — it creates a disposable DB named
`<db>_restore_drill_YYYYMMDD-HHMMSS`, restores the latest verified backup there,
runs read-only verification (table count + bounded COUNTs — **no patient rows**),
drops **only** the disposable DB, and writes the canonical evidence:

```bash
bash scripts/rollout-restore-drill.sh                 # uses latest deploy backup
php artisan rollout:restore-drill-evidence --strict   # validate the written evidence
```

**Option 3 — no safe restore target available:** do **not** fake GO. Leave the
`restore_drill_evidence` signal at WATCH and complete the action checklist below
when a staging/disposable target becomes available.

- The ENT-12 rehearsal harness `scripts/restore-rehearsal.sh` (DR rehearsal,
  different `restore-rehearsal.json` schema) remains available for ENT-12 DR
  readiness — it is **not** ROLL-5-1A evidence and is not parsed as such.
- The production restore helper `scripts/restore_postgres.sh <backup>` is a
  **separate, explicit, human-run** step — never invoked by a drill.

## 3. Required safety checks before ANY restore attempt

1. Print current production DB name (`echo "$DB_DATABASE"`).
2. Print target restore DB name.
3. Assert target DB name **is not equal** to the production DB name → else STOP.
4. Assert target name contains `restore_drill`, `staging`, or `test` → else STOP.
5. Assert the production host/DB is **not** overwritten (drill restores into the
   disposable target only).
6. Assert the backup file exists and is non-zero.
7. Restore command summary must **hide the password** (use `PGPASSWORD` env, never
   echo it into logs/evidence).
8. Use `createdb`/`dropdb` for the disposable target **only**, never production.
9. If any check fails → **STOP** (the drill script aborts with `set -euo pipefail`).

## 4. Production DB never overwritten

- No drill step targets the production database.
- No drill step calls `scripts/restore_postgres.sh`.
- No drill step runs `migrate:fresh`, `db:wipe`, `schema:drop`, or `migrate:reset`.

## 5. Verification checklist (on the staging/disposable restore)

- [ ] DB connectivity to the restored disposable DB.
- [ ] Migration table present / migrations consistent — no pending destructive drift.
- [ ] App/artisan boots (`php artisan --version`) — **no patient PII printed**.
- [ ] Health endpoints resolve: `/health/live` 200, `/health/ready` 200.
- [ ] Read-only sample counts only (branches / users / patients count — **no names, no KTP/NIK**).
- [ ] No KTP/NIK/raw patient notes/secrets exposed in any drill output or evidence file.

## 6. RPO / RTO draft (5-branch controlled rollout)

| Objective | Draft target (5 branches) | Notes |
|---|---|---|
| **RPO** (Recovery Point Objective) | ≤ 24 h | Daily automated backup before each deploy; increase frequency per branch load. |
| **RTO** (Recovery Time Objective) | ≤ 4 h | Single VPS pilot restore from latest `pg_dump`; revisit for HA/national scale. |

> These are **controlled-rollout drafts**, not an HA/DR certification. National
> scale RPO/RTO requires a separate scale-validation sprint.

## 7. Canonical evidence (ROLL-5-1A)

Evidence is written to `storage/app/readiness/restore-drills/latest.json`
(schema in `docs/evidence/rollout/restore-drill-template.md`). Create a blank
non-GO template with `php artisan rollout:restore-drill-evidence --create-template`.

```json
{
  "schema_version": 1,
  "drill_id": "roll-5-1a-YYYYMMDD-HHMMSS",
  "environment": "staging",
  "source_backup_path": "storage/app/backups/deploy/pre_auto_deploy_YYYYMMDD-HHMMSS.sql",
  "source_backup_size_bytes": 0,
  "restore_target": "daengtisiams_restore_drill_YYYYMMDD-HHMMSS",
  "production_overwrite": false,
  "started_at": "YYYY-MM-DDTHH:MM:SSZ",
  "completed_at": "YYYY-MM-DDTHH:MM:SSZ",
  "duration_seconds": 0,
  "operator": "ops",
  "commands_summary": ["sanitized command summary only — no secrets"],
  "verification": {
    "db_connectivity": "GO",
    "migration_consistency": "GO",
    "app_boot": "GO",
    "health_routes": "GO",
    "sample_readonly_queries": "GO",
    "pii_redaction_confirmed": true
  },
  "decision": "GO",
  "notes": ["no secrets, no raw PII, disposable DB dropped after evidence captured"]
}
```

After a successful drill, re-run and confirm Stage-1 clearance:

```bash
php artisan rollout:restore-drill-evidence --strict
php artisan rollout:five-branch-readiness --stage=1
```

and confirm `restore_drill_evidence` is now **GO** and Stage-1 clears (independent
of the Stage-3 five-branch count, which stays WATCH until 5 branches are RME-enabled).
