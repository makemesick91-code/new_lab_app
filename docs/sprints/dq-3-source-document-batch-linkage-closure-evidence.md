# DQ-3 Source Document Batch Linkage Closure — Evidence

## Sprint Status

**COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS — Decision: WATCH**

4 dari 8 baris source-document berhasil di-backfill deterministik (GR). 4 baris transfer/opname tetap ambiguous (manual review).

## Release Artifacts

| Item | Value |
|------|-------|
| Feature branch | `feature/dq-3-source-document-batch-linkage-closure` |
| PR | [#165](https://github.com/makemesick91-code/new_lab_app/pull/165) |
| Merge commit | `7ff1d3c` |
| Hotfix commit | `e284d55` (backfill log collision + per-item transaction) |
| GO tag (initial) | `dq-3-source-document-batch-linkage-closure-go` |
| GO tag (deployed) | `dq-3-source-document-batch-linkage-closure-go.1` |

## VPS Deploy

| Item | Value |
|------|-------|
| VPS path | `/var/www/asia-dental-lab-v2` |
| Previous HEAD | `b66435d` (`dq-2-batch-tracked-movement-backfill-inventory-batch-governance-go`) |
| Deployed HEAD | `e284d55` (`dq-3-source-document-batch-linkage-closure-go.1`) |
| DB backup | `storage/app/backups/deploy/pre_dq3_20260704-045039.sql` (580K) |
| Migrate | `2026_07_04_120001_add_dq3_source_document_backfill_provenance` DONE |
| Composer | OK |
| NPM build | OK (Node EBADENGINE warning non-blocking) |
| Cache | config/route/view/event cached |
| Services | php8.3-fpm restarted, nginx reloaded |
| Smoke | `php artisan about` OK, HTTP 302 on `/` |

## Pre-Backfill Audits

| Audit | Decision | Notes |
|-------|----------|-------|
| DQ-1 | GO | 20 PASS |
| DQ-2 | WATCH | DQ2-BATCH-007: 2, DQ2-BATCH-008: 4, DQ2-BATCH-009: 2 missing |
| DQ-3 | WATCH | Total missing: 8 (4 GR + 2 transfer + 2 opname) |

## Dry-Run Summary

- Scanned: 8
- Deterministic `link_from_movement`: 4 (goods receipt items)
- Ambiguous: 4 (2 transfer OUT/IN disagree, 2 opname no unique movement match)

## Execute Summary

| Metric | Count |
|--------|-------|
| Scanned | 8 |
| Linked from movement | 4 |
| Ambiguous skipped | 4 |
| Errors | 0 |

## Post-Backfill Audits

| Audit | Decision | Remaining |
|-------|----------|-----------|
| DQ-1 | GO | All PASS |
| DQ-2 | WATCH | DQ2-BATCH-007: 2 transfer, DQ2-BATCH-009: 2 opname; DQ2-BATCH-008: PASS |
| DQ-3 | WATCH | DQ3-SRC-002: 2 transfer, DQ3-SRC-003: 2 opname; DQ3-SRC-001: PASS |

## Governance

`architecture:foundation-governance-summary` includes DQ-3 block after deploy.

## Warnings / Risks

1. **Transfer items 2 & 12** — OUT/IN movement batch lineage disagrees; tidak di-mutasi (ambiguous).
2. **Opname items 5 & 8** — tidak ada mapping movement unik; manual review diperlukan.
3. Hotfix `e284d55` diperlukan karena DQ-2 log unique pada `inventory_movement_id` memblokir insert log DQ-3 (transaksi rollback).

## Final Decision

**WATCH** — movement + GR lineage clean; 4 transfer/opname rows butuh keputusan manual sebelum GO penuh.

## Next Recommended Sprint

**DQ-3.1** — Manual review + repair playbook untuk transfer OUT/IN disagreement dan opname product-level-only rows; atau **DQ-4** inventory reporting reconciliation jika owner prioritas shift.
