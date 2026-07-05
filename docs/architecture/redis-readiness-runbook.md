# DaengtisiaMS — Redis Readiness Runbook (ENT-4)

**Status:** ACTIVE / LOCKED  
ENT-4 does not enable Redis in production. Use this runbook to verify readiness safely before any later driver switch.

## Safe Inspection

- Inspect `config/cache.php`, `config/database.php`, `config/session.php`, and `config/queue.php`.
- Check the environment file only for driver names and boolean readiness flags. Do not print secrets.
- Safe names to reference: `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_SESSION_DB`.
- Never print values for passwords, application keys, database passwords, tokens, cookies, or session payloads.

## Local Laravel Checks

Run:

```bash
php artisan cache:redis-readiness-check
php artisan cache:redis-readiness-check --json
php artisan foundation:cache-governance-check --json
php artisan foundation:roadmap-check --strict --json
```

Use `--connect-test`, `--ttl-test`, or `--lock-test` only when the target environment is known safe for a temporary non-PII health key.

## Redis Service Check

If `redis-cli` is installed and Redis is configured locally, run:

```bash
redis-cli ping
```

Do not run `FLUSHDB`, `FLUSHALL`, wildcard deletes, or broad key scans on production.

## Laravel Cache Store Readiness

- Confirm the active cache store from `config('cache.default')` or command output.
- If a temporary key test is approved, use a key shaped like `dms:pilot:health:ent4:temporary:v1`, a short TTL, and delete it immediately.
- Never use patient, payment, session, token, or credential data in the test payload.

## Session Driver Readiness

- Confirm `SESSION_DRIVER` and `SESSION_CONNECTION` without printing session values.
- Any future session switch to Redis must test login, logout, CSRF, branch context, cashier/payment flows, and RME finalization.
- Keep rollback to database/file safe mode documented before switching.

## Queue Compatibility

- Confirm `QUEUE_CONNECTION`.
- Redis queue use belongs to ENT-5/ENT-6 governance and must include retry, failed-job, idempotency, and outbox rules.
- ENT-4 must not switch queue runtime behavior.

## Rollback

Rollback to safe mode by reverting the driver variables to the previously verified database/file settings, clearing optimized config, and rebuilding Laravel caches:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

## VPS Smoke Checklist

- Verify exact deployed commit/tag.
- Run `php artisan about`.
- Run `php artisan foundation:roadmap-check --strict --json`.
- Run `php artisan cache:redis-readiness-check --json`.
- Confirm debug is off and maintenance mode is off.
- If Redis is unavailable and ENT-4 did not require Redis at runtime, record a pilot readiness warning rather than failing the app.
- Review recent Laravel logs without exposing secrets or PII.
