# OBS-1 — Request ID, Correlation ID & Observability Readiness

## Purpose

OBS-1 builds the foundation for future centralized logging/APM/tracing
without installing an external monitoring vendor or sending sensitive data
anywhere. It gives every HTTP request a safe, bounded request id and
correlation id, attaches a minimal PII-free log context, and adds a
read-only readiness command so this posture can be audited going forward.

This is a **foundation-only** sprint — not a full APM/ELK/Sentry/NewRelic
rollout.

## Single VPS pilot status

Current pilot runtime: single VPS, local file/daily log channel, no
external log shipping. `php artisan obs:readiness-check` reports
`observability_ready`/GO (or `single_vps_ready`/GO before the middleware is
detected) — a local file/daily log channel is acceptable for this pilot but
is flagged as a non-blocking warning recommending centralized logging
before real multi-node scale-out (see LB-1/REPLICA-1/CACHE-1 foundations).

## Request ID / correlation ID behavior

- Middleware: `App\Http\Middleware\AttachRequestCorrelationContext`
  (registered globally on the `web` middleware group in `bootstrap/app.php`).
- On every request:
  - Resolves a `request_id` and `correlation_id` — from the inbound
    `X-Request-ID`/`X-Correlation-ID` headers **only if** the matching
    `OBSERVABILITY_TRUST_INBOUND_*` flag is `true` **and** the value passes
    strict validation (max length `OBSERVABILITY_MAX_ID_LENGTH`, characters
    limited to `A-Za-z0-9._:-`).
  - Otherwise generates a fresh UUID.
  - Sets `request_id`/`correlation_id` as request attributes.
  - Adds them (plus method/path/route name, each individually toggleable)
    to the log context via `Log::withContext()`.
  - Sets the response header (`X-Request-ID` by default) to the resolved
    request id.

## Inbound header trust default

Both `OBSERVABILITY_TRUST_INBOUND_REQUEST_ID` and
`OBSERVABILITY_TRUST_INBOUND_CORRELATION_ID` default to `false`. This
means a client cannot inject an arbitrary value into server-side log
correlation until this is explicitly enabled and strict validation is
confirmed to be enforced.

## Safe log context

Log context only ever contains: `request_id`, `correlation_id`, `method`,
`path`, `route` (each independently toggleable), and — only if explicitly
enabled and reviewed — `user_id`/`branch_id`. It never contains:

- Full request payload / query string
- Cookies, session id, CSRF/API tokens, passwords
- `APP_KEY`, DB/Redis credentials
- KTP/NIK, raw medical/SOAP notes, scanned document paths, consent content
- Raw payment/invoice sensitive payloads

## Running the readiness command

```bash
php artisan obs:readiness-check
php artisan obs:readiness-check --json
php artisan obs:readiness-check --strict
php artisan obs:readiness-check --header-smoke
```

- `--json` — machine-readable output.
- `--strict` (alias `--fail-on-warning`) — exit non-zero on any warning or
  missing critical config.
- `--header-smoke` — simulates an inbound request in-process (no real
  network call) carrying an invalid request id, and verifies the response
  still carries a safe, generated request id header.

## Relationship to STORAGE-1 / STATELESS-1 / LB-1 / REPLICA-1 / CACHE-1

`ObservabilityReadinessService` surfaces (but never mutates) the existing
object storage, runtime statelessness, load balancer, and database replica
readiness statuses so the combined operator picture stays in one place.
`ObservabilityGovernanceService` publishes OBS-R001..OBS-R012 into
`architecture:foundation-governance-summary` as its own
`observability_governance` section — informational only, alongside the
existing `storage_governance`, `stateless_governance`, `lb_governance`,
`database_replica_governance`, and `cache_redis_governance` sections, all
of which remain unchanged.

## Deploy notes

No log channel, session, cache, or queue driver is changed by this
sprint. No new dependency is added. No migration is required. Deploy is a
standard code deploy: pull, `composer install`, `npm run build` (only if
assets changed), Laravel cache rebuild, restart php-fpm, reload nginx.

## Rollback notes

Rollback is a plain code rollback: checkout the previous GO tag/commit,
reinstall dependencies, rebuild Laravel caches, restart php-fpm/nginx. No
migration rollback is required — OBS-1 does not add any migration.

## Next recommended sprint

OBS-2 / MON-1 — dashboards/alerting on top of this foundation, plus
queue/job correlation propagation (OBS-R008) once async workflows expand.
