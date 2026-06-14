# Pilot Support Runbook — DaengtisiaMS / ADLMS

## Purpose

This runbook defines the first-level support procedure for the DaengtisiaMS VPS
pilot. It standardizes how an issue is triaged, what immediate actions are safe,
how to handle services, logs, and backups, and how to roll back to a known-good
state if required.

This document is operational only. It does not authorize schema changes, payment
logic changes, or untested redeployment.

## Environment

| Item | Value |
|---|---|
| VPS IP | `145.79.13.224` |
| App path | `/var/www/asia-dental-lab-v2` |
| Runtime branch baseline | `feature/sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary` |
| Functional deployed commit | `f87b3d5` |
| PHP-FPM service | `php8.3-fpm` |
| Web server | `nginx` |
| Database | PostgreSQL |
| Laravel environment | `pilot` |

## Severity Levels

| Level | Name | Description | Target Response |
|---|---|---|---|
| **S1** | Critical | App down, data loss risk, payment/RME integrity broken | Immediate |
| **S2** | High | Core module unusable (RME, Kasir, Owner Dashboard) but app reachable | Same day |
| **S3** | Medium | Partial/feature issue with workaround | Next operating day |
| **S4** | Low | Cosmetic/minor, no functional impact | Backlog |

## First Response Checklist

Run from `/var/www/asia-dental-lab-v2` (read-only inspection first).

```bash
# Confirm app/git state
git branch --show-current
git log --oneline -5

# Confirm services
sudo systemctl status php8.3-fpm --no-pager
sudo systemctl status nginx --no-pager
sudo systemctl status postgresql --no-pager
sudo nginx -t

# Confirm Laravel environment
php artisan about

# Inspect recent errors
tail -100 storage/logs/laravel.log
grep -iE "error|exception|critical" storage/logs/laravel.log | tail -50
```

## Restart Service SOP

Restart only the affected service. Validate nginx config before restart.

```bash
# PHP-FPM
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm --no-pager

# Nginx (validate first)
sudo nginx -t && sudo systemctl restart nginx
sudo systemctl status nginx --no-pager

# PostgreSQL (only if DB-related and approved)
sudo systemctl restart postgresql
sudo systemctl status postgresql --no-pager
```

## Laravel Log Handling SOP

```bash
# Inspect
tail -200 storage/logs/laravel.log
grep -iE "error|exception|critical|stack trace" storage/logs/laravel.log | tail -100

# After a fix is confirmed, archive (do NOT delete blindly)
cp storage/logs/laravel.log storage/logs/laravel.$(date +%Y%m%d-%H%M%S).log
: > storage/logs/laravel.log   # truncate only after archiving and approval
```

Never delete the `storage/logs` directory. Archive before truncating, and only
truncate with approval.

## Manual Backup SOP

Take backups before any change. Store under
`/var/backups/daengtisiams/<label>-<timestamp>`.

```bash
TS=$(date +%Y%m%d-%H%M%S)
DEST=/var/backups/daengtisiams/support-$TS
sudo mkdir -p "$DEST"

# 1. Database backup (PostgreSQL custom format)
pg_dump -Fc -h 127.0.0.1 -p 5432 -U dental_pilot asia_dental_lab_pilot \
  -f "$DEST/asia_dental_lab_pilot_$TS.dump"

# 2. Verify restore catalog (does NOT restore)
pg_restore -l "$DEST/asia_dental_lab_pilot_$TS.dump" \
  > "$DEST/asia_dental_lab_pilot_$TS.dump.list"

# 3. Checksum
sha256sum "$DEST/asia_dental_lab_pilot_$TS.dump" \
  > "$DEST/asia_dental_lab_pilot_$TS.dump.sha256"

# 4. Runtime file backup (.env, storage/app, public/storage, public/build)
tar -czf "$DEST/app_runtime_$TS.tar.gz" \
  .env storage/app public/storage public/build
sha256sum "$DEST/app_runtime_$TS.tar.gz" \
  > "$DEST/app_runtime_$TS.tar.gz.sha256"
tar -tzf "$DEST/app_runtime_$TS.tar.gz" > /dev/null && echo "runtime archive OK"
```

## Rollback SOP

Roll back code only. Take a full backup first (Manual Backup SOP). Never use
`migrate:fresh` or `db:wipe`. Do not restore the production DB without explicit
approval.

```bash
# 1. Backup first (see Manual Backup SOP)

# 2. Roll back to known-good baseline
git fetch --all
git checkout feature/sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary
# or checkout the known-good tag:
git checkout sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary

# 3. Reapply runtime config caches (no migrate:fresh)
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Restart services
sudo systemctl restart php8.3-fpm
sudo nginx -t && sudo systemctl restart nginx
```

| Rollback target | Value |
|---|---|
| Known-good branch | `feature/sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary` |
| Known-good tag | `sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary` |

## What NOT To Do During Pilot

- Do **not** run `migrate:fresh` on the VPS.
- Do **not** restore the production database without explicit approval.
- Do **not** delete the `storage` directory.
- Do **not** run `chmod -R 777`.
- Do **not** deploy untested code.
- Do **not** add scheduler/cron or WhatsApp/external integration without a separate sprint.

## Escalation Template

```
Issue ID            :
Date/Time           :
Reported by         :
Role                :
Branch              :
Module              :
URL/Page            :
Severity            : S1 / S2 / S3 / S4
Symptoms            :
Steps to Reproduce  :
Screenshot/Log      :
Immediate Action    :
Current Status      :
Next Action         :
Decision            : GO / WATCH / NO-GO
```
