# DBPERF-2 — PgBouncer & PostgreSQL Runtime Tuning

## Objective

Add PgBouncer connection-pooling readiness governance and a PostgreSQL
runtime tuning audit foundation — readiness/audit-first, with **no**
production PgBouncer traffic cutover, **no** `DB_HOST`/`DB_PORT` change,
**no** `postgresql.conf` edit, and **no** PostgreSQL restart in this sprint.
Wired into CI evidence, release evidence/safety, the deploy script, and the
Foundation Governance Summary, mirroring the DBPERF-1 pattern.

## Baseline from DBPERF-1

DBPERF-1 (merged `aaa70de`, GO-tagged
`dbperf-1-postgresql-index-optimization-query-plan-audit-go`, deployed to
VPS) left the roadmap's `foundation_expansion` track with DBPERF-2 as
`next_recommended_sprint`. All foundation GO statuses (DQ, DMO, NSF,
ROADMAP, FEATURE_FLAGS, CACHE_GOVERNANCE, QUEUE_GOVERNANCE, IDEMPOTENCY,
OUTBOX, DB_PERFORMANCE, AUTOMATED_SMOKE, RELEASE_SAFETY, Combined) were GO
going into DBPERF-2.

## PgBouncer readiness policy

- `config/postgres_runtime_governance.php` is the single source of truth for
  PgBouncer and PostgreSQL runtime governance, consumed by
  `App\Services\Foundation\PostgresRuntimeGovernanceService` and
  `foundation:postgres-runtime-check`.
- PgBouncer being **installed is never required for GO** in DBPERF-2 —
  `pgbouncer_readiness.install_required_for_go` and `.service_required_for_go`
  are both `false`. A missing/inactive PgBouncer while production cutover is
  disabled is a non-blocking WATCH, never hidden as GO.
- `pgbouncer_readiness.production_routing_enabled` and `.app_cutover_allowed`
  are both `false`. `pgbouncer_readiness.required_before_cutover` lists the
  eight preconditions (backup, rollback plan, app compatibility audit,
  migration bypass policy, smoke, connection count baseline, error log watch,
  explicit owner approval) that gate any future cutover — see
  `docs/architecture/templates/pgbouncer-cutover-checklist.md`.

## Why production cutover is not done in DBPERF-2

Transaction-mode PgBouncer pooling changes session semantics (temp tables,
session `SET` variables, prepared-statement caching, advisory locks,
`LISTEN`/`NOTIFY`) in ways that are only safe once every one of those
features has been audited against the real running application and a tested
rollback path exists. DBPERF-2 performs the audit and builds the detection
tooling; it deliberately stops short of routing real traffic, matching the
roadmap's `DBPERF-2` card (`requires_design_first: true`,
`requires_rollback_plan: true`, `production_safety_rule: 'No PgBouncer
production routing without connection pool rollback plan.'`).

## App compatibility audit

`config/postgres_runtime_governance.php` → `app_compatibility_policy` lists
every feature that must be audited before pooling
(`must_audit`) and this sprint's read-only findings for each one
(`findings`) — see the config file for the full list. Headline findings:

- **Migrations must bypass the pooler.** `php artisan migrate` (DDL) must
  always connect directly to PostgreSQL, never through a transaction-mode
  pool — this is enforced as policy/documentation, not code, in DBPERF-2.
- **`DB::transaction()` is used extensively** across financial/inventory
  writes; this is compatible with transaction pooling as long as no request
  holds a transaction open across unrelated queries (not currently known to
  happen).
- **No known advisory locks, `LISTEN`/`NOTIFY`, or session-scoped temp
  tables** in the current codebase, but this must be re-verified immediately
  before any real cutover attempt (audits age quickly as code changes).
- **No long-running queue worker is enabled** (QUEUE-1 governance); a
  connection-pool policy must exist before one is enabled.

## Transaction pooling risks

- Native (unprepared) statement caching behaves differently under
  transaction pooling; Laravel's PDO layer defaults to emulated prepares,
  which reduces but does not eliminate risk — must be validated against a
  real pilot connection before cutover.
- `idle_in_transaction_session_timeout` is not currently enforced at the
  role/database level; a held-open transaction under transaction pooling can
  starve the pool. This is a **recommendation-only** finding in DBPERF-2 (see
  below), not applied.

## Migration bypass policy

`global_rules.migration_connections_must_not_use_transaction_pooler` is
`true` in governance config. In practice this means: if a PgBouncer pilot is
ever stood up, migrations keep using the direct PostgreSQL connection
(`DB_PORT=5432`), never the pooler port (`6432` in the pilot template). This
is documented in both the governance config and the cutover checklist.

## PostgreSQL runtime audit policy

`foundation:postgres-runtime-check` is read-only:

- By default it validates `config/postgres_runtime_governance.php`
  completeness, the four `foundation.db.pg_bouncer_*`/`postgres_runtime_*`
  feature flags, and whether the app's own DB connection config looks like it
  already routes through PgBouncer (port `6432` or a `pgbouncer` host hint)
  — no database introspection is required for a normal GO.
