# Runbook — VPS Deploy Evidence Timeout / SSH Broken-Pipe Recovery

> **Production target (since 2026-11-03):** Biznet host `DaengtisiaMS` (`139.190.106.38`), reached with
> `ssh daengtisiams-biznet-prod` as `dmsadmin`; privileged steps run through `sudo -n`. The Hostinger host
> `srv1730088` is WRITE-FROZEN. Prove the target first: `bash scripts/production-target-check.sh --remote`
> (`docs/runbooks/production-host-identity.md`).
>
> **No deploy has run on Biznet yet.** The first one needs explicit owner approval, the readiness checklist
> in `docs/runbooks/production-host-identity.md` §6 closed (Node/npm are not installed there), and the
> one-time first-deploy procedure in §6a of that runbook (quarantine, then `verify-transition`).

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
dropped connection can never be mistaken for success.

## What counts as proof of a deploy

A status file says only `exit=N`. It does not say where, when, what or which
program ran, so **a bare status value is never proof**. The 2026-11-03 migration
proved why: it copied the frozen host's `storage/logs/deploy-runner/` to Biznet,
and the copied files say `exit=0`, `DEPLOY OK`, and pin the very commit the
copied checkout is at.

Every run therefore writes a binding record, `deploy-<deploy-id>.host`, and the
runner's `verify` accepts a success only when **all six** facts agree
(`scripts/lib/deploy-runner-evidence.sh`):

| Fact | Proven by |
|---|---|
| Production host identity | the record names this host, and this host is the production host in `deploy/production-host.conf` (never a frozen host) |
| Execution timestamp | canonical UTC `started_at` and `finished_at` in the record, in order, not in the future |
| Deployment identifier | the record's `deploy_id` is the run's identifier, and the status, record and log are found by that identifier (never through `latest.log`) |
| Target commit | exactly one `DEPLOY_TARGET_PINNED=` in the run's own log, `DEPLOY_HEAD_TARGET_MATCH=YES (<sha>)`, and the checkout is still at that commit |
| Runner version | the record's evidence contract (`runner_contract=2`) and the SHA-256 of the runner program |
| Actual execution result | the record's `result_exit` equals the status, and a success also shows `PRODUCTION HOST PREFLIGHT: GO` and `DEPLOY OK` in the run's own log |

Anything else exits `4` with a named verdict — `UNBOUND`, `FOREIGN`, `FROZEN`,
`NOT-PRODUCTION`, `INCOMPATIBLE`, `INCOMPLETE`, `MISMATCHED`, `MALFORMED`,
`UNVERIFIED` or `STALE` — and is never GO. A proven failure is
`DEPLOY RUNNER FAILED` (exit `1`); a run still going is pending (exit `3`).

## Normal deploy (recommended)

```bash
# 1. Start the deploy detached (returns immediately; safe if the pipe drops).
#    Note the deploy_id it prints.
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start'

# 2. Follow progress (re-runnable; reconnect any time):
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh follow'

# 3. Verify THAT run by its identifier — the only GO evidence:
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh verify <deploy-id>'
```

`verify <deploy-id>` prints the six facts and `DEPLOY RUNNER OK` on success.
`status` performs the same verification on whichever run `latest.status` points
at; prefer `verify <deploy-id>`, because it cannot be confused by another start.

## If SSH disconnects mid-deploy

The deploy keeps running server-side. Reconnect and:

```bash
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh follow'
# Progress only (never a GO criterion): the tail of the run's own log.
ssh daengtisiams-biznet-prod 'sudo -n tail -n 120 /var/www/asia-dental-lab-v2/storage/logs/deploy-runner/deploy-<deploy-id>.log'
# Then decide with the verifier:
ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh verify <deploy-id>'
```

Never read `latest.status` raw (`cat`, `less`, a file manager) to decide GO: its
`exit=0` may be another host's, another run's, or a pre-binding runner's.

## The first Biznet deploy (one time only)

The runner already in the Biznet checkout predates the binding record, so the
first deploy it launches is `UNBOUND` by design. Follow
`docs/runbooks/production-host-identity.md` §6a exactly:

1. Quarantine the copied pre-binding evidence with
   `scripts/deploy-runner-quarantine-stale-status.sh` (dry run, then `--apply`;
   it moves, never deletes).
2. Start the deploy as above.
3. Prove it with
   `sudo -n bash scripts/deploy-vps-runner.sh verify-transition <deploy-id> <authorized-merge-sha>`,
   which must print `TRANSITION DEPLOY VERIFIED`.

Every later deploy is bound and must report `DEPLOY RUNNER OK` from `verify`.

## Verify final status

- `sudo -n bash scripts/deploy-vps-runner.sh verify <deploy-id>` → `DEPLOY RUNNER OK`
  (for the first Biznet deploy only: `verify-transition` → `TRANSITION DEPLOY VERIFIED`).
- `php artisan release:evidence-check --profile=vps` → GO.
- Smoke over `https://daengtisia.online`: `/health/live`, `/health/ready`, `/health/lb` → 200; `/login` → 200.

## Safety preserved

Backup-first, `migrate --force` only, ENT-8 cache-clear order, no
`migrate:fresh`/`db:wipe`/`schema:drop`/`migrate:reset`. Mandatory gates are
never silently skipped; only long optional evidence capture is non-blocking.
