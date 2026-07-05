# REPLICA-1 Read Replica Readiness

Status: readiness foundation only. The current single VPS pilot remains single-primary by default.

## Purpose

REPLICA-1 prepares DaengtisiaMS for a future PostgreSQL read replica without switching production reads today. It adds safe config, an optional `pgsql_read` connection, a non-destructive readiness command, and foundation governance.

It does not move data, change the default database connection, enable Laravel read/write splitting, or route writes to a replica.

## Commands

Run the readiness audit:

```bash
php artisan db:replica-readiness-check
php artisan db:replica-readiness-check --json
php artisan db:replica-readiness-check --strict
php artisan db:replica-readiness-check --connect-test
php artisan db:replica-readiness-check --lag-check
```

Default mode passes as `single_primary_ready` when `DB_REPLICA_ENABLED=false`.

`--connect-test` only runs read-only probes. `--lag-check` only runs PostgreSQL recovery/lag functions and is safely skipped when replica is disabled or unavailable.

## Config Flags

The sample environment includes:

```env
DB_REPLICA_ENABLED=false
DB_REPLICA_EXPECTED=false
DB_REPLICA_STRICT=false
DB_REPLICA_CONNECTION=pgsql_read
DB_REPLICA_MAX_LAG_SECONDS=5
DB_REPLICA_CONNECT_TIMEOUT_SECONDS=2
DB_REPLICA_QUERY_TIMEOUT_MS=1500
DB_REPLICA_EXPECT_READ_ONLY=true
DB_REPLICA_HEALTHCHECK_ENABLED=true
DB_REPLICA_HEALTHCHECK_DEEP=false
DB_REPLICA_ALLOW_REPORTING_READS=false
DB_REPLICA_FAIL_ON_LAG=false
DB_READ_HOST=
DB_READ_PORT=5432
DB_READ_DATABASE=
DB_READ_USERNAME=
DB_READ_PASSWORD=
DB_READ_SSLMODE=prefer
```

Do not commit real credential values. Command output reports password presence as a boolean only.

## Read/Write Safety

All write operations stay on the primary connection. A future scale sprint may route approved heavy read workloads through explicit service/repository paths after replica lag and branch isolation are verified.

Do not use replica reads for payment posting, stock movement, visit completion, finalization, or any workflow that depends on fresh transaction state.

## Replica Lag

When enabled, the command can audit `pg_is_in_recovery()` and `pg_last_xact_replay_timestamp()` using read-only SQL. Null lag is reported safely. Permission errors are reported as skipped warnings rather than forcing local/pilot failure.

## Relationship To STORAGE/STATELESS/LB

REPLICA-1 follows the same foundation posture as STORAGE-1, STATELESS-1, and LB-1:

- readiness first,
- no aggressive production switch,
- non-destructive smoke checks,
- explicit rollback,
- governance visible in `architecture:foundation-governance-summary`.

## Deploy Notes

For the current VPS pilot, keep `DB_REPLICA_ENABLED=false`. Run:

```bash
php artisan db:replica-readiness-check
php artisan architecture:foundation-governance-summary
```

The expected pilot result is `single_primary_ready` and `GO`.

## Rollback Notes

Rollback is config-only while no real read routing is enabled:

1. Set `DB_REPLICA_ENABLED=false`.
2. Clear/cache Laravel config.
3. Restart PHP-FPM if needed.
4. Keep the default `DB_CONNECTION` on the primary.

No destructive migration is part of REPLICA-1.

## Next Recommended Sprint

Plan a dedicated reporting/analytics read-routing sprint that selects specific repositories, verifies branch isolation, validates lag tolerance, and defines a runtime rollback switch before any live read traffic uses a replica.
