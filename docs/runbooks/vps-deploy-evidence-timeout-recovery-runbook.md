# Runbook — VPS Deploy Evidence Timeout / SSH Broken-Pipe Recovery

> **Production target (since 2026-11-03):** Biznet host `DaengtisiaMS` (`139.190.106.38`), reached with
> `ssh daengtisiams-biznet-prod` as `dmsadmin`; privileged steps run through `sudo -n`. The Hostinger host
> `srv1730088` is WRITE-FROZEN. Prove the target first: `bash scripts/production-target-check.sh --remote`
> (`docs/runbooks/production-host-identity.md`).
>
> **No deploy has run on Biznet yet.** The first one needs explicit owner approval and the readiness checklist
> in `docs/runbooks/production-host-identity.md` §6 closed (Node/npm are not installed there).

**Owner:** Foundation / Ops. **Governance:** `foundation:runtime-hardening-check`.

## Problem

The full deploy (`scripts/deploy-vps.sh`) runs a long governance-gate +
release-evidence-capture phase. When the deploy is driven as a foreground
`ssh ... 'bash -s' < scripts/deploy-vps.sh`, a slow evidence phase can outlast
the SSH connection and the pipe drops (broken pipe / client timeout), leaving an
ambiguous deploy state.

## Fix — server-side detached runner

`scripts/deploy-vps-runner.sh` runs `scripts/deploy-vps.sh` DETACHED on the
server (`setsid` + `nohup`), streaming to a log file and recording the real final
exit code in a status file. The deploy therefore outlives the SSH pipe, and a
dropped connection can never be mistaken for success — GO is reported only when
`scripts/deploy-vps.sh` finishes with `exit=0` (it is `set -euo pipefail`, so the
mandatory gates and evidence checks must pass first).

## Normal deploy (recommended)

```bash
# 1. Start the deploy detached (returns immediately; safe if the pipe drops):
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start'

# 2. Follow progress (re-runnable; reconnect any time):
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh follow'

# 3. Confirm final status:
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh status'
```

`status` prints `final exit=0` + `DEPLOY RUNNER OK` on success, or
`DEPLOY RUNNER FAILED` otherwise. Do NOT treat a deploy as GO until you see
`exit=0`.

## If SSH disconnects mid-deploy

The deploy keeps running server-side. Reconnect and:

```bash
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh follow'
# or inspect the raw log directly:
ssh daengtisiams-biznet-prod 'sudo -n tail -n 120 /var/www/asia-dental-lab-v2/storage/logs/deploy-runner/latest.log'
ssh daengtisiams-biznet-prod 'sudo -n cat /var/www/asia-dental-lab-v2/storage/logs/deploy-runner/latest.status'
```

## Verify final status

- `latest.status` contains `exit=0`.
- Deploy log tail ends with `DEPLOY OK: <stamp>`.
- `php artisan release:evidence-check --profile=vps` → GO.
- Smoke: `/health/live`, `/health/ready`, `/health/lb` → 200; `/login` → 200.

## Safety preserved

Backup-first, `migrate --force` only, ENT-8 cache-clear order, no
`migrate:fresh`/`db:wipe`/`schema:drop`/`migrate:reset`. Mandatory gates are
never silently skipped; only long optional evidence capture is non-blocking.
