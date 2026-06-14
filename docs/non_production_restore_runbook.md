# Non-Production Restore Runbook

## Purpose

This runbook defines the safe procedure for a future PostgreSQL backup restore rehearsal into a non-production database.

## Important Warning

Do not execute this runbook during Sprint 26.3.

Sprint 26.3 is documentation-only. The commands below are templates for a future approved restore rehearsal phase.

## Absolute Safety Rules

- Never restore into production database.
- Never run destructive commands against production.
- Never use production database name (`asia_dental_lab_pilot`) as restore target.
- Never modify `/var/www/asia-dental-lab-v2` during restore rehearsal.
- Never deploy to VPS as part of restore rehearsal documentation.
- Stop if host, database, user, or target environment is unclear.

## Recommended Non-Production Target

| Field | Value |
| --- | --- |
| Database name | `asia_dental_lab_restore_rehearsal` |
| Environment | Local or isolated staging |
| Production DB name | `asia_dental_lab_pilot` — must not be used |
| Production VPS IP | `145.79.13.224` reference only |
| Production app path | `/var/www/asia-dental-lab-v2` reference only |
| Execution status | Future phase only |

## Pre-Execution Checklist for Future Phase

| # | Check | Required | Status | Notes |
|---|---|---|---|---|
| 1 | Approval from owner/IT | Yes |  |  |
| 2 | Confirm non-production host | Yes |  |  |
| 3 | Confirm target database name | Yes |  |  |
| 4 | Confirm production DB will not be used | Yes |  |  |
| 5 | Confirm backup file path | Yes |  |  |
| 6 | Confirm backup file timestamp | Yes |  |  |
| 7 | Confirm backup file size | Yes |  |  |
| 8 | Prepare evidence template | Yes |  |  |

## Command Templates for Future Approved Rehearsal

Do not run these commands in Sprint 26.3. These are templates only.

### 1. Confirm environment

```bash
hostname
pwd
whoami
date
```

### 2. Confirm backup file

```bash
ls -lah /path/to/backup/file.dump
file /path/to/backup/file.dump
# Optional integrity check if a .sha256 sidecar exists (Sprint 25.7 baseline format)
sha256sum -c /path/to/backup/file.dump.sha256
# List archive contents without restoring (non-destructive)
pg_restore -l /path/to/backup/file.dump
```

### 3. Create non-production database target

Only in a future approved rehearsal phase:

```bash
createdb asia_dental_lab_restore_rehearsal
```

### 4. Restore backup into non-production target

Only in a future approved rehearsal phase:

```bash
pg_restore \
  --dbname=asia_dental_lab_restore_rehearsal \
  --verbose \
  /path/to/backup/file.dump
```

Alternative if backup is plain SQL:

```bash
psql \
  --dbname=asia_dental_lab_restore_rehearsal \
  --file=/path/to/backup/file.sql
```

### 5. Basic sanity checks

Only in a future approved rehearsal phase:

```bash
psql --dbname=asia_dental_lab_restore_rehearsal -c "\dt"
psql --dbname=asia_dental_lab_restore_rehearsal -c "select count(*) from information_schema.tables where table_schema = 'public';"
```

### 6. Optional cleanup after approved rehearsal

Only if approved and only for non-production database:

```bash
dropdb asia_dental_lab_restore_rehearsal
```

## Laravel Non-Production Sanity Notes

If the restored database is later connected to a local/staging Laravel environment:

- Use separate `.env.restore` or isolated environment.
- Do not overwrite production `.env`.
- Do not point production app to restore database.
- Do not run migrations against production.
- Do not run destructive artisan commands.

Possible future sanity commands in isolated environment only:

```bash
php artisan about
php artisan route:list
php artisan migrate:status
```

Do not run full test suite unless explicitly scoped in a future phase.

## Evidence to Capture

- Date and time
- Reviewer/operator
- Host/environment
- Backup file name
- Backup timestamp
- Backup size
- Target database name
- Restore command reviewed
- Restore result
- Sanity check result
- Issues found
- Final result

Record evidence in `docs/backup_restore_rehearsal_evidence_template.md`.

## Result Classification

| Result | Meaning |
| --- | --- |
| PASS | Restore completes and sanity checks pass |
| WATCH | Restore completes but minor issue needs follow-up |
| FAIL | Restore fails or data appears incomplete |
| BLOCKED | Restore cannot start safely |

## Escalation

Escalate if:

- Backup cannot be found.
- Backup cannot be read.
- Restore command points to production.
- Non-production target cannot be verified.
- Restore fails.
- Sanity checks fail.
- Data appears incomplete.

Escalate using the escalation template in `docs/pilot_support_runbook.md`. Treat any production data loss risk as S1 (Critical).

## Final Notes

This runbook is a planning artifact for a future approved restore rehearsal. It does not authorize production restore or production database modification.
