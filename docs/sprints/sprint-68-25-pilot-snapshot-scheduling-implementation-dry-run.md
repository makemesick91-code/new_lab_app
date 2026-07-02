# Sprint 68.25 — Pilot Snapshot Scheduling Implementation Dry Run

## Executive Summary

- Sprint implements dry-run scheduling foundation for `pilot:performance-snapshot`.
- App deploy is not required because command is already deployed (Sprint 68.22 GO tag on VPS).
- VPS ops is required after GO tag.
- systemd service/timer is preferred scheduler (per Sprint 68.24 plan).
- Timer should remain disabled unless explicitly approved.
- Manual systemd service run should produce one JSON snapshot.
- No cron/alert/dashboard/migration.
- Manual command remains fallback.

## Deploy Decision

| Item | Decision |
|---|---|
| App deploy needed | No |
| VPS ops needed | Yes |
| Reason | systemd dry-run scheduling artifacts are created on VPS |
| Migration needed | No |
| DB write needed | No |
| Timer enabled by default | No |
| Alert/dashboard | No |

## Implementation Scope

| Area | Decision |
|---|---|
| Wrapper script | `/usr/local/sbin/daengtisiams-pilot-snapshot` |
| systemd service | `/etc/systemd/system/daengtisiams-pilot-snapshot.service` |
| systemd timer | `/etc/systemd/system/daengtisiams-pilot-snapshot.timer` (disabled) |
| Manual service run | yes |
| Output path | `storage/app/monitoring/` |
| Retention | not automated yet |
| Alerts | not implemented |

## Safety Rules

- No app deploy unless code defect fixed.
- No migration.
- No destructive DB command.
- No DB write by scheduler except snapshot file under storage.
- No raw logs.
- No PII.
- No secrets.
- Do not enable timer without approval.
- Do not commit generated snapshot files.

## Planned VPS Ops Checklist

- [ ] Preflight app/tag/command.
- [ ] Verify timezone.
- [ ] Create monitoring directory.
- [ ] Create or update wrapper script with backup if existing.
- [ ] Create systemd service file with backup if existing.
- [ ] Create systemd timer file with backup if existing.
- [ ] `systemctl daemon-reload`.
- [ ] Do not enable timer.
- [ ] Run service manually once.
- [ ] Verify output JSON exists.
- [ ] Validate JSON.
- [ ] Verify service status.
- [ ] Verify timer disabled/inactive.
- [ ] Verify manual command still works.
- [ ] Rollback steps documented.

## systemd Design

### Service unit

```ini
# /etc/systemd/system/daengtisiams-pilot-snapshot.service
[Unit]
Description=DaengtisiaMS Pilot Performance Snapshot
Wants=network-online.target
After=network-online.target postgresql.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/asia-dental-lab-v2
ExecStart=/usr/local/sbin/daengtisiams-pilot-snapshot
Nice=5
```

**Design notes:**

- `Type=oneshot` — runs once per trigger; suitable for snapshot capture.
- Runs as `www-data` — same user as php-fpm/nginx; can write to `storage/app/monitoring/`.
- `ExecStart` delegates to wrapper script for timestamp expansion and JSON validation.
- No `--fail-on-watch` in dry run — job success = JSON file produced.
- No service restart of php-fpm/nginx/postgresql.

### Timer unit

```ini
# /etc/systemd/system/daengtisiams-pilot-snapshot.timer
[Unit]
Description=Weekly DaengtisiaMS Pilot Performance Snapshot

[Timer]
OnCalendar=Sun *-*-* 03:30:00
Persistent=true
Unit=daengtisiams-pilot-snapshot.service

[Install]
WantedBy=timers.target
```

**Design notes:**

- `OnCalendar=Sun *-*-* 03:30:00` — weekly Sunday 03:30 (confirm VPS timezone/WITA before enablement).
- `Persistent=true` — catches missed run after reboot.
- Timer **not enabled** in this sprint — dry-run only via manual `systemctl start daengtisiams-pilot-snapshot.service`.

## Wrapper Script Design

**Path:** `/usr/local/sbin/daengtisiams-pilot-snapshot`

