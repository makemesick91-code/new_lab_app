# DQ-3.1 — Manual Review & Repair Playbook for Ambiguous Transfer/Opname Batch Rows

## Objective

Provide a safe, auditable, owner-approved repair path for the 4 ambiguous source-document rows left after DQ-3 automatic backfill (transfer OUT/IN disagreement, opname no unique movement match). **Never guess batch linkage.**

## DQ-3 WATCH baseline

After DQ-3 deploy: 4 transfer/opname rows remain ambiguous. DQ-3.1 does not auto-repair; it exports evidence and accepts approved mapping files only.

## Ambiguous row classes

| Class | Example | Automatic repair |
| --- | --- | --- |
| Transfer OUT/IN batch disagreement | Items #2, #12 | Forbidden |
| Opname no unique movement match | Items #5, #8 | Forbidden |

## Commands

```bash
# Review pack (read-only)
php artisan inventory:ambiguous-batch-review-pack
php artisan inventory:ambiguous-batch-review-pack --json
php artisan inventory:ambiguous-batch-review-pack --output=review.json --format=json

# Repair (dry-run default)
php artisan inventory:repair-ambiguous-batch-links --mapping=path/to/mapping.csv --dry-run
php artisan inventory:repair-ambiguous-batch-links --mapping=path/to/mapping.csv --execute
```

## Mapping file

Templates: `docs/templates/dq-3-1-ambiguous-batch-repair-mapping-template.csv` (and `.json`).

Required columns: `source_type`, `source_item_id`, `approved_inventory_batch_id`, `approval_reference`, `approved_by`, `approved_at`, `reason`.

## Approval workflow

1. Generate review pack
2. Owner reviews candidates (no PII in export)
3. Fill mapping CSV/JSON with approval fields
4. Dry-run repair — confirm validation PASS
5. **Database backup**
6. Execute with `--execute`
7. Run DQ-1, DQ-2, DQ-3, DQ-3.1 audits
8. Document evidence

## Safety rules

- Canonical repair path: `inventory:repair-ambiguous-batch-links` only
- No stock quantity mutation; ledger unchanged
- No new manufacturer-looking batches
- Updates `inventory_batch_id` on source-document items only
- Transactional, idempotent, audit log in `trx_inventory_batch_backfill_logs`
- NSF-R024 governs ambiguous repair

## GO / WATCH / NO-GO

| State | Criteria |
| --- | --- |
| GO | No ambiguous rows; audits clean |
| WATCH | Playbook deployed; ambiguous rows remain OR mapping not approved |
| NO-GO | DQ audit FAIL (integrity errors) |

## Evidence

Sprint evidence: `docs/sprints/dq-3-1-manual-review-repair-ambiguous-batch-rows-evidence.md`

VPS review packs: `storage/app/backups/deploy/dq31/` or `storage/app/reports/dq31/`
