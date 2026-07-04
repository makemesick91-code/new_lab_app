# DQ-3.1 — Approved Repair Execution — Evidence

## Sprint status

| Field | Value |
| --- | --- |
| Status | **COMPLETE / DEPLOYED / SMOKE PASS** |
| Decision | **WATCH** — approved mapping file missing; no production mutation |
| Case | **A** (mapping missing) |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Local HEAD | `30c55b7` |
| VPS HEAD | `765dffe` (hotfix included; no exact tag on HEAD) |
| GO tag (initial) | `dq-3-1-manual-review-repair-ambiguous-batch-rows-go` |
| Code changed this run | **No** |

## Local precheck

| Check | Result |
| --- | --- |
| Hotfix `765dffe` on base | **YES** |
| `inventory:ambiguous-batch-review-pack` | exists |
| `inventory:repair-ambiguous-batch-links` | exists |
| `php artisan test --filter=Dq31` | 19 passed |
| `php artisan test --filter=Dq3SourceDocumentBatch` | 15 passed |
| `php artisan test --filter=Dq2BatchGovernance` | 11 passed |
| `./vendor/bin/pint --dirty` | PASS |
| `git diff --check` | clean (CRLF warnings only) |

## VPS pre-repair audits (2026-07-04T05:14:41+00:00)

| Audit | Result |
| --- | --- |
| DQ-1 | **GO** (20 PASS, 0 WARN, 0 FAIL) |
| DQ-2 | **WATCH** (8 PASS, 2 WARN — DQ2-BATCH-007 transfer, DQ2-BATCH-009 opname) |
| DQ-3 | **WATCH** (7 PASS, 3 WARN — 4 missing, 4 ambiguous) |
| DQ-3.1 review pack | **WATCH** — 4 ambiguous rows |

### Ambiguous rows (unchanged)

| Type | Item ID | Product | Document | Reason | Candidates |
| --- | --- | --- | --- | --- | --- |
| transfer | 2 | 37 (ASEPTIC GEL) | TRF-202606-G8WBFF | OUT/IN batch lineage disagrees | batch 16 (OUT), 17 (IN) |
| transfer | 12 | 42 (CAIRAN SPIRTUS) | TRF-202606-RGHRWB | OUT/IN batch lineage disagrees | batch 18 (OUT), 19 (IN) |
| opname | 5 | 36 (ALKOHOL) | OPN-202606-KGGGTB | No deterministic recovery | none |
| opname | 8 | 36 (ALKOHOL) | OPN-202606-ZNILIP | No deterministic recovery | none |

## Fresh review pack (regenerated)

| Path | Size | Generated |
| --- | --- | --- |
| `storage/app/backups/deploy/dq31/dq31_review_pack_20260704-051451.json` | **7.1K** | 2026-07-04T05:14:51+00:00 |

Prior pack still present: `dq31_review_pack_20260704-050710.json` (7.1K).

## Approved mapping validation

| Item | Value |
| --- | --- |
| Expected path | `storage/app/backups/deploy/dq31/dq31_approved_mapping.csv` |
| File exists | **NO** |
| Dry-run | **N/A** (blocked — no mapping) |
| DB backup (pre-execute) | **N/A** |
| Execute | **NOT EXECUTED** per NSF-R024 |
| Production mutation | **NONE** |

Template: `docs/templates/dq-3-1-ambiguous-batch-repair-mapping-template.csv`

Required columns: `source_type`, `source_item_id`, `approved_inventory_batch_id`, `approval_reference`, `approved_by`, `approved_at`, `reason`, `expected_product_id`, `expected_document_id`, `expected_current_inventory_batch_id`, `notes`

## Post-repair audits

Not applicable — execute blocked. Pre-repair state unchanged:

| Audit | Result |
| --- | --- |
| DQ-1 | **GO** |
| DQ-2 | **WATCH** |
| DQ-3 | **WATCH** |
| DQ-3.1 | **WATCH** (4 ambiguous) |
| Foundation summary | NSF WATCH \| DMO WATCH \| Combined **WATCH** |

## VPS smoke (precheck only)

| Check | Result |
| --- | --- |
| `php artisan about` | OK (Laravel 12.61.0, PHP 8.3.6, pilot) |
| `php artisan migrate:status` | All migrations through `2026_07_04_140001` — Ran |
| Services restart | **N/A** (no execute) |

## Risks

- 4 ambiguous source-document rows remain; DQ-2/DQ-3/DQ-3.1 stay WATCH.
- Transfer rows require owner decision on OUT vs IN batch (16 vs 17; 18 vs 19).
- Opname rows have no candidate batches — owner must supply correct `approved_inventory_batch_id` from physical/lot records; do not guess.
- Repair without approved mapping would violate NSF-R024.

## Final decision

**WATCH** — playbook and hotfix deployed; repair blocked pending owner-approved `dq31_approved_mapping.csv`.

## Next recommended action

1. Owner reviews `dq31_review_pack_20260704-051451.json` on VPS.
2. Fill `storage/app/backups/deploy/dq31/dq31_approved_mapping.csv` (4 rows) with approval metadata.
3. Transfer `reason` must mention OUT/IN resolution; opname `reason` must mention manual approval.
4. Re-run: dry-run → DB backup → execute → post-audit per DQ-3.1 playbook.


## Approved Repair Execution — Final GO

Date: 2026-07-04 05:50 UTC  
VPS path: /var/www/asia-dental-lab-v2  
VPS HEAD: 765dffe  
Backup before execute: storage/app/backups/deploy/pre_dq31_approved_repair_20260704-054722.sql, 583K  
Mapping file: storage/app/backups/deploy/dq31/dq31_approved_mapping.csv

### Repair Result
- Rows submitted: 4
- Applied: 4
- Skipped: 0
- Errors: 0

### Applied Mapping
- transfer #2 -> inventory_batch_id 16
- transfer #12 -> inventory_batch_id 18
- opname #5 -> inventory_batch_id 2
- opname #8 -> inventory_batch_id 2

### Post Audit
- DQ-1: GO, 20 PASS, 0 WARN, 0 FAIL
- DQ-2: GO, 10 PASS, 0 WARN, 0 FAIL
- DQ-3: GO, 10 PASS, 0 WARN, 0 FAIL
- DQ-3.1: GO, 0 ambiguous rows
- Foundation: NSF WATCH | DMO WATCH | Combined WATCH

### Decision
DQ chain final decision: GO.
Foundation remains WATCH due to non-DQ NSF/DMO items.
