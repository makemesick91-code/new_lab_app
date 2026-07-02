# Sprint 68.26 — Pilot Snapshot Scheduled Timer Enablement & First Scheduled Evidence

## Executive Summary

- Sprint enables the pilot snapshot systemd timer after Sprint 68.25 dry-run success.
- App deploy is not required because command is already deployed (Sprint 68.22 GO tag on VPS).
- VPS ops is required after GO tag.
- Timer schedule uses WITA business intent mapped to current VPS UTC timezone.
- Sunday 03:30 WITA equals Saturday 19:30 UTC.
- Timer should be enabled and active.
- First manual service evidence should produce valid JSON snapshot.
- No migration, no DB write except snapshot file, no alert/dashboard.

## Deploy Decision

| Item | Decision |
|---|---|
| App deploy needed | No |
| VPS ops needed | Yes |
| Reason | Enable existing systemd timer and collect first evidence |
| Migration needed | No |
| DB write needed | No |
| Timer enabled | Yes, after GO tag |
| Alert/dashboard | No |

## Timezone Decision

| Item | Value |
|---|---|
| Business schedule | Sunday 03:30 WITA |
| VPS timezone | UTC / Etc/UTC |
| systemd OnCalendar | Saturday 19:30 UTC |
| Timer line | `OnCalendar=Sat *-*-* 19:30:00` |
| Note | If VPS timezone changes to Asia/Makassar, review timer and change back to `OnCalendar=Sun *-*-* 03:30:00` local |

**Rationale:** Sprint 68.25 confirmed VPS runs `Etc/UTC`. Operational intent is low-traffic Sunday morning WITA (03:30). WITA is UTC+8, so 03:30 WITA Sunday = 19:30 UTC Saturday. Timer must be reviewed if VPS timezone is ever changed to `Asia/Makassar`.

## Background

| Sprint | Result |
|---|---|
| 68.22 | Stack trace noise reduction deployed; command stable on VPS |
| 68.23 | Weekly evidence OK; no deploy |
| 68.24 | Scheduling plan; systemd timer recommended |
| 68.25 | Dry-run VPS ops; wrapper/service/timer created; timer disabled; manual service run OK (`pilot-snapshot-20260702-094300.json`, overall OK) |

**Sprint 68.25 baseline:** PR #133, merge `b86bea8`, GO tag `sprint-68-25-pilot-snapshot-scheduling-implementation-dry-run-go`. VPS app still at `sprint-68-22-pilot-snapshot-stack-trace-noise-reduction-go` / `b0d4082`. Wrapper output fix: use `--output="pilot-snapshot-${TS}.json"` (filename only); command prepends `storage/app/monitoring/`.

## Implementation Scope

| Area | Decision |
|---|---|
| Wrapper script | existing from Sprint 68.25 (`/usr/local/sbin/daengtisiams-pilot-snapshot`) |
| systemd service | existing from Sprint 68.25 |
| systemd timer | update/verify OnCalendar, then enable |
| Manual service run | yes (first evidence) |
| Output path | `storage/app/monitoring/` |
| Retention | not automated yet |
| Alerts | not implemented |
| App deploy | no |

## Safety Rules

- No app deploy unless code defect fixed.
- No migration.
- No destructive DB command.
- No DB write by command except snapshot file under storage.
- No raw logs.
- No PII.
- No secrets.
- Do not commit generated snapshot files.
- Keep rollback commands documented.

## Planned VPS Ops Checklist

- [ ] Preflight app/tag/command.
- [ ] Verify timezone (`timedatectl`; expect UTC).
- [ ] Verify wrapper output pattern (`--output="pilot-snapshot-${TS}.json"`).
- [ ] Verify service/timer files exist.
- [ ] Backup timer before editing (`*.pre_sprint_68_26_<timestamp>.bak`).
- [ ] Set `OnCalendar=Sat *-*-* 19:30:00`.
- [ ] `systemctl daemon-reload`.
- [ ] `systemctl enable --now daengtisiams-pilot-snapshot.timer`.
- [ ] Verify timer enabled/active.
- [ ] Verify next trigger via `systemctl list-timers`.
- [ ] Run service manually once (`systemctl start daengtisiams-pilot-snapshot.service`).
- [ ] Verify output JSON exists.
- [ ] Validate JSON (`JSON_FILE_OK`).
- [ ] Summarize JSON status safely (no PII).
- [ ] Verify app health and HTTP.
- [ ] Document rollback.

## systemd Timer Decision

Final timer unit after enablement:

```ini
[Unit]
Description=Weekly DaengtisiaMS Pilot Performance Snapshot

[Timer]
OnCalendar=Sat *-*-* 19:30:00
Persistent=true
Unit=daengtisiams-pilot-snapshot.service

[Install]
WantedBy=timers.target
```

- Represents **Sunday 03:30 WITA** on current UTC VPS.
- `Persistent=true` catches missed runs after reboot.
- No `--fail-on-watch` in scheduled run — job success = JSON file produced.
- Snapshot status is read from JSON in weekly evidence review.

## Wrapper Pattern Confirmation

**Correct:**

```bash
--output="pilot-snapshot-${TS}.json"
```

**Incorrect:**

```bash
--output="storage/app/monitoring/pilot-snapshot-${TS}.json"
```

Reason: `pilot:performance-snapshot` already constrains/prepends output to `storage/app/monitoring/`.

## First Evidence Plan

1. `systemctl start daengtisiams-pilot-snapshot.service`
2. Identify latest `storage/app/monitoring/pilot-snapshot-*.json`
3. Validate with `JSON_THROW_ON_ERROR`
4. Summarize `overall_status` and section statuses: app, database, resources, http, logs
5. Summarize logs grouping metrics when present (fresh/historical/stack trace counts)
6. Confirm file owner `www-data:www-data`, mode `644`, non-zero size

## Rollback Plan

Disable timer:

```bash
systemctl disable --now daengtisiams-pilot-snapshot.timer
systemctl daemon-reload
```

Full removal if needed:

```bash
rm -f /etc/systemd/system/daengtisiams-pilot-snapshot.timer
rm -f /etc/systemd/system/daengtisiams-pilot-snapshot.service
rm -f /usr/local/sbin/daengtisiams-pilot-snapshot
systemctl daemon-reload
```

Manual command remains available: `php artisan pilot:performance-snapshot --json`.

## What Was Not Done

- No app deploy.
- No migration.
- No cron.
- No alert.
- No dashboard.
- No DB table.
- No retention cleanup automation.

## Local Checks

| Check | Result |
|---|---|
| git diff --check | pass |
| graphify update | pass (2042 files, graphify-out updated) |
| php artisan about | pass (Laravel 12.61.0, PHP 8.5.4 local) |
| artisan list command | `pilot:performance-snapshot` present |

## Post-GO VPS Ops Evidence

Final VPS ops evidence (timer enablement, first manual service run, JSON validation, app health) is reported in the sprint closure chat summary after GO tag. No post-GO doc commit unless a new evidence sprint is opened.

## Recommended Next Sprint

**Primary:** Sprint 68.27 — Pilot Snapshot First Scheduled Run Review

**Alternative:** Sprint 68.27 — Pilot Snapshot Retention Cleanup Plan

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / VPS TIMER ENABLED / FIRST EVIDENCE VERIFIED / NO APP DEPLOY
