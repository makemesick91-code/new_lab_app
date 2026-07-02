# Sprint 68.24 — Pilot Snapshot Scheduling Plan

## Executive Summary

- Sprint 68.23 confirmed the snapshot command is stable after stack trace noise reduction.
- Overall status is **OK** on VPS/pilot — App, Database, Resources, HTTP, and Logs all OK; `--fail-on-watch` exit 0.
- Manual weekly evidence now works reliably but is repetitive (SSH + same artisan command each week).
- This sprint plans scheduling only; **no cron/systemd was created**.
- **Deploy is not needed** in this sprint.
- Recommended scheduler is **systemd timer**, with cron as fallback.
- First future rollout should be **dry-run / no-alert**; timer may remain disabled until stakeholder approval.
- Manual command remains fallback for operators at any time.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed | No |
| Deploy performed | No |
| Reason | Planning/docs-only |
| Code change needed | No |
| Migration needed | No |
| Cron/systemd created | No |
| Alert/dashboard | No |

## Background Evidence

| Sprint | Result | Decision |
|---|---|---|
| 68.18 | Command implemented/deployed | OK |
| 68.20 | Logs classification tuned/deployed | OK |
| 68.22 | Stack trace noise reduction deployed | Overall OK |
| 68.23 | Weekly evidence after grouping (PR #131, merge `fcce01e`) | Overall OK — no VPS drift |

**VPS baseline at Sprint 68.23 evidence:** HEAD `b0d4082`, tag `sprint-68-22-pilot-snapshot-stack-trace-noise-reduction-go`, APP_ENV `pilot`, Q5/Q6 sub-ms, HTTP `/` ~32 ms, `/login` ~20 ms, disk ~91.71 GB free, RAM ~7.1 GB available, payments 25, logs grouping effective (`fresh_error_like_count=0`, `historical_stack_trace_line_count=1090` grouped informatif).

## Problem Statement

Checklist monitoring manual Sprint 68.14–68.16 dan command `php artisan pilot:performance-snapshot` Sprint 68.18–68.23 **terbukti efektif** — dua siklus evidence berturut-turut setelah tuning logs (68.21 → 68.22 → 68.23) menghasilkan keputusan OK tanpa deploy tambahan.

Namun proses mingguan masih **repetitif**:

- Operator harus SSH ke VPS (`daengtisiams-vps`) setiap minggu.
- Perintah yang sama diulang manual (`pilot:performance-snapshot`, validasi JSON, dokumentasi sprint evidence).
- Waktu eksekusi bervariasi tergantung kapan operator tersedia.
- Output file tidak distandarkan ke path tetap kecuali operator menambahkan `--output=` secara manual.

Scheduling automation dapat **menstandarkan waktu capture** dan **format file output** di `storage/app/monitoring/`, tetapi harus dirancang dengan hati-hati:

- Tidak mengekspos PII, raw logs, atau secrets.
- Tidak menulis DB atau mengubah konfigurasi aplikasi.
- Tidak menghasilkan alert berisik pada status WATCH di rollout pertama.
- Tidak menggantikan review manusia — operator tetap membaca JSON dan memutuskan sprint berikutnya.
- Memerlukan persetujuan stakeholder dan VPS ops sebelum aktivasi timer/cron.

## Goals

- Standardize weekly snapshot capture time and output location.
- Reduce manual SSH repetition for routine evidence collection.
- Keep command privacy-safe (aggregate counts/timings only; no PII/raw logs).
- Keep output under `storage/app/monitoring/` only.
- Preserve manual command as fallback (`php artisan pilot:performance-snapshot` anytime).
- Avoid alert noise in first scheduling rollout.
- Define deploy/VPS ops requirements for future implementation sprint.
- Document approval gates, rollout phases, rollback, and retention before any server change.

## Non-Goals

- No cron/systemd activation in this sprint.
- No service/timer unit files created on VPS.
- No alert integration (email/Telegram/WhatsApp).
- No dashboard UI.
- No monitoring DB table or migration.
- No command code change unless a real defect is discovered.
- No deploy, SSH write, service restart, cache clear, composer install, or npm build.
- No raw log collection or storage.
- No PII/KTP/NIK in output or documentation.
- No automatic remediation (restart, index creation, optimization).

## Recommended Scheduler

| Option | Pros | Cons | Decision |
|---|---|---|---|
| systemd timer | Robust timer semantics; `systemctl status/list-timers`; journal logs; `Persistent=true` catches missed runs; consistent ops | Requires VPS root/sudo; unit files need review | **Recommended** |
| cron (`/etc/cron.d/` or user crontab) | Simple; widely understood | Less structured; `%` escaping in cron; weaker missed-run recovery | **Fallback** |
| Laravel scheduler | App-integrated; reuses artisan | Still needs cron/systemd to trigger `schedule:run`; adds app dependency for ops | Later option |
| Manual weekly | Safest; already proven | Repetitive; timing varies | **Always available fallback** |

**Rationale for systemd preference on VPS:**

- Easier status inspection via `systemctl status daengtisiams-pilot-snapshot.timer`.
- Execution logs visible in journal (`journalctl -u daengtisiams-pilot-snapshot.service`).
- `OnCalendar` + `Persistent=true` handles reboot/missed window better than basic cron.
- Can run as `www-data` (or approved app user) with explicit `WorkingDirectory`.
- Aligns with Sprint 68.17 Phase 2 recommendation and standard Linux service ops practice.

## Recommended Schedule

| Item | Recommendation |
|---|---|
| Frequency | Weekly |
| Day | Sunday |
| Time | 03:30 (early morning) |
| Timezone | **Confirm server timezone before implementation** — target low-usage window in Asia/Makassar (WITA, UTC+8) if VPS uses local time |
| Rationale | Low clinic usage; completes before weekly operational review; avoids peak hours |
| First rollout | Dry-run / manual `systemctl start` — timer may remain **disabled** until approval |
| Alerts | None in first rollout |
| Default `--since` | `24h` (command default) — sufficient for weekly fresh-error window |
| Optional extended lookback | `--since=7d` for monthly review runs only (manual or separate scheduled job later) |

## Future Command Pattern

**Primary pattern (VPS path):**

```bash
cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot \
  --json \
  --output=storage/app/monitoring/pilot-snapshot-YYYYMMDD-HHMMSS.json
```

**Equivalent with timestamp (shell — for wrapper script or cron):**

```bash
cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot \
  --json \
  --output=storage/app/monitoring/pilot-snapshot-$(date +%Y%m%d-%H%M%S).json
```

**Optional Markdown (separate run or second output in future sprint):**

```bash
cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot \
  --markdown \
  --output=storage/app/monitoring/pilot-snapshot-$(date +%Y%m%d-%H%M%S).md
```

**Design notes:**

- Output path **must** remain under `storage/app/monitoring/` — enforced by command (`PilotPerformanceSnapshotCommand` rejects paths outside this directory).
- JSON is **machine-readable** and preferred for scheduled runs; operator copies summary into sprint evidence doc as needed.
- Escaping differs between cron (`%` → `\%`) and systemd (`ExecStart` does not expand shell `date` by default).
- Prefer a small **root-owned wrapper script** reviewed in implementation sprint if timestamp expansion is needed in systemd — do not implement in Sprint 68.24.
- Command remains **read-only**: no DB write, no service restart, no migration.
- Env guard applies: VPS `APP_ENV=pilot` is allowed; production requires `--force-production` (not expected on pilot VPS).

## Output and Retention Plan

| Item | Recommendation |
|---|---|
| Output directory | `storage/app/monitoring/` (under Laravel app root on VPS) |
| JSON file | Yes — primary scheduled output |
| Markdown file | Optional — human-readable; can be added in Phase 3 review or separate manual run |
| Raw logs | No — never stored by command |
| PII | No — aggregate counts and timings only |
| Retention | **90 days** initially (~13 weekly files) |
| Monthly summary | Operator manually documents key metrics in sprint evidence doc |
| Future automated cleanup | Delete files older than 90 days under `storage/app/monitoring/` (Artisan prune command or cron find — **future sprint only**) |
| Repo commit | **Never** commit generated snapshot JSON/Markdown to git |
| Download | Only when needed for investigation; do not bulk-sync to dev machines |
| Disk impact | Negligible — each JSON file is small (KB range); 90-day retention safe on ~92 GB free VPS |

**Example filenames:**

```text
storage/app/monitoring/pilot-snapshot-20260706-033001.json
storage/app/monitoring/pilot-snapshot-20260706-033001.md
```

## Official Thresholds (Reference for JSON Review)

Use Sprint 68.12–68.14 / 68.17 official thresholds when reading scheduled JSON output.

### SQL

| Runtime | Status |
|---:|---|
| <100 ms | OK |
| 100–300 ms | WATCH |
| 300–500 ms | WATCH / investigate |
| 500 ms–1s | INVESTIGATE |
| >1s | FIX |

### HTTP

| Runtime | Status |
|---:|---|
| <100 ms avg | OK |
| 100–300 ms avg | OK / WATCH |
| 300–500 ms avg | WATCH |
| 500 ms–1s avg | INVESTIGATE |
| >1s avg or p95 | FIX |

### Logs (Sprint 68.20–68.22 rules)

- Historical-only entries must **not** escalate status by themselves.
- Historical stack trace continuation lines grouped under historical parent are **informational**.
- Fresh errors inside lookback window affect status.
- Orphan timestamp-less error-like lines remain safe fallback (should stay 0 after 68.22).
- CRITICAL/emergency/fatal **fresh** errors escalate faster.

### Capacity / optimization triggers (human decision)

- Owner Dashboard HTTP avg >300 ms consistently.
- Owner Dashboard HTTP avg >500 ms on pilot/VPS.
- Owner Dashboard p95 >1s.
- Q5/Q6 SQL >500 ms consistently.
- Payment rows >1M plus user-visible slowness.
- Payment rows >10k → closer weekly evidence review.
- Pilot/VPS evidence materially slower than local stress baseline.
- User complaints about slowness.

## systemd Timer Design

**Documentation only — do NOT create these files in Sprint 68.24.**

### Potential service unit

```ini
# /etc/systemd/system/daengtisiams-pilot-snapshot.service
[Unit]
Description=DaengtisiaMS Pilot Performance Snapshot
After=network-online.target postgresql.service nginx.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/asia-dental-lab-v2
# NOTE: systemd does NOT expand $(date ...) in ExecStart by default.
# Implementation sprint should use ONE of:
#   (a) /bin/sh -lc 'cd ... && php artisan ... --output=storage/app/monitoring/pilot-snapshot-$(date +%Y%m%d-%H%M%S).json'
#   (b) root-owned wrapper script at /usr/local/bin/daengtisiams-pilot-snapshot-run.sh
ExecStart=/bin/sh -lc 'cd /var/www/asia-dental-lab-v2 && /usr/bin/php artisan pilot:performance-snapshot --json --output=storage/app/monitoring/pilot-snapshot-$(date +%%Y%%m%%d-%%H%%M%%S).json'
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

### Potential timer unit

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

### Future enablement commands (implementation sprint only)

```bash
sudo systemctl daemon-reload
sudo systemctl enable daengtisiams-pilot-snapshot.timer   # only after approval
sudo systemctl start daengtisiams-pilot-snapshot.timer      # or leave disabled until dry-run passes
systemctl list-timers | grep daengtisiams
sudo systemctl start daengtisiams-pilot-snapshot.service    # manual one-shot test
journalctl -u daengtisiams-pilot-snapshot.service -n 50
ls -la /var/www/asia-dental-lab-v2/storage/app/monitoring/
```

## cron Fallback Design

**Documentation only — do NOT create this entry in Sprint 68.24.**

```cron
# /etc/cron.d/daengtisiams-pilot-snapshot
# m h dom mon dow user  command
30 3 * * 0 www-data cd /var/www/asia-dental-lab-v2 && /usr/bin/php artisan pilot:performance-snapshot --json --output=storage/app/monitoring/pilot-snapshot-$(date +\%Y\%m\%d-\%H\%M\%S).json >>/dev/null 2>&1
```

**Notes:**

- Cron requires escaped `%` as `\%`.
- Shell expansion must be tested on VPS before enablement.
- `WorkingDirectory` and PHP binary path must be verified (`which php` on VPS = PHP 8.3).
- Redirect to `/dev/null` suppresses console output — prefer journal/systemd for first rollout observability.
- systemd is preferred when VPS ops can manage unit files.

## Permission and User Plan

| Item | Recommendation |
|---|---|
| Run user | `www-data` (same as php-fpm/nginx app user on VPS) |
| Working directory | `/var/www/asia-dental-lab-v2` |
| Output directory | `storage/app/monitoring/` — must exist and be writable by run user |
| Preflight | `mkdir -p storage/app/monitoring && chown www-data:www-data storage/app/monitoring` (implementation sprint) |
| Sudo inside command | No — artisan command does not require elevated privileges |
| DB write | No — read-only checks only |
| Service restart | No — scheduler must not restart php-fpm/nginx/postgresql |
| `.env` access | Laravel reads config normally; command does not dump `.env` contents |
| Git access | Not required for snapshot execution |

## Failure Semantics

| Topic | Recommendation |
|---|---|
| First rollout `--fail-on-watch` | **Do NOT use** — job success = snapshot file produced |
| WATCH handling | Evaluate `overall_status` from JSON during weekly review; do not fail timer on WATCH |
| INVESTIGATE/FIX | Review JSON manually; open investigation sprint if needed — no auto-alert yet |
| Exit code 0 | Expected when snapshot completes and file is written |
| Exit code 10 | Invalid env or bad `--since` — investigate scheduler/command config |
| Later alerting | May use `--fail-on-watch` or custom wrapper parsing JSON when alert channel approved |
| Transient HTTP WATCH | Sprint 68.23 showed one markdown run with transient Http WATCH while JSON/console were OK — another reason to avoid fail-on-watch for evidence-only scheduling |

**Design choice:** For first scheduled rollout, prefer **NOT** using `--fail-on-watch`. Reason: systemd/cron job should complete and produce evidence even when snapshot status is WATCH; status should be evaluated from JSON content, not job failure exit code.

## Approval Gates

Before Sprint 68.25 implementation, obtain explicit approval on:

| Gate | Question for stakeholder / ops |
|---|---|
| Owner/stakeholder | Approve weekly automated snapshot on pilot VPS? |
| VPS ops | Approve systemd timer (or cron fallback) installation? |
| Server timezone | Confirm VPS `timedatectl` — does 03:30 match intended WITA low-usage window? |
| Run user | Confirm `www-data` (or alternative app user)? |
| Schedule time | Confirm Sunday 03:30 or propose alternative |
| Retention | Confirm 90-day file retention before automated cleanup |
| Output format | JSON only, or JSON + Markdown each week? |
| Timer initial state | Start **disabled** with manual dry-run first, or enable immediately after dry-run? |
| Failure alerting | Evidence-only (no alert) for Phase 1–3, or early exit-code alerting? |
| Deploy alignment | Confirm VPS app tag matches expected GO tag before enabling scheduler |

## Future Rollout Plan

### Phase 1 — Dry-run Implementation (Sprint 68.25 primary)

- Create `storage/app/monitoring/` if missing; verify permissions.
- Install systemd service + timer files (or cron entry) in **disabled** state.
- Run manual one-shot: `systemctl start daengtisiams-pilot-snapshot.service` (or equivalent cron test).
- Verify JSON file created, valid (`JSON_THROW_ON_ERROR`), path under `storage/app/monitoring/`.
- Verify no DB write, no service restart, no PII in file.
- Document evidence in sprint doc.
- **No alert.**

### Phase 2 — Enable Timer

- After stakeholder approval, `systemctl enable --now daengtisiams-pilot-snapshot.timer`.
- Verify `systemctl list-timers` shows next run.
- Wait for first scheduled run OR trigger manual start again.
- Inspect output file and journal logs.
- Disable quickly if misconfigured: `systemctl disable --now daengtisiams-pilot-snapshot.timer`.

### Phase 3 — Weekly Review From Generated File

- Operator reads latest JSON each week (SSH or future dashboard).
- Compare metrics week-over-week (DB size, payments, Q5/Q6, HTTP, logs counts).
- Document summary in sprint evidence doc — do not commit JSON to repo.
- Manual `php artisan pilot:performance-snapshot` remains available for ad-hoc checks.

### Phase 4 — Optional Alert / Dashboard (deferred)

- Alert on INVESTIGATE/FIX only (Sprint 68.17 Phase 3).
- Owner dashboard trend from stored JSON or optional DB table.
- Retention cleanup command (`--prune-days=90`).
- Requires separate approval, deploy, and secret management.

## Rollback Plan

If scheduled automation causes issues (wrong time, permission errors, diskus failures):

```bash
# Disable systemd timer immediately
sudo systemctl disable --now daengtisiams-pilot-snapshot.timer

# Optional: disable service unit from manual triggers
sudo systemctl disable daengtisiams-pilot-snapshot.service

# Remove cron entry if cron was used instead
sudo rm -f /etc/cron.d/daengtisiams-pilot-snapshot
# or edit crontab and remove line

# Remove wrapper script if created
sudo rm -f /usr/local/bin/daengtisiams-pilot-snapshot-run.sh

sudo systemctl daemon-reload
```

**After rollback:**

- Continue manual weekly snapshot via SSH.
- No DB rollback required — command is read-only.
- No data loss expected — generated JSON files can remain or be deleted manually.
- Investigate root cause before re-enabling.

## Risk Register

| Risk | Impact | Mitigation |
|---|---|---|
| Wrong timezone | Snapshot runs during busy hours | Confirm `timedatectl` before enablement |
| Permission issue | No output file; silent cron failure | Preflight `storage/app/monitoring/` ownership; test manual run first |
| File accumulation | Disk usage over months | 90-day retention; future prune command |
| WATCH treated as job failure | Alert/timer noise | Avoid `--fail-on-watch` in first rollout |
| Raw output committed to git | Privacy/repo bloat | Never commit `storage/app/monitoring/`; `.gitignore` already excludes storage |
| Operator assumes OK without reading JSON | Missed regression | Weekly human review sprint doc still required |
| Scheduler drift if app not deployed | Old command behavior | Verify VPS GO tag before enabling timer |
| systemd date expansion bug | Fixed filename overwrite | Use wrapper script or `/bin/sh -lc` with tested pattern |
| Cron `%` escaping error | Job never runs correctly | Test cron line on VPS; prefer systemd |
| Transient HTTP WATCH | False alarm if fail-on-watch used | Evidence-only exit semantics in Phase 1–3 |

## Deploy Requirements For Future Sprint

| Future Change | Deploy/VPS Ops Needed | Notes |
|---|---|---|
| systemd service/timer units | VPS ops yes | Unit files on server; not in app repo unless documented in ops runbook |
| cron entry | VPS ops yes | No app deploy if command already on VPS at correct tag |
| Wrapper script in repo | App deploy yes | Track in repo → merge/GO/deploy |
| Wrapper script on server only | VPS ops yes | No app deploy if script is server-local |
| `storage/app/monitoring/` mkdir/chown | VPS ops yes | One-time setup |
| Retention cleanup command | App deploy yes | New Artisan code — future sprint |
| Alert integration | App deploy + secrets | `.env` webhook tokens — future only |
| Dashboard | App deploy | Future only |
| Command bug fix | App deploy yes | Standard GO tag deploy per contingency rules |

## Recommended Next Sprint

**Primary:**

**Sprint 68.25 — Pilot Snapshot Scheduling Implementation Dry Run**

Scope:

- Implement systemd service/timer (preferred) or cron in **controlled/disabled** form.
- Create/verify `storage/app/monitoring/` permissions.
- Manual one-shot run; verify JSON output.
- No alert, no dashboard, no migration.
- VPS ops required; app deploy only if wrapper script tracked in repo or command fix needed.
- Timer may remain disabled until stakeholder sign-off.

**Alternative (if scheduling not approved yet):**

**Sprint 68.25 — Pilot Snapshot Weekly Evidence Review 2**

- Continue manual SSH --evidence via SSH.
- Defer systemd/cron to Sprint 68.26 scheduling implementation.

## What Was Not Done

- No deploy.
- No SSH/VPS write operation.
- No cron/systemd unit created.
- No alert integration.
- No dashboard.
- No migration.
- No DB write.
- No command code change.
- No generated monitoring output committed.

## Commands Run

Local only (Sprint 68.24):

```bash
pwd
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch feature/sprint-68-24-pilot-snapshot-scheduling-plan
git status --short
git branch --show-current
git log --oneline -5
php artisan about
php artisan list | grep pilot:performance-snapshot
graphify update .
graphify query "pilot performance snapshot command scheduling monitoring"
rg -n "pilot:performance-snapshot|monitoring|snapshot" app docs/sprints -g'*.php' -g'*.md'
git diff --check
```

Pest, Pint, and npm not run — docs-only sprint.

## Safety Confirmation

- No deploy.
- No VPS write operation.
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump/log committed.
- No generated monitoring output committed.
- No real PII/KTP/NIK exposed.

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / NO DEPLOY

*(Status fields updated after git/PR/tag workflow completes.)*
