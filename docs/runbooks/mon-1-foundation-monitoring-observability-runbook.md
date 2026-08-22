# MON-1 — Foundation Monitoring & Observability Runbook

Read-only consolidation of the observability signals that already exist in
DaengtisiaMS into one explainable decision. MON-1 **surfaces** existing signals;
it never duplicates NSF/CICD gates, deploy evidence, smoke gates, or domain audits.

## What MON-1 does

- Aggregates: ENT-8 health (`/health/live`, `/health/ready`, `/health/lb`), ENT-5
  failed jobs, ENT-12 backups, NSF-10 governance evidence, storage/cache
  writability, application-log error counts, and the cached decision of the
  existing governance/domain audit commands.
- Emits ONE decision: **GO / WATCH / FAIL / UNKNOWN** with per-signal reasons and
  safe remediation hints.
- Surfaces it via a CLI command and a read-only, Super-Admin-only page.

## What MON-1 intentionally does NOT duplicate

| Existing capability | Owner | MON-1 relationship |
| --- | --- | --- |
| Health probes / endpoints | ENT-8 / LB-1 | reuses `HealthCheckService` in-process |
| Release safety + smoke | NSF-9 | reads outcome, never re-runs smoke |
| Release evidence capture/verify | NSF-10 | reads cached `*-check.json` decisions |
| CI classification + gates | CICD-CTRL-1 / NSF-R011/R012 | observes, never re-classifies |
| Deploy / rollback | ENT-11 | reads backup/deploy artifacts |
| Backup / DR | ENT-12 | lists latest backup file only |
| Per-section raw inspection | ENT-7 dev-console | complements with a consolidated decision |
| Domain audits | Sprint 68.45 / FIX-PRE-68-45 | reports exit status, never re-implements |

## Commands

```bash
# Lightweight consolidated status (report-only, exit 0)
php artisan foundation:monitoring-observability-check

# Machine-readable
php artisan foundation:monitoring-observability-check --json

# Enforce: exit non-zero only on real unsafe FAIL states
php artisan foundation:monitoring-observability-check --strict

# Include the existing audit commands (CLI-only) and report their exit status
php artisan foundation:monitoring-observability-check --include-audits --strict
```

- Default mode never runs Pest tests, never re-runs CI gates, never executes
  domain audits.
- `--include-audits` is the ONLY mode that invokes
  `rme:doctor-performance-access-audit --strict` and
  `inventory:procurement-workflow-audit --strict`; those commands remain the
  authoritative source of truth.

## Interpreting the decision

- **GO** — all known signals healthy.
- **WATCH** — a non-blocking degradation (failed jobs present, stale/absent
  backup, recent log errors, health degraded). Investigate; not release-blocking.
- **FAIL** — an unsafe state: health down, `APP_DEBUG` on in production/pilot,
  unexpected maintenance mode, storage/cache not writable, a governance/audit
  evidence NO-GO, or an included audit that failed. `--strict` exits non-zero.
- **UNKNOWN** — no reliable in-app source (queue worker/scheduler liveness) or no
  cached evidence yet. Never treated as green; run the CLI for the full picture.

## Read-only monitoring page

- Route `GET /foundation/monitoring` (`foundation.monitoring.index`).
- Gated by `permission:view_developer_console` (Super Admin only via
  `Gate::before`) — **no new permission was added**.
- Read-only: no log clearing, no queue retry/delete, no chmod. It never renders
  env values, DB credentials, API keys, tokens, raw stack traces, KTP/NIK, or raw
  failed-job payloads (all free text passes `SensitiveValueMasker`).
- The page never runs heavy audits; audit rows show cached evidence metadata and
  point to the CLI for a full audit.

## Responding to a storage/cache permission FAIL

MON-1 probes `storage/framework/cache/data`, `storage/logs`, `bootstrap/cache`
with a short-lived temp file (create + delete). If any is not writable it is a
FAIL. MON-1 never chmods. Fix on the VPS via the existing deploy-runner
convention:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod ug+rwx {} \;
php artisan optimize:clear
```

## Responding to an audit FAIL

Run the authoritative command directly (it is unchanged by MON-1):

```bash
php artisan inventory:procurement-workflow-audit --strict   # exit 2 on unsafe FAIL
php artisan rme:doctor-performance-access-audit --strict     # exit 2 on anomaly
```

## Which log files does Monitoring read?

Not a fixed path. Both the MON-1 `laravel_log` signal and the pilot snapshot resolve their
sources from the effective logging configuration
([rule 114](../../.cursor/rules/114-monitoring-log-source-authority.mdc)), so changing
`LOG_CHANNEL` or `LOG_STACK` moves the monitor with the application instead of blinding it.

To see what the running configuration resolves to — read-only, and safe on production
because it boots nothing and writes no log record:

```bash
cd /var/www/asia-dental-lab-v2
php -r '$c=require "bootstrap/cache/config.php"; $l=$c["logging"];
  echo "default=".$l["default"]."\n";
  echo "stack=".json_encode($l["channels"]["stack"]["channels"]??null)."\n";
  echo "single.path=".($l["channels"]["single"]["path"]??"-")."\n";'
```

Do **not** use `php artisan tinker` for this. Tinker writes real `ERROR` records into the
application log and will hold `logs = WATCH` for the next 24 hours
([rule 113 R6](../../.cursor/rules/113-monitoring-logs-watch-truthfulness.mdc)).

The snapshot reports what it actually read under `sections.logs.metrics`:
`log_sources` (channel, driver, file, status), `log_sources_read`, `log_sources_absent`,
`log_sources_unreadable`, `log_sources_unsupported`, and `source_coverage_complete`. If
`source_coverage_complete` is `false`, the verdict is deliberately not OK — read the
warnings to see which source was missing, unreadable, or unsupported.

## VPS smoke checklist (post-deploy)

```bash
php artisan foundation:monitoring-observability-check
php artisan foundation:monitoring-observability-check --json
php artisan foundation:monitoring-observability-check --include-audits --strict
curl -sS -o /dev/null -w '%{http_code}' https://<host>/health/live   # 200
curl -sS -o /dev/null -w '%{http_code}' https://<host>/health/ready  # 200
curl -sS -o /dev/null -w '%{http_code}' https://<host>/health/lb     # 200
curl -sS -o /dev/null -w '%{http_code}' https://<host>/foundation/monitoring  # 302 guest
```

Expected on the single-VPS pilot: overall **WATCH** or **GO**. `queue_worker`
UNKNOWN is expected (no reliable in-app source); `deploy_evidence`/`deploy_backup`
turn GO once the first governed deploy has run on the box.

## Rollback

MON-1 is additive (config + service + command + read-only route/view + docs +
tests; no migration, no permission, no seeder/role change). To revert, revert the
merge commit or use `scripts/rollback-vps.sh` back to
`sprint-68-45-inventory-procurement-workflow-stabilization-gr-batch-vendor-governance-go`.
