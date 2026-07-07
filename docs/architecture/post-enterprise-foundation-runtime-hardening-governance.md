# POST-ENT — Enterprise Foundation Runtime Hardening Governance

Status: **DURABLE LOCK**. Post-closure hardening sprint. NOT ENT-17.

Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
(do NOT target `main`).

## Why this exists

ENT-16 formally closed the Enterprise Foundation sequence ENT-1..ENT-16 with the
final tag `enterprise-foundation-go`. The roadmap's next recommended sprint is
`MON-1 — Health Monitoring, Alerting & Uptime Readiness`.

This is a **post-closure hardening / follow-up sprint** that inherits the closed
baseline. It does **not** open ENT-17, does **not** move any ENT GO tag or the
final closure tag, and does **not** change `next_recommended_sprint` away from
`MON-1`. It hardens three runtime concerns that were left for after closure:

1. **ENT-1..ENT-4 audit** — prove those early governance/config/docs locks are
   present, completed, GO-tagged and doc-backed.
2. **Queue worker runtime** — activate a conservative queue worker safely on top
   of ENT-5 Queue, Retry & Failed Job Governance.
3. **Deploy evidence timeout hardening** — make the slow VPS evidence-capture
   phase survive an SSH broken pipe.

## Rules — PEH-R001..PEH-R012

| ID | Rule |
|----|------|
| PEH-R001 | Post-ENT hardening preserves the closed baseline; re-verifies ENT-5..ENT-16 GO; never moves a GO tag; MON-1 stays next. |
| PEH-R002 | ENT-1..ENT-4 are audited as governance/config/docs locks, never retrofitted into runtime modules. |
| PEH-R003 | The ENT-1..4 audit FAILs on a real missing mandatory artifact (roadmap entry, completed status, GO tag, canonical doc, rule-id marker). |
| PEH-R004 | Queue worker activation is conservative: single low-concurrency systemd process, `queue:work` only, ENT-5 tries/backoff/timeout, bounded `--max-time`. |
| PEH-R005 | The worker is NEVER started by the deploy script (ENT-5 `no_long_running_worker_started_by_deploy`); operator-run, worker-ready. |
| PEH-R006 | The worker connection must be broker-backed (database/redis), never `sync`; approved queues are a subset of the ENT-5 allowed set. |
| PEH-R007 | The systemd unit carries no destructive command; destructive literals live only in config; journal output is non-sensitive. |
| PEH-R008 | The slow deploy evidence phase runs through a server-side detached runner (setsid/nohup) with a log + status file so a dropped SSH pipe cannot tear it down. |
| PEH-R009 | A deploy is never GO before the mandatory gates actually pass (the runner reports GO only on `exit=0`); optional evidence is non-blocking but never silently skipped. |
| PEH-R010 | Deploy safety preserved: backup-first, `migrate --force` only, ENT-8 cache-clear order; never `migrate:fresh`/`db:wipe`/`schema:drop`/`migrate:reset` on the VPS. |
| PEH-R011 | Hardening evidence is non-sensitive and captured per ci/vps profile (no secret/credential/backup contents/environment value/KTP/NIK). |
| PEH-R012 | Future worker/deploy hardening registers here with tests first and passes `foundation:runtime-hardening-check` without weakening any ENT-5..ENT-16 gate. |

## Commands

- `php artisan foundation:ent-1-4-audit-check [--strict] [--json]`
- `php artisan foundation:queue-worker-runtime-check [--strict] [--json]`
- `php artisan foundation:runtime-hardening-check [--strict] [--json]` (umbrella)
- `php artisan foundation:queue-worker-smoke [--process] [--strict]`

All are read-only (the smoke command dispatches one harmless non-PII job) and
feed the informational `enterprise_foundation_runtime_hardening_governance`
section of `architecture:foundation-governance-summary`.

## ENT-1..ENT-4 audit result (canonical scope)

| Sprint | Canonical scope | Runtime backfill required | Result |
|--------|-----------------|---------------------------|--------|
| ENT-1 Enterprise Architecture Baseline Lock | governance/config/docs lock | No | Verified — completed, GO-tagged, `enterprise-architecture-baseline-lock.md` present. |
| ENT-2 Database Performance Contract | governance/config/docs lock | No | Verified — completed, GO-tagged, contract + hotspot inventory docs present. |
| ENT-3 Reporting Materialized Summary Expansion | governance/config/docs lock | No | Verified — completed, GO-tagged, contract + candidate inventory docs present. |
| ENT-4 Redis Cache Enterprise Policy | governance/config/docs lock | No | Verified — completed, GO-tagged, policy + TTL + invalidation docs present. |

**No runtime retrofit was required or performed** — ENT-1..ENT-4 were intended
as governance/config/docs locks. The audit proves the durable artifacts exist.

## Queue worker

- Service: `daengtisiams-queue-worker.service` (`deploy/systemd/`).
- Command: `artisan queue:work database --queue=default,reports,notifications,maintenance --sleep=3 --tries=3 --backoff=10,60,180 --timeout=120 --memory=256 --max-time=3600 --rest=1`.
- One process, `Restart=always`, `User=www-data`, `WorkingDirectory=/var/www/asia-dental-lab-v2`.
- Activation runbook: `docs/runbooks/queue-worker-activation-runbook.md`.

## Deploy evidence timeout

- Runner: `scripts/deploy-vps-runner.sh` (`start` / `follow` / `status` / `run`).
- Recovery runbook: `docs/runbooks/vps-deploy-evidence-timeout-recovery-runbook.md`.

## Evidence

Optional ci/vps artifacts (auditability, non-blocking): `ent-1-4-audit-check.json`,
`queue-worker-runtime-check.json`, `runtime-hardening-check.json`.
