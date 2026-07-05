# CACHE-1 — Redis Shared Cache & Session Readiness

## Purpose

Build a readiness foundation for a future Redis-backed shared cache and shared
session store — **without** switching production cache/session/queue drivers
today. This is a foundation/audit sprint, not a Redis cutover.

## Status on the single VPS pilot

- `CACHE_STORE=file`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database` —
  **unchanged**.
- `CACHE_REDIS_EXPECTED=false` by default, so `cache:redis-readiness-check`
  reports `redis_not_expected` / `GO` and exits `0`.
- Redis is not required to be installed for this sprint to be GO. If no
  Redis client (`ext-redis` or `predis`) is present, the readiness/connect/
  TTL/lock checks report `unavailable` and are safely skipped.

## What the command checks

`php artisan cache:redis-readiness-check` reports (never logs the real
Redis password, only a boolean "configured" flag):

- `app_env` / `app_debug_safe`
- `cache_store`, `session_driver`, `queue_connection` (current drivers)
- `redis_expected`, `redis_connection`, `redis_client_available`
- `redis_host_configured`, `redis_port_configured`,
  `redis_password_configured_as_boolean_only`
- `connect_test_status`, `ttl_test_status`, `lock_test_status`
  (`skipped` / `unavailable` / `passed` / `failed`)
- `healthcheck_prefix`
- `warnings` and `recommendations`
- overall `status` and `decision`

### Status values

`single_node_ready`, `redis_not_expected`, `redis_config_ready`,
`redis_connect_ready`, `warning`, `fail` → `decision` is `GO`,
`GO_WITH_WARNINGS`, or `NO_GO`.

## How to run it

```
php artisan cache:redis-readiness-check
php artisan cache:redis-readiness-check --json
php artisan cache:redis-readiness-check --strict
php artisan cache:redis-readiness-check --connect-test
php artisan cache:redis-readiness-check --ttl-test
php artisan cache:redis-readiness-check --lock-test
php artisan cache:redis-readiness-check --fail-on-warning
```

- `--strict` only escalates to a failing exit code when Redis is *expected*
  (`CACHE_REDIS_EXPECTED=true`) but misconfigured or unreachable. It never
  fails the single-VPS default.
- `--connect-test` performs a read-only `PING` against the configured Redis
  connection.
- `--ttl-test` performs one `SET`/`GET`/`DEL` on a single unique key under
  the healthcheck prefix, with a short TTL.
- `--lock-test` performs one `SET ... NX EX` (acquire) then a
  token-checked `DEL` (release) on a single unique lock key.
- `--fail-on-warning` exits non-zero on any warning, including on the
  single-VPS pilot informational warnings.

## Config flags (no secret values)

See `config/cache_scale.php` and the `CACHE_REDIS_*` / `REDIS_*` keys in
`.env.example`. Key flags: `CACHE_REDIS_READINESS_ENABLED`,
`CACHE_REDIS_EXPECTED`, `CACHE_REDIS_STRICT`, `CACHE_REDIS_CONNECTION`,
`CACHE_REDIS_SESSION_CONNECTION`, `CACHE_REDIS_HEALTHCHECK_PREFIX`,
`CACHE_REDIS_TTL_SECONDS`, `CACHE_REDIS_EXPECT_CACHE_STORE`,
`CACHE_REDIS_EXPECT_SESSION_DRIVER`, `CACHE_REDIS_EXPECT_LOCKS`,
`CACHE_REDIS_ALLOW_SINGLE_VPS_WARNINGS`.

## Cache/session separation principle

`config/database.php` now defines three Redis logical connections:
`default` (`REDIS_DB`), `cache` (`REDIS_CACHE_DB`), and `session`
(`REDIS_SESSION_DB`) — separate logical databases so cache, session, and
general Redis usage never collide. `config/session.php`/`config/cache.php`
are **not** repointed to Redis by this sprint.

## TTL / prefix / key safety

All healthcheck keys are created under `CACHE_REDIS_HEALTHCHECK_PREFIX`
(default `healthchecks:cache`) with a random UUID suffix and a short TTL
(default 30s). The service only ever deletes the exact key it created —
never a wildcard, and never `FLUSHDB`/`FLUSHALL`.

## Distributed lock notes

The lock healthcheck uses `SET key token EX ttl NX` to acquire and only
releases (`DEL`) after verifying the stored value matches its own token —
the same acquire/verify/release pattern any future distributed-lock feature
must follow (see CACHE-R006).

## Cache invalidation notes

Before any read-heavy caching is enabled for RME, payment, inventory stock,
or reports, an explicit invalidation design is required (CACHE-R007).
**Forbidden:** using Redis as the source of truth for stock, invoices,
payments, medical records/odontogram data, or audit logs (CACHE-R011) —
PostgreSQL remains authoritative.

## Relationship to STORAGE-1 / STATELESS-1 / LB-1 / REPLICA-1

CACHE-1 readiness complements the existing chain: STATELESS-1 already
reports `session/cache` drivers as part of horizontal-scale readiness;
LB-1's readiness surfaces the same drivers; REPLICA-1 covers reads at the
database layer. CACHE-1 adds a Redis-specific, non-destructive audit layer
without touching any of those existing readiness services or their GO
status.

## Deploy notes

No migration. No driver change. Deploy follows the existing VPS runbook
(backup → pull tag → composer/npm → `migrate --force` → Laravel cache
rebuild → permission fix → restart php-fpm/nginx).

## Rollback notes

Rollback is a plain `git checkout` of the previous tag/commit plus the
standard Laravel cache rebuild and service restart — no migration to
reverse, since this sprint adds no schema changes.

## Next recommended sprint

Enable Redis for cache and/or session in a dedicated sprint with an
explicit canary rollout and rollback plan (CACHE-R012), once `--connect-test`,
`--ttl-test`, and `--lock-test` have been verified against a real Redis
instance in the target environment.
