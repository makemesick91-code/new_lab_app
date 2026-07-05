# DBPERF-2 — PgBouncer & PostgreSQL Runtime Tuning — Deploy Evidence

**Status:** COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS — **GO**

See [`dbperf-2-pgbouncer-postgresql-runtime-tuning.md`](../architecture/dbperf-2-pgbouncer-postgresql-runtime-tuning.md)
for the full governance design.

## Final status
- Final status: COMPLETE — GO
- PR: [#177](https://github.com/makemesick91-code/new_lab_app/pull/177) — "DBPERF-2 PgBouncer & PostgreSQL Runtime Tuning"
- Merge commit: `23ca439`
- GO tag: `dbperf-2-pgbouncer-postgresql-runtime-tuning-go` (points at `23ca439`)

## VPS deploy
- Previous HEAD: `aaa70de` (`dbperf-1-postgresql-index-optimization-query-plan-audit-go`)
- Deployed HEAD: `23ca439` (`dbperf-2-pgbouncer-postgresql-runtime-tuning-go`)
- Backup path: `storage/app/backups/deploy/pre_dbperf2_20260705-032018.sql`
- Backup size: 619,916 bytes (606K)
- Backup verification result: GO (9/9 checks)
- Node/npm version: Node v20.20.2 / npm 10.8.2
- composer/npm/build/migrate result: `composer install` OK (lock unchanged, autoload regenerated); `npm ci` OK (added 160 packages; 3 pre-existing audit advisories, unrelated to this sprint); `npm run build` OK; `php artisan migrate --force` → "Nothing to migrate" (DBPERF-2 ships **no migration**, as designed — governance/config/service only)

## Governance results
- Postgres runtime governance result: GO (9/9 checks), driver `pgsql` — `--include-db-stats` GO with `connections: active=1 idle=0 idle_in_transaction=0 total=1`; `--include-db-stats --include-pgbouncer-probe` → WATCH (1 warning, non-blocking) because PgBouncer has a registered-but-inactive systemd unit and no listener on port 6432 while `pg_bouncer_cutover_enabled` stays `false` — exactly the expected non-blocking WATCH this sprint's governance is designed to report honestly
- PgBouncer readiness/probe result: `installed=yes` (systemd unit present from a prior environment state), `service_active=no`, `listener_active=no`, `binary_present=no` — PgBouncer is **not actually running**; governance correctly reports WATCH, not GO, and does **not** hide this
- App DB routing status: **direct PostgreSQL** — `app_cutover_detection.potential_cutover = false` (DB_PORT is `5432`, no `pgbouncer` host hint); no cutover flag enabled
- Runtime settings audit summary: `foundation:postgres-runtime-check --include-db-stats` read `max_connections`, `shared_buffers`, `effective_cache_size`, `work_mem`, `maintenance_work_mem`, `checkpoint_timeout`, `max_wal_size`, `statement_timeout`, `idle_in_transaction_session_timeout`, `log_min_duration_statement`, `track_io_timing`, `shared_preload_libraries`, etc. via safe `SHOW` commands plus a sanitized `pg_stat_activity` connection count — no query text or PII captured
- Recommendations/deferred tuning items: 12 recommendation-only entries emitted (`safe_documentation_only` / `requires_maintenance_window` / `requires_capacity_baseline`), each with `current_value`, `rationale`, `risk`, `rollback_note`, `next_action`; sizing-dependent settings (`shared_buffers`, `effective_cache_size`, `work_mem`, `maintenance_work_mem`, `max_connections`) are classified `requires_capacity_baseline` because DBPERF-2 does not read host RAM — deferred to a future sprint with an explicit capacity baseline; nothing applied
- DB performance governance result: GO (7/7 checks, unchanged from DBPERF-1) — driver `pgsql`, `--include-db-stats` → `index_count=461`, `pg_stat_user_indexes=yes`, `pg_stat_statements=yes`
- Feature flags result: GO — 21 flags registered (3 new this sprint: `pg_bouncer_cutover_enabled`, `postgres_runtime_tuning_recommendations`, `postgres_runtime_apply_enabled`, plus `pg_bouncer_readiness` updated to `rollout_status=implemented`), 0 risky-enabled
- Cache governance result: GO (15/15 checks) — Redis runtime disabled (unchanged)
- Queue governance / idempotency / outbox result: GO / GO (0 records) / GO (0 records) — unchanged from QUEUE-1/DBPERF-1 baseline
- Release evidence result: GO — vps profile, 17/17 artifacts (14 required + 3 optional) captured and verified, including the new `postgres-runtime-check.json` (12,952 bytes)
- Release safety result: GO (11/11 checks), vps profile
- Automated smoke result: GO — command-readiness (6/6); HTTP smoke against `http://127.0.0.1` returned `SMOKE-HTTP-HEALTH: HTTP probe to http://127.0.0.1/login returned healthy status 200` (7/7)
- DQ/DMO/NSF/ROADMAP/Combined status: DQ-1/DQ-2/DQ-3/DQ-3.1 all GO; DMO-2 GO (446 passed); NSF-6 GO (22/23 passed); ROADMAP GO (next recommended sprint: `RPT-1`); Combined Foundation GO

## CI
- CI checks (PR #177, run `28727306745`): NSF-R012 Quality Gate — pass (1m11s); NSF-R011 Critical Test Gate — pass (2m59s); NSF-9 Release Safety & Automated Smoke Gate — pass (46s); NSF-10 Release Evidence Gate — pass (46s); NSF-R011 Full Suite Gate — skipped (by design, PR-only skip)
- CI artifact evidence: `nsf-r012-quality-gate`, `nsf-r011-critical-test-gate`, `nsf-9-release-safety-gate`, `nsf-10-release-evidence` artifacts uploaded, including `postgres-runtime-check.json`

## Files changed
28 files changed (1604 insertions, 23 deletions): new `config/postgres_runtime_governance.php`; new `App\Services\Foundation\PostgresRuntimeGovernanceService`; new console command `foundation:postgres-runtime-check`; new PgBouncer pilot template + cutover checklist (`docs/architecture/templates/{pgbouncer.ini.example,pgbouncer-cutover-checklist.md}`); 3 new feature flags + 1 updated in `config/feature_flags.php`; updates to `FoundationGovernanceSummaryService`, `ReleaseEvidenceService`, `config/{foundation_governance,foundation_roadmap,release_evidence,release_safety}.php`, CI workflow, `scripts/deploy-vps.sh`; new architecture doc + this evidence doc; updates to `docs/architecture/{national-foundation-expansion-roadmap,nsf-application-rules,nsf-governance-deploy-gates}.md`; 3 new test files (`Dbperf2GovernanceIntegrationTest`, `PostgresRuntimeGovernanceTest`, `PgBouncerReadinessGovernanceTest`) + 6 updated stale-roadmap-assertion tests (next-sprint expectation moved `DBPERF-2` → `RPT-1`). No migration.

## Warnings/risks
- None blocking. `npm audit` reports 3 pre-existing advisories (1 high, 2 critical) in dev/build tooling — unrelated to this sprint's scope and not newly introduced; not remediated here per DBPERF-2 scope (governance/audit only).
- On VPS, `foundation:postgres-runtime-check --include-pgbouncer-probe` reports `installed=yes` because a `pgbouncer.service` systemd unit is registered (returns `inactive` rather than "unit not found") even though no `pgbouncer` binary is on `PATH` and nothing listens on port 6432. This is disclosed transparently as a non-blocking WATCH (`listener_active=no`, service not actually running) — production routing/cutover stays disabled, so this does not affect the GO decision and matches the sprint rule "do not hide WATCH status if PgBouncer is not installed or not active."
- Local `RELEASE_SAFETY`/`RELEASE_EVIDENCE` remain WATCH for the `local` profile only (no local evidence captured) — expected and non-blocking, matches prior sprints' behavior.
- No PgBouncer production cutover, no `DB_HOST`/`DB_PORT` change, no `postgresql.conf` edit, and no PostgreSQL restart occurred (confirmed: PostgreSQL `ActiveEnterTimestamp` on VPS remained `2026-07-04 00:05:49 UTC`, unchanged before/after this deploy).

## Cleanup / no leftover process
- Local git status: clean on base branch, up to date with origin
- Open PRs: none (PR #177 merged and branch deleted)
- Recent GH runs: run `28727306745` (PR) completed successfully (all required jobs pass); the push-triggered `Foundation Evidence Gates` run for the merge commit was still finishing at report time — this is the same automated CI pattern seen after every prior sprint's merge and is not a leftover local/manual process
- Local stuck processes: none found (only editor/IDE/session infrastructure running, no leftover `npm`/`composer`/`artisan`/`git`/`gh`/`psql`/`pgbouncer` deploy command)
- VPS git status: clean, HEAD at GO tag `dbperf-2-pgbouncer-postgresql-runtime-tuning-go` (`23ca439`)
- VPS stuck processes: none found; `php8.3-fpm`/`nginx`/`postgresql` active; PgBouncer confirmed not running (by design); no `queue:work`/`queue:listen`/stuck migration process; no new ERROR/CRITICAL log entries in `storage/logs/laravel.log` since deploy (zero log lines dated `2026-07-05`)

## Next sprint
RPT-1 — Materialized View + rpt_* Summary Foundation.
