# Backup, DR & Restore Rehearsal Runbook (ENT-15)

Durable governance: `docs/architecture/enterprise-documentation-runbook-governance.md`.
Verified by: `php artisan foundation:enterprise-documentation-check --strict`.

## Purpose

Take verified database backups with retention (ENT-12) and rehearse a restore in a
non-production scratch database so recovery is proven without ever touching
production. Covers the **backup_dr and restore_rehearsal** topics. Baseline
objectives: RTO 60 minutes, RPO 1440 minutes, retention 14 days.

## When to Use

- Before every deploy (the deploy script already takes a pre-deploy backup).
- On the scheduled backup cadence.
- When rehearsing disaster recovery / validating a backup is restorable.

## Prerequisites

- `ssh daengtisiams-vps` at `/var/www/asia-dental-lab-v2`.
- A distinct scratch database name for rehearsal (must differ from production).
- Sufficient disk under `storage/app/backups/`.

## Safe Commands

Backup + verify + prune (fail-fast):

```
bash scripts/backup-vps.sh
php artisan foundation:backup-dr-check
```

`scripts/backup-vps.sh` runs `pg_dump` → `foundation:backup-verify` → prunes dumps
older than the retention window while keeping a minimum floor.

Restore rehearsal (manual, non-production scratch DB only):

```
bash scripts/restore-rehearsal.sh
```

The rehearsal restores the latest verified backup into a **scratch** database
(guarded to differ from production), verifies the restored table count, drops only
the scratch database, and writes non-sensitive `restore-rehearsal.json` evidence.

## Forbidden Commands

Never run these against the pilot/production database:

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- `php artisan schema:drop`
- `php artisan migrate:reset`
- Any restore over the production database, any automatic production restore during
  deploy, and any restore rehearsal that targets the production database. Restore
  rehearsal is manual and non-production only; it is **never** auto-run by deploy.

## Evidence

- Backup filename + size and `foundation:backup-verify` result.
- `backup-dr-check.json` GO and the non-sensitive `restore-rehearsal.json`
  (path/size/table-count/RTO/RPO — never data rows).

## Rollback / Fallback

- Backups are additive; pruning keeps a minimum floor so history is never fully
  removed.
- Real production recovery (only on a genuine incident) uses the explicit
  `scripts/restore_postgres.sh <backup>` step with an approved change window — not
  the rehearsal script.

## Troubleshooting

- `foundation:backup-verify` fails → the dump is incomplete; do not prune, take a
  fresh backup.
- Rehearsal aborts "scratch DB equals production" → set a distinct scratch DB name;
  the guard is working as designed.

## Smoke Verification

- `php artisan foundation:backup-dr-check --strict` → GO.
- A recent verified backup exists under `storage/app/backups/`.
- Rehearsal evidence shows the scratch DB was dropped and production untouched.

## Security / PII Notes

- Backup files and evidence are never committed to git and never contain rendered
  KTP/NIK; evidence carries metadata only (paths, sizes, counts).
- Credentials come from the server environment and are never printed.

## Owner / Reviewer

- Owner: Database / DevOps lead. Reviewer: Enterprise Foundation governance owner.

## Review Cadence

- Review each backup/DR sprint (ENT-12) and at least quarterly; rehearse restore at
  least once per quarter.
