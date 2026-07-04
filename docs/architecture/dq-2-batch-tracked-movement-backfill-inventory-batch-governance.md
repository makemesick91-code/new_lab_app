# DQ-2 Batch-tracked Movement Backfill & Inventory Batch Governance

## 1. Objective

Close DQ-1 WATCH item **DQ1-DATA-006** (batch-tracked movements missing `inventory_batch_id`) through safe, auditable DQ-2 governance:

- Read-only batch anomaly audit
- Dry-run-first backfill with explicit `--execute`
- Permanent write guardrail on movement creation
- Foundation governance integration

## 2. DQ-1 baseline

| Item | Value |
| --- | --- |
| PR | #163 merged |
| GO tag | `dq-1-acid-constraint-data-quality-audit-go` |
| VPS WATCH | DQ1-DATA-006 — 12 movements missing `inventory_batch_id` |

DQ-2 extends/closes DQ1-DATA-006 when backfill is deterministic or uses marked legacy placeholders.

## 3. Safe backfill decision tree

1. **Source document recovery** — goods receipt item (`inventory_movement_id` / `reversal_movement_id`), transfer item, opname item, or GR line batch fields.
2. **Single batch candidate** — exactly one `inv_inventory_batches` row for product + branch.
3. **Legacy governance placeholder** — `LEGACY-DQ2-{product_id}-{branch_id}-{date}-{movement_id}` when no manufacturer identity is recoverable. Marked via `backfill_source=dq2_legacy_placeholder`.
4. **Ambiguous** — multiple candidates; left untouched (WARN). Export evidence for manual resolution.

## 4. Commands

```bash
# Audit (read-only)
php artisan inventory:batch-governance-audit
php artisan inventory:batch-governance-audit --json
php artisan inventory:batch-governance-audit --fail-on=error
php artisan inventory:batch-governance-audit --export=dq2-audit.json

# Backfill (dry-run default)
php artisan inventory:backfill-missing-batches
php artisan inventory:backfill-missing-batches --dry-run --json
php artisan inventory:backfill-missing-batches --execute
php artisan inventory:backfill-missing-batches --execute --movement-id=123
php artisan inventory:backfill-missing-batches --execute --export=dq2-backfill.json
php artisan inventory:backfill-missing-batches --no-legacy-placeholder
```

## 5. Audit check IDs

| Check ID | Rule |
| --- | --- |
| DQ2-BATCH-001 | Schema + `requires_batch_tracking` present |
| DQ2-BATCH-002 | Batch-tracked movements have `inventory_batch_id` |
| DQ2-BATCH-003 | Movement batch matches product |
| DQ2-BATCH-004 | Movement batch branch compatible |
| DQ2-BATCH-005 | No orphan `inventory_batch_id` |
| DQ2-BATCH-006 | Quantity direction valid |
| DQ2-BATCH-007 | Transfer batch linkage (closed by DQ-3 source-document backfill) |
| DQ2-BATCH-008 | Goods receipt batch identity (closed by DQ-3 source-document backfill) |
| DQ2-BATCH-009 | Stock opname batch identity (closed by DQ-3 source-document backfill) |
| DQ2-BATCH-010 | DQ1-DATA-006 compatibility |

## 6. Write guardrail

`InventoryMovementBatchGuard` enforces `inventory_batch_id` when `inv_products.requires_batch_tracking = true` on all `InventoryMovementRepository::create()` calls.

DQ-2 backfill updates existing rows directly (no guard bypass on public HTTP flows).

**DQ-3** closes source-document item linkage (DQ2-BATCH-007/008/009). **DQ-3.1** closes remaining ambiguous transfer/opname rows via approved manual repair. See `docs/architecture/dq-3-source-document-batch-linkage-closure.md` and `docs/architecture/dq-3-1-manual-review-repair-ambiguous-batch-rows.md`.

## 7. Provenance

Migration `2026_07_04_100001_add_dq2_batch_governance_provenance`:

- `inv_inventory_batches.backfill_source` (nullable)
- `inv_inventory_batches.backfilled_at` (nullable)
- `trx_inventory_batch_backfill_logs` — per-movement audit trail

## 8. Deploy procedure

1. DB backup → `storage/app/backups/deploy/pre_dq2_*.sql`
2. Checkout GO tag `dq-2-batch-tracked-movement-backfill-inventory-batch-governance-go`
3. `composer install --no-dev`, `npm ci && npm run build`
4. `php artisan migrate --force`
5. `php artisan inventory:batch-governance-audit`
6. `php artisan inventory:backfill-missing-batches --dry-run`
7. `php artisan inventory:backfill-missing-batches --execute` (if dry-run safe)
8. Post-audit: DQ-1 + DQ-2 + `architecture:foundation-governance-summary`
9. Cache rebuild + service restart

## 9. Rollback / verification

- Revert code to prior GO tag if needed.
- DB: restore from pre-deploy backup only if execute mutated data incorrectly.
- Verify: `data-quality:dq1-audit`, `inventory:batch-governance-audit --fail-on=error`.

## 10. Production safety

- Dry-run is default; `--execute` is explicit.
- Backfill is transactional and idempotent (`trx_inventory_batch_backfill_logs`).
- No mutation of `quantity_in`, `quantity_out`, `movement_type`, or branch/product/location.
- Ledger remains sole stock source of truth.
- Never `migrate:fresh` / `db:wipe` on VPS.

## 11. Evidence

See `docs/sprints/dq-2-batch-tracked-movement-backfill-inventory-batch-governance-evidence.md`.
