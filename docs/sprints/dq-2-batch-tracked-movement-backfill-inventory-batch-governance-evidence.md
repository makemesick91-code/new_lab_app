# DQ-2 Batch-tracked Movement Backfill — Deploy Evidence

**Sprint:** DQ-2  
**Date:** 2026-07-04  
**Final decision:** **WATCH** (deploy OK; DQ-1 GO; movement batch backlog closed; source-document item warnings remain)

## Git / release

| Item | Value |
| --- | --- |
| Feature branch | `feature/dq-2-batch-tracked-movement-backfill-inventory-batch-governance` |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| PR | [#164](https://github.com/makemesick91-code/new_lab_app/pull/164) |
| Merge commit | `b66435d` |
| GO tag | `dq-2-batch-tracked-movement-backfill-inventory-batch-governance-go` |

## VPS deploy

| Item | Value |
| --- | --- |
| Path | `/var/www/asia-dental-lab-v2` |
| Previous HEAD | `612d65b` (post DQ-1 evidence) |
| Deployed HEAD | `b66435d` (`dq-2-batch-tracked-movement-backfill-inventory-batch-governance-go`) |
| DB backup | `storage/app/backups/deploy/pre_dq2_20260704-043304.sql` |
| Backup size | 585,157 bytes (572K on disk) |

## Pre-backfill audits (VPS production)

| Audit | Result |
| --- | --- |
| DQ-1 | 19 PASS, 1 WARN — **DQ1-DATA-006**: 12 batch-tracked movements missing `inventory_batch_id` |
| DQ-2 | 7 PASS, 3 WARN, 0 FAIL — **DQ2-BATCH-002**: 12 missing; transfer/GR/opname item warnings |

## Dry-run summary

| Metric | Count |
| --- | --- |
| Scanned | 12 |
| Deterministic (`link_existing_batch`) | 2 |
| Legacy placeholder candidates | 10 |
| Ambiguous | 0 |
| Errors | 0 |

All 12 classified as safe (no ambiguous rows).

## Execute backfill summary

| Metric | Count |
| --- | --- |
| Scanned | 12 |
| Linked existing batch | 2 |
| Legacy placeholder batch | 10 |
| Created from source | 0 |
| Ambiguous skipped | 0 |
| Errors | 0 |

Export: `storage/app/architecture/storage/app/backups/deploy/dq2_backfill_20260704-043304.json` (path nesting — non-blocking; evidence captured).

## Post-backfill audits

| Audit | Result |
| --- | --- |
| DQ-1 | **20 PASS, 0 WARN, 0 FAIL — GO** (DQ1-DATA-006 PASS) |
| DQ-1 `--fail-on=error` | exit 0 |
| DQ-2 | **7 PASS, 3 WARN, 0 FAIL — WATCH** |
| DQ-2 `--fail-on=error` | exit 0 |
| Foundation summary | Combined **WATCH** (NSF/DMO pre-existing warnings) |

### Remaining DQ-2 warnings (non-movement source rows)

| Check | Count | Note |
| --- | --- | --- |
| DQ2-BATCH-007 | 2 | Transfer items missing `inventory_batch_id` |
| DQ2-BATCH-008 | 4 | Goods receipt items missing `inventory_batch_id` |
| DQ2-BATCH-009 | 2 | Stock opname items missing `inventory_batch_id` |

Movement ledger backlog (DQ1-DATA-006) is **closed**. Source-document item gaps are report-only WARN — deferred to future sprint.

## Build / migrate / cache

| Step | Result |
| --- | --- |
| `composer install --no-dev` | OK |
| `npm ci && npm run build` | OK (Node 18 EBADENGINE on `@tailwindcss/oxide` — non-blocking) |
| `php artisan migrate --force` | `2026_07_04_100001_add_dq2_batch_governance_provenance` DONE |
| Cache rebuild | OK |
| php8.3-fpm + nginx | OK |

## Smoke

| Check | Status |
| --- | --- |
| `php artisan about` | PASS (pilot, Laravel 12.61.0) |
| `curl -I http://127.0.0.1` | HTTP 302 |
| Post-backfill dry-run | 0 scanned (clean) |
| GO tag on HEAD | PASS |

## Governance rules added

- `inventory:batch-governance-audit` (read-only, DQ2-BATCH-001–010)
- `inventory:backfill-missing-batches` (dry-run default, `--execute` explicit)
- `InventoryMovementBatchGuard` on movement creation
- `trx_inventory_batch_backfill_logs` provenance table
- Integrated into `architecture:foundation-governance-summary`

## Warnings / risks

1. **Legacy placeholder batches** — 10 movements linked via `LEGACY-DQ2-*` governance batches (not manufacturer lots); documented in batch `notes` and `backfill_source`.
2. **Source-document item warnings** — GR/transfer/opname line items still missing batch id (8 rows); out of DQ-2 movement scope.
3. **npm EBADENGINE** — Node 18 on VPS; build succeeded.
4. **Export path nesting** — `--export=storage/app/backups/...` resolved under architecture root; fix deferred.

## Next recommended sprint

**DQ-3** — Source-document batch item backfill (GR/transfer/opname lines) or inventory batch governance phase-2 closure.
