# OBS-2 — Centralized Logging & Error Tracking Readiness

## Purpose

OBS-2 builds the foundation for centralized log collection and error
tracking so DaengtisiaMS can later plug in a log collector (Loki/ELK) and an
error tracker (Sentry/Bugsnag/self-hosted) with minimal risk. This sprint is
**readiness only** — it does not install, enable, or send data to any
external vendor. It builds directly on top of OBS-1's request id /
correlation id foundation.

## Status: single VPS pilot

- Centralized logging: **disabled** (`CENTRAL_LOGGING_ENABLED=false`).
- Error tracking: **disabled** (`ERROR_TRACKING_ENABLED=false`).
- `php artisan obs:pipeline-readiness-check` reports
  `external_logging_not_enabled` / `error_tracking_not_enabled` with decision
  `GO` — this is the expected, safe state for the current single VPS pilot.
- Laravel's default log channel and stack are untouched by this sprint.

## Centralized logging readiness behavior

- Config: `config/observability_pipeline.php` → `central_logging`.
- OFF by default. When enabled without a driver/endpoint configured, the
  readiness check reports a `warning` (or a `fail` under `--strict` / when
  the persistent `CENTRAL_LOGGING_STRICT` flag is on).
- The service only ever reports **booleans** for endpoint/API key presence
  (`central_logging_endpoint_configured`,
  `central_logging_api_key_configured_as_boolean_only`) — the actual values
  are never printed, logged, or included in the governance summary.
- `CENTRAL_LOGGING_SEND_PII` must stay `false`. If it is ever `true`, the
  readiness check fails immediately (governed by
  `OBS_PIPELINE_FAIL_ON_PII_RISK`, default `true`), independent of
  `--strict`.

## Error tracking readiness behavior

- Config: `config/observability_pipeline.php` → `error_tracking`.
- OFF by default (`ERROR_TRACKING_ENABLED=false`, sample rate `0.0`).
- Same missing-driver/DSN and PII-risk rules as centralized logging above;
  DSN presence is reported as a boolean only
  (`error_tracking_dsn_configured_as_boolean_only`).
- `--error-smoke` is opt-in and skipped by default
  (`OBS_PIPELINE_ERROR_SMOKE_ENABLED=false`). When run, it only builds a
  synthetic in-process exception object and writes one local log line — it
  never throws unhandled, and never calls out to a vendor.

## Why external vendors stay disabled by default

No monitoring vendor (Sentry/NewRelic/OpenTelemetry/ELK/Loki/Datadog) is
installed or contacted in this sprint. Turning centralized
logging/error-tracking on requires, at minimum: a reviewed destination
(driver + endpoint/DSN), a privacy/data-processing review confirming no
PII leaves the system, and a documented rollback path. Until then, OFF is
the only safe default for a clinical system handling patient data.

## Safe fields allowed in exported events (future rollout)

- Request id / correlation id (OBS-1)
- HTTP method, route name, path
- Log level, event name, timestamp
- Non-PII synthetic smoke markers

## Forbidden fields — never log or export

KTP/NIK, diagnosis/clinical detail, SOAP/handwriting/raw medical notes, scan
or consent document content, payment-sensitive payload (card/account
detail), full request payload, passwords, tokens, cookies, session ids,
`APP_KEY`, database/Redis credentials, or any other secret.

## Dependency on OBS-1

Every log/error event this pipeline eventually exports must carry the
request id / correlation id that OBS-1's
`App\Http\Middleware\AttachRequestCorrelationContext` already attaches to
the log context. `php artisan obs:pipeline-readiness-check` reads OBS-1's
readiness (`request_id_enabled`, `log_context_enabled`) directly and warns
if either is off.

## Running the readiness command

```
php artisan obs:pipeline-readiness-check
php artisan obs:pipeline-readiness-check --json
php artisan obs:pipeline-readiness-check --strict
php artisan obs:pipeline-readiness-check --log-smoke
php artisan obs:pipeline-readiness-check --error-smoke
```

- `--strict` / `--fail-on-warning`: exit non-zero on any warning.
- `--log-smoke`: writes one safe synthetic non-PII log line locally (no
  external call).
- `--error-smoke`: opt-in, default skipped; simulates a synthetic exception
  and logs it locally without throwing or sending external traffic.
- All modes are non-destructive: no log is cleared, no cache is flushed, no
  session is deleted, no external request is made by default.

## Rollout plan (future sprint, not this one)

1. Complete a privacy/data-processing review for the chosen log
   collector/error tracker.
2. Configure driver + endpoint/DSN via environment/config only (never
   hardcoded).
3. Turn on `CENTRAL_LOGGING_ENABLED`/`ERROR_TRACKING_ENABLED` behind a
   feature flag, starting with a canary/non-production destination.
4. Verify with `--strict` and `--error-smoke` before enabling for real
   traffic; add retention/access-control/redaction policy documentation.
5. Add alert thresholds and on-call routing in OBS-3/MON-1.

## Rollback

- Set `CENTRAL_LOGGING_ENABLED=false` and `ERROR_TRACKING_ENABLED=false`
  (or remove the vendor config) and clear config cache
  (`php artisan config:clear && php artisan config:cache`). No code
  rollback is required because the pipeline is inert while disabled.

## Relationship to prior foundation sprints

Builds on STORAGE-1 (object storage readiness), STATELESS-1 (runtime
portability), LB-1 (load balancer pilot), REPLICA-1 (read replica
readiness), CACHE-1 (Redis readiness), and OBS-1 (request id / correlation
id). None of those governance chains are modified by OBS-2; this sprint
only adds a new, informational `observability_pipeline_governance` section
to `php artisan architecture:foundation-governance-summary`.

## Deploy notes

Deployed via the standard VPS pilot runbook: backup DB, pull the GO tag,
`composer install --no-dev`, `npm ci && npm run build`,
`php artisan migrate --force` (no migration needed for this sprint — config
and code only), Laravel cache rebuild, permission reset, restart
`php8.3-fpm`/reload nginx, then run the smoke command list below.

## Next recommended sprint

OBS-3 / MON-1 — dashboards, alert thresholds, and (if approved after a
privacy review) the first real centralized logging/error-tracking vendor
enablement.
