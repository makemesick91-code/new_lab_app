# DBPERF-1 — PostgreSQL Index Optimization & Query Plan Audit — Deploy Evidence

**Status:** COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS — **GO**

See [`dbperf-1-postgresql-index-optimization-query-plan-audit.md`](../architecture/dbperf-1-postgresql-index-optimization-query-plan-audit.md)
for the full governance design.

## Final status
- Final status: COMPLETE — GO
- PR: [#176](https://github.com/makemesick91-code/new_lab_app/pull/176) — "DBPERF-1 PostgreSQL Index Optimization & Query Plan Audit"
- Merge commit: `aaa70de`
- GO tag: `dbperf-1-postgresql-index-optimization-query-plan-audit-go` (points at `aaa70de`)

## VPS deploy
- Previous HEAD: `4ce3e2d` (`queue-1-queue-idempotency-outbox-foundation-go`)
- Deployed HEAD: `aaa70de` (`dbperf-1-postgresql-index-optimization-query-plan-audit-go`)
- Backup path: `storage/app/backups/deploy/pre_dbperf1_20260705-014408.sql`
- Backup size: 617,561 bytes (604K)
- Backup verification result: GO (9/9 checks)
- Node/npm version: Node v20.20.2 / npm 10.8.2
- composer/npm/build/migrate result: `composer install` OK (lock unchanged, autoload regenerated); `npm ci` OK (3 pre-existing audit advisories, unrelated to this sprint); `npm run build` OK; `php artisan migrate --force` applied exactly 1 additive migration (`2026_07_05_200001_add_dbperf1_idempotency_status_expires_at_index`) — no other migrations pending, no destructive operation

## Governance results
- DB performance governance result: GO (7/7 checks) — driver `pgsql`, applied index candidates: 1, deferred candidates: 10
- DB stats / pg_stat result: GO — `index_count=461`, `pg_stat_user_indexes=yes`, `pg_stat_statements=yes` (available on VPS, unlike local dev where it is WATCH/unavailable)
- Applied indexes: `idx_dbperf1_idempotency_status_expires_at` on `sys_idempotency_keys (status, expires_at)`, created via `CREATE INDEX CONCURRENTLY IF NOT EXISTS` — supports `IdempotencyService::expireOld()`'s `WHERE status = reserved AND expires_at < now()` sweep
- Deferred candidates: all other target query families already covered by the pre-existing NSF-2 safe index pack (`idx_nsf2_patients_branch_is_active`, inventory movement composite index) and Sprint 68.1/68.2 read-path indexes (`trx_clinic_visits_visit_number_pattern_idx`, `trx_rme_payments_branch_paid_at_idx`, `trx_rme_invoices_active_receivable_order_idx`); the owner-dashboard daily payment trend aggregate (~118ms at 500K-payment scale) is deferred to RPT-1 (materialized view / summary foundation), not an indexing gap
- Query plan evidence result: `--include-query-plan-samples` confirmed real EXPLAIN-only plans (no ANALYZE, no row data) for `rme.visit_queue`, `cashier.receivable_list`, and `foundation.audit_governance_commands` sample queries, all using index scans
- Feature flags result: GO — 18 flags registered, 0 risky-enabled (no new flags this sprint)
- Cache governance result: GO (15/15 checks) — Redis runtime disabled (unchanged)
- Queue governance / idempotency / outbox result: GO / GO (0 records) / GO (0 records) — unchanged from QUEUE-1 baseline
- Release evidence result: GO — vps profile, 16/16 artifacts (13 required + 3 optional) captured and verified, including the new `db-performance-check.json` (78,046 bytes)
- Release safety result: GO (11/11 checks), vps profile
- Automated smoke result: GO — command-readiness (6/6); HTTP smoke (`automated-smoke-http.json`) captured during evidence capture
- DQ/DMO/NSF/ROADMAP/Combined status: DQ-1/DQ-2/DQ-3/DQ-3.1 all GO; DMO-2 GO (446 passed); NSF-6 GO (22/23 passed); ROADMAP GO (next recommended sprint: `DBPERF-2`); Combined Foundation GO

## CI
- CI checks (PR #176, run `28725641612`): NSF-R012 Quality Gate — pass (1m5s); NSF-R011 Critical Test Gate — pass (3m8s); NSF-9 Release Safety & Automated Smoke Gate — pass (49s); NSF-10 Release Evidence Gate — pass (50s); NSF-R011 Full Suite Gate — skipped (by design, PR-only skip)
- CI artifact evidence: `nsf-r012-quality-gate`, `nsf-r011-critical-test-gate`, `nsf-9-release-safety-gate`, `nsf-10-release-evidence` artifacts uploaded, including `db-performance-check.json`

## Files changed
26 files changed (1473 insertions, 15 deletions): new `config/db_performance_governance.php`; 1 additive migration (`sys_idempotency_keys` composite index); `App\Services\Foundation\DbPerformanceGovernanceService`; new console command `foundation:db-performance-check`; updates to `FoundationGovernanceSummaryService`, `ReleaseEvidenceService`, `ArchitectureFoundationGovernanceSummaryCommand`, `config/{foundation_governance,foundation_roadmap,release_evidence,release_safety}.php`, CI workflow, `scripts/deploy-vps.sh`; new architecture doc + this evidence doc; updates to `docs/architecture/{national-foundation-expansion-roadmap,nsf-application-rules,nsf-governance-deploy-gates}.md`; 3 new test files (`Dbperf1GovernanceIntegrationTest`, `DbPerformanceGovernanceTest`, `DbPerformanceIndexCandidateTest`) + 5 updated stale-roadmap-assertion tests.

## Warnings/risks
- None blocking. `npm audit` reports 3 pre-existing advisories (1 high, 2 critical) in dev/build tooling — unrelated to this sprint's scope and not newly introduced; not remediated here per DBPERF-1 scope (governance/audit only).
- Local `RELEASE_SAFETY`/`RELEASE_EVIDENCE` remain WATCH for the `local` profile only (no local evidence captured) — expected and non-blocking, matches prior sprints' behavior. `DB_PERFORMANCE` is WATCH locally only when `pg_stat_statements` is unavailable in the dev environment; on VPS it is a clean GO.

## Cleanup / no leftover process
- Local git status: clean on base branch, up to date with origin
- Open PRs: none (PR #176 merged and branch deleted)
- Recent GH runs: run `28725641612` (PR) completed successfully (all required jobs pass)
- Local stuck processes: none found
- VPS git status: clean, HEAD at GO tag `dbperf-1-postgresql-index-optimization-query-plan-audit-go`
- VPS stuck processes: none found; `php8.3-fpm`/`nginx` active; no `queue:work`/`queue:listen`/stuck migration/index-creation process running; no new ERROR/CRITICAL in `storage/logs/laravel.log` since deploy

## Next sprint
DBPERF-2 — PgBouncer & PostgreSQL Runtime Tuning.
