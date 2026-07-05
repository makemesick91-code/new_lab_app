# DBPERF-1 — PostgreSQL Index Optimization & Query Plan Audit — Deploy Evidence

**Status:** PENDING — local implementation complete, awaiting PR/merge/GO tag/VPS deploy.

See [`dbperf-1-postgresql-index-optimization-query-plan-audit.md`](../architecture/dbperf-1-postgresql-index-optimization-query-plan-audit.md)
for the full governance design.

## Final status
- Final status: PENDING
- PR: TBD
- Merge commit: TBD
- GO tag: `dbperf-1-postgresql-index-optimization-query-plan-audit-go` (TBD)

## VPS deploy
- Previous HEAD: TBD
- Deployed HEAD: TBD
- Backup path: TBD
- Backup size: TBD
- Backup verification result: TBD
- Node/npm version: TBD
- composer/npm/build/migrate result: TBD

## Governance results
- DB performance governance result: TBD
- DB stats / pg_stat result: TBD
- Applied indexes: `sys_idempotency_keys (status, expires_at)` via `2026_07_05_200001_add_dbperf1_idempotency_status_expires_at_index`
- Deferred candidates: see `config/db_performance_governance.php` `index_candidate_policy` (all other target query families already covered by NSF-2 / Sprint 68.1/68.2; owner dashboard daily payment trend deferred to RPT-1)
- Query plan evidence result: TBD
- Feature flags result: TBD
- Cache governance result: TBD
- Queue governance / idempotency / outbox result: TBD
- Release evidence result: TBD
- Release safety result: TBD
- Automated smoke result: TBD
- DQ/DMO/NSF/ROADMAP/Combined status: TBD

## CI
- CI checks: TBD
- CI artifact evidence: TBD

## Files changed
TBD

## Warnings/risks
TBD

## Cleanup / no leftover process
TBD

## Next sprint
DBPERF-2 — PgBouncer & PostgreSQL Runtime Tuning.
