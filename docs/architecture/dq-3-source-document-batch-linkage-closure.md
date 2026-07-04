# DQ-3 — Source Document Batch Linkage Closure

## Objective

Close DQ-2 WATCH items **DQ2-BATCH-007/008/009**: batch-tracked source-document item rows (goods receipt, stock transfer, stock opname) missing `inventory_batch_id` while movements may already be linked.

## DQ-2 Baseline

- Movement-level backlog closed by DQ-2 backfill.
- Remaining WATCH: source-document items without `inventory_batch_id`.
- DQ-3 fills source item linkage only — **no stock quantity mutation**.

## Source-Document Lineage Model

```text
source document item → inventory movement → inventory batch → audit governance
```

Supported tables:

| Document | Table | Column |
|----------|-------|--------|
| Goods receipt | `trx_goods_receipt_items` | `inventory_batch_id` |
| Stock transfer | `trx_stock_transfer_items` | `inventory_batch_id` |
| Stock opname | `trx_stock_opname_items` | `inventory_batch_id` |

## Commands

```bash
# Read-only audit
php artisan inventory:source-document-batch-audit
php artisan inventory:source-document-batch-audit --json
php artisan inventory:source-document-batch-audit --fail-on=error

# Dry-run first (default)
php artisan inventory:backfill-source-document-batches --dry-run
php artisan inventory:backfill-source-document-batches --dry-run --json

# Execute (requires backup + dry-run review)
php artisan inventory:backfill-source-document-batches --execute
```

## Mapping Rules

1. **Exact movement reference** — copy `movement.inventory_batch_id` when `inventory_movement_id` or deterministic header match exists.
2. **Header match** — product + document + quantity + movement type; only when exactly one candidate.
3. **Source batch fields** — match existing batch by `batch_number`/`lot_number`/`expiry_date` when real.
4. **Transfer** — preserve OUT/IN batch identity; skip when OUT/IN disagree.
5. **Opname** — map adjustment movement to counted item when unique.
6. **Ambiguous** — skip, report WATCH.

## Permanent Guardrail

`SourceDocumentBatchGuard` enforces batch on batch-tracked source items before finalize/movement creation (stock opname finalize; transfer already guarded). `InventoryMovementBatchGuard` remains final safety net.

## Deploy Procedure

1. DB backup
2. Pull GO tag, `composer install --no-dev`, `npm ci && npm run build`
3. `php artisan migrate --force`
4. DQ-1, DQ-2, DQ-3 audit + dry-run
5. `--execute` only when dry-run is deterministic
6. Post-backfill audits, cache rebuild, smoke

## Rollback

- Backfill is idempotent via `trx_inventory_batch_backfill_logs` (`source_document_type`, `source_document_item_id`).
- Restore DB backup if needed; never `migrate:fresh` / `db:wipe` on VPS.
