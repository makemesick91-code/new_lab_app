# PgBouncer Production Cutover Checklist (DBPERF-2 template)

This checklist gates any future production cutover to PgBouncer. **None of
these steps are performed as part of DBPERF-2** — DBPERF-2 delivers
readiness/audit governance only. This document is the required precondition
list referenced by `config/postgres_runtime_governance.php` →
`pgbouncer_readiness.required_before_cutover` and by the
`foundation.db.pg_bouncer_cutover_enabled` feature flag's `rollback_action`.

A cutover must not proceed until every item below is checked off and an
owner has given explicit written approval.

1. **Backup verified** — a fresh `pg_dump` backup exists and passes
   `foundation:backup-verify` (GO), taken immediately before the cutover
   attempt.
2. **Smoke baseline** — `release:automated-smoke --base-url=<prod>` is GO
   against the current direct-PostgreSQL setup, captured as a pre-cutover
   baseline for comparison.
3. **App compatibility audit** — every item in
   `config/postgres_runtime_governance.php` → `app_compatibility_policy.must_audit`
   has been re-verified against the current codebase (not just the DBPERF-2
   findings snapshot), with special attention to: persistent connections,
   prepared statements, temp tables, session variables, advisory locks,
   LISTEN/NOTIFY, transaction usage, queue workers.
4. **psql direct connection baseline** — a connection-count and latency
   baseline captured via `foundation:postgres-runtime-check --include-db-stats`
   against the direct PostgreSQL port, for before/after comparison.
5. **PgBouncer connection test** — a non-production (staging or isolated)
   PgBouncer instance, configured from
   `docs/architecture/templates/pgbouncer.ini.example`, has been
   connection-tested end-to-end by the application without errors.
6. **Migration bypass policy confirmed** — `php artisan migrate` and any
   other DDL path is confirmed to connect directly to PostgreSQL (never
   through the pooler), and this is documented in the deploy runbook.
7. **Env change plan** — the exact `DB_HOST`/`DB_PORT` (and any pool-mode
   related) `.env` changes are written down before touching production,
   reviewed by a second person.
8. **Rollback env plan** — the exact revert values for `DB_HOST`/`DB_PORT`
   are written down alongside the change plan, so rollback requires no
   improvisation.
9. **Restart scope confirmed** — the plan explicitly states that only
   `php-fpm` (or the app process manager) is restarted after an env change;
   **PostgreSQL itself is never restarted** for an application-side cutover.
10. **Error log watch window** — a defined window (e.g. 30–60 minutes) of
    active `storage/logs/laravel.log` + PostgreSQL log monitoring immediately
    after cutover, with a documented abort trigger (error rate/threshold).
11. **Release safety evidence** — `foundation:release-safety-check
    --profile=vps` and `release:evidence-capture --profile=vps` are GO
    immediately before and after the cutover attempt.
12. **Explicit owner approval** — a named owner has reviewed items 1–11 and
    given explicit written approval to proceed, timestamped in the deploy
    evidence doc for that cutover sprint.

## Non-negotiable safety rules

- No `DB_HOST`/`DB_PORT` change without every item above checked.
- No PostgreSQL restart as part of an application cutover.
- No `postgresql.conf` edit or `ALTER SYSTEM SET` as part of a cutover.
- No cutover without a verified, tested rollback path.
- `foundation.db.pg_bouncer_cutover_enabled` stays `false` until this
  checklist is complete and approval is recorded.
