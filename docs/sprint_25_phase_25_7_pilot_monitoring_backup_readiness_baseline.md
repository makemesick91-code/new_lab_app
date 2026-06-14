# Sprint 25 Phase 25.7 — Pilot Monitoring + Backup Readiness Baseline

## Goal

Establish a VPS pilot monitoring and backup readiness baseline for DaengtisiaMS.

This phase does not add application features and does not change production logic.

## Baseline

- Previous phase commit: `f4a6bb2`
- Previous phase tag: `sprint-25-phase-25-6-vps-owner-dashboard-branch-receivable-summary-smoke`
- Local branch: `feature/sprint-25-phase-25-7-pilot-monitoring-backup-readiness-baseline`
- VPS app path: `/var/www/asia-dental-lab-v2`
- VPS IP: `145.79.13.224`

## VPS Git Baseline

| Check | Result |
|---|---|
| VPS branch | `feature/sprint-25-phase-25-5-owner-dashboard-branch-receivable-summary` |
| VPS HEAD | `f87b3d5` |
| VPS deployed feature | Owner Dashboard Branch Receivable Summary Table |
| Notes | Sprint 25.6 was docs-only smoke documentation, so VPS remaining on Sprint 25.5 functional branch is expected |

## System Health Baseline

| Check | Result |
|---|---|
| Hostname | `srv1730088` |
| Uptime | 10 days, 5 hours |
| Load average | `0.00, 0.00, 0.00` |
| Memory | 7.8Gi total, 7.1Gi available |
| Swap | 0B |
| Disk `/` | 96G total, 4.1G used, 92G available, 5% used |

## Service Health

| Service | Result |
|---|---|
| `php8.3-fpm` | active |
| `nginx` | active |
| `postgresql` | active |
| `nginx -t` | PASS |
| PHP CLI | 8.3.6 |
| Laravel | 12.61.0 |
| Environment | `pilot` |
| Debug mode | OFF |
| Config cache | CACHED |
| Routes cache | CACHED |
| Views cache | CACHED |
| public/storage | LINKED |

## Route / Cache Quick Check

| Check | Result |
|---|---|
| `/dashboard` route | PASS |
| `rme.cashier.receivables` route | PASS |
| RME receivables export route | PASS |
| RME receivable follow-up routes | PASS |

## Laravel Log Baseline

| Check | Result |
|---|---|
| `storage/logs/laravel.log` size | 0 bytes |
| `tail -100 storage/logs/laravel.log` | CLEAN |
| Error scan | CLEAN |

## Database Backup Readiness

| Check | Result |
|---|---|
| DB connection | `pgsql` |
| DB host | `127.0.0.1` |
| DB port | `5432` |
| DB name | `asia_dental_lab_pilot` |
| DB user | `dental_pilot` |
| Backup directory | `/var/backups/daengtisiams/sprint-25-7-20260614-044414` |
| Backup format | PostgreSQL custom format |
| Dump file | `asia_dental_lab_pilot_sprint_25_7_20260614-044414.dump` |
| Dump size | 404K / 413147 bytes |
| Restore list file | `asia_dental_lab_pilot_sprint_25_7_20260614-044414.dump.list` |
| Restore list size | 80K / 81040 bytes |
| SHA256 file | `asia_dental_lab_pilot_sprint_25_7_20260614-044414.dump.sha256` |
| `pg_restore -l` verification | PASS |

## Runtime File Backup Readiness

Included runtime paths:

- `.env`
- `storage/app`
- `public/storage`
- `public/build`

| Check | Result |
|---|---|
| Runtime archive | `app_runtime_sprint_25_7_20260614-044557.tar.gz` |
| Runtime archive size | 801K / 820081 bytes |
| Runtime archive SHA256 | `app_runtime_sprint_25_7_20260614-044557.tar.gz.sha256` |
| `tar -tzf` verification | PASS |
| Backup inventory | `backup_inventory.txt` |
| Backup inventory actual size | 676 bytes |
| Backup directory total size | 1.3M |

## Backup Directory Inventory

Backup directory:

`/var/backups/daengtisiams/sprint-25-7-20260614-044414`

Files:

| File | Size |
|---|---:|
| `app_runtime_sprint_25_7_20260614-044557.tar.gz` | 820081 bytes |
| `app_runtime_sprint_25_7_20260614-044557.tar.gz.sha256` | 167 bytes |
| `asia_dental_lab_pilot_sprint_25_7_20260614-044414.dump` | 413147 bytes |
| `asia_dental_lab_pilot_sprint_25_7_20260614-044414.dump.list` | 81040 bytes |
| `asia_dental_lab_pilot_sprint_25_7_20260614-044414.dump.sha256` | 175 bytes |
| `backup_inventory.txt` | 676 bytes |

## Decision

Decision: GO.

DaengtisiaMS pilot VPS is ready for basic operational monitoring and backup baseline.

## Constraints

- No production code changed.
- No migration run.
- No restore into production database.
- No payment logic changed.
- No follow-up mutation logic changed.
- No scheduler/cron added.
- No WhatsApp/external integration added.
- No full test suite run on VPS.
- Backup files remain on VPS and are not committed to Git.