```bash
#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/asia-dental-lab-v2"
OUT_DIR="${APP_DIR}/storage/app/monitoring"
TS="$(date +%Y%m%d-%H%M%S)"
OUT_FILE="${OUT_DIR}/pilot-snapshot-${TS}.json"

cd "$APP_DIR"

mkdir -p "$OUT_DIR"

php artisan pilot:performance-snapshot --json --output="storage/app/monitoring/pilot-snapshot-${TS}.json"

test -s "$OUT_FILE"

php -r 'json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo "JSON_OK\n";' "$OUT_FILE"

echo "SNAPSHOT_FILE=$OUT_FILE"
```

**Safety properties:**

- `set -euo pipefail` — fail fast on errors.
- Output path under `storage/app/monitoring/` only (command enforces this).
- `test -s` — ensures non-empty file before success.
- PHP JSON validation before exit 0.
- No `--fail-on-watch` — WATCH does not fail the job.
- No secrets, PII, or raw logs in output.
- Root-owned script (`chmod 755`, `chown root:root`); executed by `www-data` via systemd.

## Output and Retention

- JSON only in dry run.
- Retention cleanup deferred to future sprint.
- 90-day policy remains target (Sprint 68.24).
- Generated files must not be committed to git.
- Example filename: `storage/app/monitoring/pilot-snapshot-20260706-033001.json`

## Rollback Plan

If rollback is needed after dry-run:

```bash
systemctl disable --now daengtisiams-pilot-snapshot.timer
rm -f /etc/systemd/system/daengtisiams-pilot-snapshot.timer
rm -f /etc/systemd/system/daengtisiams-pilot-snapshot.service
rm -f /usr/local/sbin/daengtisiams-pilot-snapshot
systemctl daemon-reload
```

- Backups of pre-existing files: `*.pre_sprint_68_25_<timestamp>.bak` alongside originals.
- Manual command remains available: `php artisan pilot:performance-snapshot --json`.
- Generated snapshot JSON files in `storage/app/monitoring/` can be kept or pruned manually.

## Future Enablement Plan

1. Obtain stakeholder approval for weekly automated capture.
2. Confirm VPS timezone (target WITA / Asia/Makassar low-usage window).
3. `systemctl enable daengtisiams-pilot-snapshot.timer`
4. `systemctl start daengtisiams-pilot-snapshot.timer`
5. Verify `systemctl list-timers` shows next trigger.
6. Review first scheduled snapshot JSON in weekly evidence sprint.
7. Add retention cleanup (90-day prune) in later sprint.

## What Was Not Done

- No timer enablement unless approved.
- No cron.
- No alert.
- No dashboard.
- No DB table.
- No migration.
- No app deploy.

## Local Checks

| Check | Result |
|---|---|
| Branch | `feature/sprint-68-25-pilot-snapshot-scheduling-implementation-dry-run` |
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| git diff --check | pending at commit |
| graphify update | OK (2041 files, AST-only) |
| php artisan about | OK (local) |
| artisan list command | `pilot:performance-snapshot` present |
| Pest/Pint | not run — docs-only |
| Code change | none |

## Post-GO VPS Ops Evidence

Final VPS ops evidence is reported in the sprint closure chat final report. No post-GO doc commit is made in this sprint.

Expected evidence items:

- VPS preflight (host, tag, APP_ENV, command JSON_OK, timezone)
- Wrapper/service/timer paths and backup status
- Manual `systemctl start daengtisiams-pilot-snapshot.service` success
- Generated JSON file name, JSON_FILE_OK, safe status summary
- File ownership `www-data:www-data`
- Timer disabled/inactive
- App health post-ops (artisan, services, HTTP)

## Recommended Next Sprint

**Primary:**

Sprint 68.26 — Pilot Snapshot Scheduled Timer Enablement & First Scheduled Evidence

**Alternative:**

Sprint 68.26 — Pilot Snapshot Weekly Evidence Review From Dry-Run Output (if timer enablement approval is pending)

## Final Status

PENDING — awaiting PR merge, GO tag, and VPS ops dry-run verification.

Target:

`DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / VPS OPS DRY-RUN VERIFIED / NO APP DEPLOY`
