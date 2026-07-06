# Enterprise Operations Runbook (ENT-15)

Durable governance: `docs/architecture/enterprise-documentation-runbook-governance.md`.
Verified by: `php artisan foundation:enterprise-documentation-check --strict`.

## Purpose

Day-to-day operations of the DaengtisiaMS pilot: reading runtime health, using the
read-only Developer Console (ENT-7), checking the health endpoints (ENT-8), and
inspecting queue / idempotency / outbox posture (ENT-5/ENT-6) without changing
any business workflow. This runbook covers the **operations, developer_console,
queue_outbox, and health** topics.

## When to Use

- A stakeholder reports the app is slow or a page errors.
- You need to confirm the pilot is healthy after a deploy or during the day.
- You need to inspect failed jobs, pending jobs, or audit metadata.
- Before escalating an incident, to gather non-sensitive evidence.

## Prerequisites

- SSH access to the VPS pilot (`ssh daengtisiams-vps`) at `/var/www/asia-dental-lab-v2`.
- Super Admin (for the Developer Console at `GET /dev-console`).
- The application runtime is up (`php artisan up`).

## Safe Commands

```
php artisan about
php artisan foundation:health-check
php artisan foundation:developer-console-check
php artisan foundation:queue-retry-failed-job-check
php artisan foundation:idempotency-outbox-check
php artisan queue:failed
php artisan architecture:foundation-governance-summary
curl -s http://127.0.0.1/health/live
curl -s http://127.0.0.1/health/ready
curl -s http://127.0.0.1/health/lb
```

- Health endpoints stay minimal and non-sensitive (status + component names only).
- The Developer Console is read-only, permission-gated (`view_developer_console`),
  audited, and PII-masked; a guest hitting `/dev-console` is redirected to login.
- The queue worker is **not** enabled on the pilot by design; ENT-5/ENT-6 posture
  is verified as readiness, not active worker rollout.

## Forbidden Commands

Never run these on the pilot/production VPS — they destroy or reset data:

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- `php artisan schema:drop`
- `php artisan migrate:reset`
- Any raw SQL restore over the live database, any high-volume load test on the
  pilot, or any command that enables the queue worker without an approved rollout.

## Evidence

- Capture `foundation:health-check --json`, `developer-console-check.json`, and the
  governance summary as non-sensitive artifacts under
  `storage/release-evidence/latest/`.
- Never paste secrets, credentials, or KTP/NIK into an incident record.

## Rollback / Fallback

- Operations commands are read-only; there is nothing to roll back.
- If a deploy caused the incident, follow the
  [VPS Deploy & Rollback Runbook](vps-deploy-rollback-runbook.md).

## Troubleshooting

- `/health/ready` returns 503 → inspect the failing component in the JSON body
  (database is critical; cache/queue/storage are degraded-tolerant).
- Failed jobs present → `php artisan queue:failed`, then retry/forget per uuid
  following the queue-worker operations runbook (ENT-5).
- Console panel shows "unavailable" → the panel failed safely (per-panel
  try/catch); check `storage/logs/laravel.log` (masked tail).

## Smoke Verification

- `/health/live`, `/health/ready`, `/health/lb` return HTTP 200 non-sensitive JSON.
- `/dev-console` as a guest redirects to login (does not expose the console).
- `php artisan queue:failed` is empty.
- `php artisan about` shows env pilot, debug OFF, maintenance OFF.

## Security / PII Notes

- No full KTP/NIK is ever rendered, exported, or logged; the console masks
  13+-digit runs, credentials, tokens, and emails.
- Do not copy secrets or the environment file into any doc or ticket.

## Owner / Reviewer

- Owner: Platform / DevOps lead. Reviewer: Enterprise Foundation governance owner.

## Review Cadence

- Review each enterprise sprint (ENT-*) and at least quarterly.
