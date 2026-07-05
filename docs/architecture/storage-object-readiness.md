# STORAGE-1 — Object Storage Readiness

Status: foundation only. OFF by default. No production file has been moved.

## Purpose

Prepare DaengtisiaMS to use an S3-compatible object storage backend (MinIO,
AWS S3, or any S3-compatible provider) in a future sprint, without forcing a
migration of existing local/public files today.

## What changed

- New `object` disk in `config/filesystems.php` (driver `s3`), reading only
  `OBJECT_STORAGE_*` env vars — it does not touch the existing `local`,
  `public`, or `s3` disks.
- New `config/object_storage.php`: `enabled` flag, `disk` name, `required_env`
  list, and `healthcheck_prefix`.
- New `App\Support\Storage\ObjectStorageReadinessService`: read-only
  readiness check, optional non-destructive write/read/delete healthcheck.
- New command `php artisan storage:object-readiness-check`.
- New governance rules STORAGE-R001..R005 (see
  `docs/architecture/storage-governance-rules.md`), surfaced in
  `php artisan architecture:foundation-governance-summary`.

## Env variables

All added to `.env.example` with empty/default-safe values — no real
secrets committed.

| Key | Purpose | Default |
|---|---|---|
| `OBJECT_STORAGE_ENABLED` | Master switch | `false` |
| `OBJECT_STORAGE_DISK` | Disk name to resolve | `object` |
| `OBJECT_STORAGE_DRIVER` | Filesystem driver | `s3` |
| `OBJECT_STORAGE_BUCKET` | Bucket name | empty |
| `OBJECT_STORAGE_REGION` | Region | `auto` |
| `OBJECT_STORAGE_ENDPOINT` | S3-compatible endpoint (MinIO, etc.) | empty |
| `OBJECT_STORAGE_URL` | Public base URL, if any | empty |
| `OBJECT_STORAGE_ACCESS_KEY_ID` | Access key | empty |
| `OBJECT_STORAGE_SECRET_ACCESS_KEY` | Secret key | empty |
| `OBJECT_STORAGE_USE_PATH_STYLE_ENDPOINT` | Path-style addressing (needed by most MinIO setups) | `true` |
| `OBJECT_STORAGE_THROW` | Throw on filesystem errors | `true` |
| `OBJECT_STORAGE_HEALTHCHECK_PREFIX` | Prefix for the healthcheck object | `healthchecks/daengtisiams` |

## Disabled mode (default / current pilot)

When `OBJECT_STORAGE_ENABLED=false`:

- `ObjectStorageReadinessService` never calls `Storage::disk('object')`.
- `storage:object-readiness-check` reports `disabled_ready` and exits `0`.
- No file, existing or new, is written to the object disk.

## Enabled mode (future sprint)

When `OBJECT_STORAGE_ENABLED=true`:

- Required env keys (`required_env` in `config/object_storage.php`) are
  validated. Missing keys are reported by name only — never by value.
- `storage:object-readiness-check --write-test` writes a small text object
  under `OBJECT_STORAGE_HEALTHCHECK_PREFIX`, reads it back, verifies the
  content, then deletes it. Nothing else on the disk is touched.
- `--strict` exits non-zero when misconfigured or when the write test fails,
  for use in CI/deploy gates.
- `--json` emits a machine-readable report for tooling.

## Deploy note

- Deploying this sprint's code does not change VPS behavior: the pilot
  keeps `OBJECT_STORAGE_ENABLED=false` (or unset) and all uploads continue
  to use the existing `local`/`public` disks exactly as before.
- Enabling object storage on the VPS requires an explicit follow-up change
  to `.env` with real bucket/credentials and is out of scope for STORAGE-1.

## Rollback note

- This sprint is purely additive (new config keys, new disk entry, new
  service/command, new docs). Rolling back means checking out the previous
  tag/commit; no migration or data rollback is required.

## Upload rule for new features

New file upload/storage code must go through a storage abstraction/service
(see STORAGE-R001 in `storage-governance-rules.md`) — do not scatter new
`Storage::disk(...)` calls directly in controllers.

## Future migration plan (not in this sprint)

A later sprint may:

1. Add an explicit, reviewed migration plan for moving specific file
   categories (e.g. new uploads only, or a dual-write period) to the
   `object` disk.
2. Never delete or move existing files as a side effect of enabling object
   storage (STORAGE-R005).
