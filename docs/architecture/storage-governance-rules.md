# STORAGE Governance Rules (STORAGE-1)

These rules are surfaced (as an informational, non-blocking section) in
`php artisan architecture:foundation-governance-summary` under
`storage_governance.rules`, alongside the existing NSF/DMO/DQ rule chains.

| Rule | Statement |
|---|---|
| `STORAGE-R001` | New file upload/storage features must go through a storage abstraction/service, not scattered hardcoded `Storage::disk()` calls in controllers. |
| `STORAGE-R002` | Object storage must be private by default; a public URL is only exposed via a signed/controlled route, never a raw public disk URL. |
| `STORAGE-R003` | A storage healthcheck command must exist and must be non-destructive (write/read/delete a small object only). |
| `STORAGE-R004` | Object storage secrets must never be logged, never stored in the database, and never appear as real values in docs or `.env.example`. |
| `STORAGE-R005` | Production/storage migrations must not delete or move existing files without an explicit, separate migration plan. |

## How each rule is enforced today

- **R001**: `App\Support\Storage\ObjectStorageReadinessService` is the single
  place that resolves the object disk; future upload features should call
  through a similar service rather than `Storage::disk('object')` directly
  in controllers.
- **R002**: the `object` disk in `config/filesystems.php` sets
  `'visibility' => 'private'`; no `url()`/public route is wired in this
  sprint.
- **R003**: `php artisan storage:object-readiness-check --write-test` is
  additive, non-destructive, and scoped to `OBJECT_STORAGE_HEALTHCHECK_PREFIX`.
- **R004**: `ObjectStorageReadinessService::missingEnvKeys()` reports key
  *names* only; `.env.example` ships with empty values; docs never contain
  real credentials.
- **R005**: STORAGE-1 does not move, copy, or delete any existing file. Any
  future migration of existing files needs its own reviewed plan/sprint.

## Source

Canonical rule catalog: `App\Services\Foundation\StorageGovernanceService::rules()`.
