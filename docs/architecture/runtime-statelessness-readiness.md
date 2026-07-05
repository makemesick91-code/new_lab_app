# STATELESS-1 — Runtime Statelessness & Deploy Portability Readiness

## Purpose

Give DaengtisiaMS a read-only, non-destructive way to audit how ready the
running application is for multi-instance / load-balanced / containerized
deploy, without forcing any driver change today. This is a **foundation**
sprint: readiness auditing, not a runtime migration.

## Status: single VPS pilot

The application still runs as a single VPS pilot instance. Nothing about
session/cache/queue/filesystem/log drivers changed as part of this sprint.
`runtime:stateless-readiness-check` reports the current posture; it does not
change it.

## What the command checks

- `session.driver`, `cache.default`, `queue.default`, `filesystems.default`,
  `logging.default`, `app.env`/`app.debug`.
- Whether `object_storage` (STORAGE-1) is enabled and its readiness status.
- Whether the required local writable paths
  (`storage/framework/cache`, `storage/framework/sessions`,
  `storage/framework/views`, `storage/logs`, `bootstrap/cache`) exist and are
  writable.
- Drivers considered risky for horizontal scale: session/cache driver
  `file`, queue connection `sync`. These produce a `warning` status, not a
  failure, because they are acceptable for a single VPS pilot.
- Local log channels (`single`/`daily`/`stack`) are noted as acceptable for a
  single VPS pilot with a recommendation to plan centralized logging before
  scale-out.

## Running the command

```bash
php artisan runtime:stateless-readiness-check
php artisan runtime:stateless-readiness-check --json
php artisan runtime:stateless-readiness-check --strict
php artisan runtime:stateless-readiness-check --write-test
```

- `--json` — machine-readable output for CI/scripts.
- `--strict` (alias `--fail-on-warning`) — treat `warning`/`ready_single_node`
  status as a failing exit code. Default (non-strict) only fails on `fail`
  (missing writable path or write-test failure).
- `--write-test` — writes a small file to the allowed healthcheck disk/path
  (`STATELESS_HEALTHCHECK_DISK` / `STATELESS_HEALTHCHECK_PREFIX`, default
  `local` disk under `healthchecks/stateless/`), reads it back to verify
  content, then deletes it. Nothing else on disk is touched.

`php artisan deploy:portability-check` is a thin alias for the same check,
so deploy pipelines have a `deploy:*`-named entry point.

## Allowed runtime writable paths

Only `storage/` and `bootstrap/cache/` may be written to at runtime
(`STATELESS_ALLOWED_LOCAL_WRITE_PATHS` in the environment example file,
default `storage,bootstrap/cache`). New features must not write durable user
files anywhere else on local disk — use the storage abstraction / STORAGE-1
object storage readiness path instead.

## Session / cache / queue recommendations for future scale

| Driver aspect | Current (pilot)  | Recommendation before horizontal scale |
|---------------|-------------------|------------------------------------------|
| Session       | see command output | Move to `database` or `redis` if currently `file`. |
| Cache         | see command output | Move to `database` or `redis` if currently `file`. |
| Queue         | see command output | Move to an async connection (`database`/`redis`) with a dedicated worker if currently `sync`. |
| Logs          | local files       | Plan centralized/aggregated logging (syslog, external shipper) ahead of scale-out. |

The command reports the actual current driver values — this table is
generic guidance, not a claim about the current configuration.

## Relationship to STORAGE-1

STATELESS-1 reads STORAGE-1's `object_storage` readiness status
(`app/Support/Storage/ObjectStorageReadinessService`) but does not enable it
and does not change any file's location. Durable-file readiness is STORAGE-1's
concern; STATELESS-1 audits the rest of the runtime (session/cache/queue/
writable paths) and surfaces STORAGE-1's status alongside it for a single
combined picture.

## Deploy notes

- Run `runtime:stateless-readiness-check` (optionally `--write-test`) after
  any deploy, before and after cache rebuild, as part of the smoke checklist.
- The command is safe to run repeatedly and safe to run on the VPS.

## Rollback notes

This sprint adds only new config/service/command/docs files — nothing
existing was modified in a way that changes runtime behavior. Rolling back is
a matter of checking out the previous commit/tag; no migration to reverse.

## Next recommended sprint

`LB-1` — Load Balancer Pilot, gated on STATELESS-1 GO (see
`config/foundation_roadmap.php`).
