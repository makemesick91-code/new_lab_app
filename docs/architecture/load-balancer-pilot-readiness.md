# LB-1 — Load Balancer Pilot Readiness

## Purpose

Give DaengtisiaMS a read-only, non-destructive way to audit whether the app
can safely sit behind a reverse proxy/load balancer, without forcing any
production traffic architecture change today. This is a **foundation/pilot**
sprint — readiness auditing plus a minimal health endpoint, not a real
multi-node cutover.

## Status: single VPS pilot

The application still runs as a single VPS pilot instance. Nothing about the
session/cache/queue/filesystem drivers, `APP_URL`, or HTTPS/secure-cookie
enforcement changed as part of this sprint. `lb:readiness-check` reports the
current posture; it does not change it. Trusted proxies stay opt-in via
`LB_TRUSTED_PROXIES` — left empty, Laravel's stock `TrustProxies` behavior is
untouched (trusts nothing), which is the safe default.

## What the command checks

- `LB_PILOT_ENABLED`, health endpoint enabled/path.
- Whether `LB_TRUSTED_PROXIES` is configured (informational — never forces a
  trust-all default).
- Forwarded-header, HTTPS, and secure-cookie *expectation* flags versus the
  actual `APP_URL` scheme and `session.secure` config.
- `session.driver`, `cache.default`, `queue.default` (same risky-driver
  signal STATELESS-1 already reports for horizontal scale).
- Whether STORAGE-1's object storage is enabled.
- STATELESS-1's runtime statelessness readiness status, surfaced alongside.

## Running the command

```bash
php artisan lb:readiness-check
php artisan lb:readiness-check --json
php artisan lb:readiness-check --strict
php artisan lb:readiness-check --header-smoke
```

- `--json` — machine-readable output for CI/scripts.
- `--strict` (alias `--fail-on-warning`) — treat a `warning` status as a
  failing exit code. Default (non-strict) only fails on `fail`.
- `--header-smoke` — simulates forwarded-header resolution **in-process**
  (a synthetic proxy IP is added to a copy of the trusted-proxy list for the
  simulation only; no real network call is made and no config is changed).
  Skipped with a reason when `LB_TRUSTED_PROXIES` is empty or
  `LB_HEADER_SMOKE_ENABLED=false`.
- `--url=` — reserved for future use; currently informational only.

## Health endpoint

- Path: `LB_HEALTH_ENDPOINT_PATH` (default `/health/lb`), route name
  `health.lb`.
- Public, unauthenticated, `GET`/`HEAD`.
- Response is always the same minimal shape:
  `{"status":"ok","service":"daengtisiams","check":"lb"}` (or `"status":"fail"`
  with HTTP 503 if the optional deep check fails).
- Never exposes `APP_KEY`, DB host/user, full env, route list, user/branch/
  patient data, KTP/NIK, or stack traces.
- Deep check (`LB_HEALTH_DEEP_CHECK`, default `false`) only pings the DB
  connection (`select 1`) — it never returns query results.
- Set `LB_HEALTH_ENDPOINT_ENABLED=false` to remove the route entirely (404).

## Trusted proxy / forwarded headers notes

- Laravel's built-in `Illuminate\Http\Middleware\TrustProxies` is already
  part of the framework's default global middleware stack; this sprint only
  wires an opt-in trusted-proxy list from `LB_TRUSTED_PROXIES` into
  `bootstrap/app.php` via `$middleware->trustProxies(at: ...)`.
- Empty `LB_TRUSTED_PROXIES` (default) means the call is skipped entirely —
  behavior is identical to before this sprint (no proxy trusted, forwarded
  headers ignored).
- When a real reverse proxy/load balancer is introduced, set
  `LB_TRUSTED_PROXIES` to its explicit IP/CIDR — never `*` in production.

## HTTPS termination / secure cookie notes

- `LB_EXPECT_HTTPS` / `LB_EXPECT_SECURE_COOKIES` are advisory expectation
  flags only. Setting them surfaces a warning if `APP_URL`'s scheme or
  `SESSION_SECURE_COOKIE` don't line up — nothing is auto-enabled.
- Only flip `SESSION_SECURE_COOKIE`/`APP_URL` to `https` after confirming the
  proxy actually terminates TLS end-to-end; verify with the readiness command
  and a manual request first.

## Session / cache / queue requirement for future multi-node

Same guidance as STATELESS-1: `file` session/cache and `sync` queue are
acceptable for this single VPS pilot but must move to a shared backend
(`database`/`redis`, async queue) before real multi-node instances sit behind
the load balancer.

## Relationship to STORAGE-1 and STATELESS-1

LB-1 surfaces STORAGE-1's object storage `enabled` flag and STATELESS-1's
runtime readiness `status` alongside its own checks but does not change
either. LB-1 depends on STATELESS-1 being GO (see
`config/foundation_roadmap.php`); STORAGE-1/STATELESS-1 governance rules are
unchanged by this sprint.

## Deploy notes

- Run `lb:readiness-check` (optionally `--header-smoke`) after any deploy, as
  part of the same smoke checklist as `runtime:stateless-readiness-check` and
  `storage:object-readiness-check`.
- The command and health endpoint are safe to run/hit repeatedly and safe to
  run on the VPS.

## Rollback notes

This sprint adds only new config/controller/service/command/docs files and a
small opt-in `bootstrap/app.php` addition (skipped when `LB_TRUSTED_PROXIES`
is empty) — nothing existing was modified in a way that changes runtime
behavior by default. Rolling back is a matter of checking out the previous
commit/tag; no migration to reverse.

## Next recommended sprint

`REPLICA-1` — Read Replica Readiness, gated on LB-1 GO (see
`config/foundation_roadmap.php`).