- `--include-db-stats` runs `SHOW <setting>` for each setting in
  `postgres_runtime_audit.settings` (version, `max_connections`,
  `shared_buffers`, `effective_cache_size`, `work_mem`,
  `maintenance_work_mem`, `checkpoint_timeout`, `max_wal_size`,
  `random_page_cost`, `effective_io_concurrency`,
  `default_statistics_target`, `statement_timeout`,
  `idle_in_transaction_session_timeout`, `lock_timeout`,
  `log_min_duration_statement`, `log_checkpoints`, `track_io_timing`,
  `shared_preload_libraries`), plus a `pg_stat_activity` connection-state
  count (active/idle/idle-in-transaction) scoped to the current database and
  `pg_stat_statements` availability. It never selects application row data
  or query text.
- `--include-pgbouncer-probe` detects a local PgBouncer binary
  (`command -v pgbouncer`), service (`systemctl is-active pgbouncer`), and a
  listener on the configured pilot port (`127.0.0.1:6432` by default) — all
  read-only shell probes, never printing admin credentials.

## Tuning recommendation policy (no-auto-apply)

`tuning_recommendation_policy.no_auto_apply` is `true`.
`PostgresRuntimeGovernanceService::buildRecommendations()` emits, per
setting: `current_value`, `recommendation` (rationale), `classification`
(`safe_documentation_only` | `requires_staging_test` |
`requires_maintenance_window` | `requires_capacity_baseline` |
`requires_restart` | `not_recommended`), `restart_required`, `risk`,
`rollback_note`, and `next_action`. Sizing-dependent settings
(`shared_buffers`, `effective_cache_size`, `work_mem`,
`maintenance_work_mem`, `max_connections`) are classified
`requires_capacity_baseline` rather than given a blind suggested value,
because DBPERF-2 does not read host RAM. No recommendation is ever applied —
`foundation.db.postgres_runtime_apply_enabled` stays `false`, and the
`ALTER SYSTEM SET` / `edit_postgresql_conf` / `restart_postgresql` actions
are all in `denied_actions`.

## Rollback plan

See `docs/architecture/templates/pgbouncer-cutover-checklist.md` items 7–9
for the env-change/rollback-env/restart-scope plan a future cutover must
follow: revert `DB_HOST`/`DB_PORT` to direct PostgreSQL, restart `php-fpm`
only (never PostgreSQL), and re-verify with
`foundation:postgres-runtime-check --include-pgbouncer-probe` and
`release:automated-smoke`.

## Release evidence integration

`config/release_evidence.php` requires `postgres-runtime-check.json` for
both the `ci` and `vps` profiles (optional for `local`).
`App\Services\Foundation\ReleaseEvidenceService::buildJobs()` runs
`foundation:postgres-runtime-check --json` (adding `--include-db-stats
--include-pgbouncer-probe` for the `vps` profile) and writes the safe JSON
artifact, following the same `BufferedOutput` capture pattern as every other
governance artifact.

## Release safety integration

`config/release_safety.php` → `required_pre_deploy_gates` includes
`foundation:postgres-runtime-check`. `foundation:release-safety-check` fails
if that command is unregistered, exactly like every other required gate — it
does **not** require PgBouncer installed for GO, only that the check runs
and reports GO/WATCH.

## Deploy gate integration

`.github/workflows/foundation-evidence-gates.yml` (`release_safety_gate` job)
runs `foundation:postgres-runtime-check` and writes
`storage/ci-evidence/postgres-runtime-check.json`; the `nsf10_release_evidence_gate`
job additionally captures it via `release:evidence-capture --profile=ci`.
`scripts/deploy-vps.sh` runs `foundation:postgres-runtime-check` with
`--include-db-stats` and `--include-pgbouncer-probe` (best-effort, `|| true`
on the probe so a missing PgBouncer never blocks deploy) and writes
`storage/release-evidence/latest/postgres-runtime-check.json`.

## GO / WATCH / NO-GO criteria

See `config/postgres_runtime_governance.php` → `go_criteria` /
`watch_criteria` / `no_go_criteria` for the full list. Summary:

- **GO** — config present/complete, no denied action detected, PgBouncer not
  required installed, both cutover/apply flags disabled, runtime audit safe.
- **WATCH** — PgBouncer probe not requested/not installed while cutover
  disabled, non-pgsql connection, or RAM-dependent sizing recommendations
  pending a capacity baseline. Always non-blocking for Combined Foundation.
- **NO-GO** — cutover or runtime-apply flag enabled without proof/approval,
  the app appears to already route through PgBouncer without approval
  evidence, or a denied action is detected.

## Next sprint

`config/foundation_roadmap.php` marks `DBPERF-2` and RPT-1 as `completed`;
the next `planned` item in priority order is **STORAGE-1 — Object Storage
Readiness**. RPT-1 completed as governance/readiness only: no auto refresh,
no report source switch, and no heavy materialized view refresh was introduced.
