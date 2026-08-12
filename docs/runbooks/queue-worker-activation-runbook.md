# Runbook — Queue Worker Activation (conservative, single process)

**Owner:** Foundation / Ops. **Cadence:** review each deploy that changes queue behavior.
**Governance:** `foundation:queue-worker-runtime-check`, ENT-5 `foundation:queue-retry-failed-job-check`.

## When to run

After a successful deploy with green governance gates, when queue worker
processing is wanted on the VPS pilot. The deploy script NEVER starts the worker
(ENT-5); activation is an explicit, operator-run step.

## Prerequisites (must all be green)

- `php artisan foundation:queue-worker-runtime-check --strict` → GO
- `php artisan foundation:queue-retry-failed-job-check --strict` → GO
- Queue connection is broker-backed (database or redis), never `sync`:
  `php artisan config:show queue.default` → `database`
- `failed_jobs` table exists: `php artisan queue:failed` returns cleanly.

## Install / enable the service

```bash
sudo cp deploy/systemd/daengtisiams-queue-worker.service \
  /etc/systemd/system/daengtisiams-queue-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now daengtisiams-queue-worker.service
```

## The tracked unit changed — RE-INSTALL IT

**The deploy does not install this unit.** By ENT-5 design the deploy script
never installs or starts a worker, so editing
`deploy/systemd/daengtisiams-queue-worker.service` in the repository and
deploying changes **nothing** on the host: systemd keeps running the previously
installed copy.

That is how a queue can silently lose its consumer. If the unit's `--queue=`
list changed — for example when a module adds a dedicated queue — copy it again:

```bash
sudo cp deploy/systemd/daengtisiams-queue-worker.service \
  /etc/systemd/system/daengtisiams-queue-worker.service
sudo systemctl daemon-reload
sudo systemctl restart daengtisiams-queue-worker.service
```

Then confirm what systemd actually runs, not what the repository says:

```bash
systemctl cat daengtisiams-queue-worker.service | grep -- --queue
```

`legacy-rme:rollout-readiness` reads the INSTALLED unit first for exactly this
reason and falls back to the tracked file only when it is absent.

## Restart after a code deploy

The deploy signals a graceful restart; the running worker finishes its current
job then exits and systemd restarts it:

```bash
php artisan queue:restart
sudo systemctl restart daengtisiams-queue-worker.service
```

## Check status

```bash
systemctl is-enabled daengtisiams-queue-worker.service
systemctl is-active daengtisiams-queue-worker.service
sudo systemctl status daengtisiams-queue-worker.service --no-pager
journalctl -u daengtisiams-queue-worker.service -n 100 --no-pager
```

## Inspect / retry / clear failed jobs (manual, operator only)

```bash
php artisan queue:failed
php artisan queue:retry all      # re-queue failed jobs
# queue:flush / queue:forget are manual, operator-run ONLY — never automated.
```

## Smoke

```bash
php artisan foundation:queue-worker-smoke            # dispatch a harmless job
# The running worker consumes it; then confirm:
php artisan queue:failed                              # expect empty
journalctl -u daengtisiams-queue-worker.service -n 20 --no-pager | grep queue-worker-smoke
```

Locally / in CI (no running worker), process inline:

```bash
php artisan foundation:queue-worker-smoke --process --strict
```

The smoke job only writes one bounded, non-PII log line — it never sends
WhatsApp, creates a LabOrder, or touches inventory/payments/patient data.

## Rollback / disable / emergency stop

```bash
sudo systemctl stop daengtisiams-queue-worker.service
sudo systemctl disable daengtisiams-queue-worker.service
```

Disabling the worker is always safe: there are currently no business
`ShouldQueue` jobs, so nothing is lost — pending jobs simply wait in the
`jobs` table until a worker runs again.

## Expected

- Worker `active` + `enabled`, one process.
- `queue:failed` empty (or a documented pre-existing safe state).
- No new Laravel log errors from worker activation.
