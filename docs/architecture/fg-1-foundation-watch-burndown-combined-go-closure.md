# FG-1 — Foundation WATCH Burn-down & Combined Governance GO Closure

## Objective

Close remaining non-DQ Foundation WATCH causes, make Combined Foundation governance explainable, and consume the completed DQ-1/DQ-2/DQ-3/DQ-3.1 GO chain without stale blocking warnings.

## DQ chain baseline (GO)

| Sprint | Status | Evidence |
| --- | --- | --- |
| DQ-1 | GO | `docs/sprints/dq-1-acid-constraint-data-quality-audit-evidence.md` |
| DQ-2 | GO | `docs/sprints/dq-2-batch-tracked-movement-backfill-inventory-batch-governance-evidence.md` |
| DQ-3 | GO | `docs/sprints/dq-3-source-document-batch-linkage-closure-evidence.md` |
| DQ-3.1 | GO | `docs/sprints/dq-3-1-manual-review-repair-ambiguous-batch-rows-evidence.md` |

Baseline commit: `dba5a2b` — docs: finalize DQ-3.1 approved repair evidence.

## NSF WATCH causes (non-blocking)

| Rule ID | Classification | Cause | Action |
| --- | --- | --- | --- |
| NSF-R009 | environment | pg_stat_statements deferred locally; validate on VPS | Run `performance:runtime-query-observability` on VPS |
| NSF-R011 | evidence_only | Full suite gate requires sprint/CI evidence | Document in sprint evidence; run `php artisan test` |
| NSF-R012 | evidence_only | Build/pint gate requires sprint evidence | Document in sprint evidence; run `npm run build` + pint |
| NSF-M001 | deferred_backlog | Meta backlog for R011/R012 | Owner: Engineering; target: NSF-7 |
| NSF-M002 | deferred_backlog | Meta backlog for R009 VPS validation | Owner: Platform; target: NSF-7 |

**NSF effective decision:** GO (no blocking warnings; raw decision remains WATCH for transparency).

## DMO WATCH causes (deferred backlog)

| Rule ID | Classification | Cause | Owner | Target |
| --- | --- | --- | --- | --- |
| DMO-M001 | deferred_backlog | net_revenue blocked — pilot uses paid_amount | Product/Finance | DMO-3 |
| DMO-M003 | deferred_backlog | receivable_aging_bucket has no persisted table | Finance | DMO-3 |
| DMO-M006 | deferred_backlog | Treatment/tariff multi-branch price boundary | Product | DMO-3 |
| DMO-M007 | deferred_backlog | pod_count blocked pending POD standardization | Lab/Delivery | DMO-3 |

**DMO effective decision:** GO (deferred backlog only).

## Combined decision rules

1. **NO-GO** — any NSF or DMO error-severity failure, or DQ chain not GO.
2. **WATCH** — any blocking NSF/DMO warning, or DQ ambiguous rows remain.
3. **GO** — errors = 0, DQ chain GO, and all remaining NSF/DMO warnings are classified as `deferred_backlog`, `evidence_only`, or `environment`.

Combined GO is allowed when deferred backlog is documented with owner/risk/target sprint. Raw NSF/DMO decisions may still show WATCH.

## Commands

```bash
php artisan architecture:foundation-governance-summary
php artisan architecture:foundation-governance-summary --json
php artisan architecture:nsf-governance-check --json
php artisan architecture:dmo-governance-check --json
php artisan data-quality:dq1-audit
php artisan inventory:batch-governance-audit
php artisan inventory:source-document-batch-audit
php artisan inventory:ambiguous-batch-review-pack
```

## Deploy gate (VPS)

1. Pre-deploy DB backup (record path + size).
2. `composer install --no-dev`, `npm ci && npm run build`.
3. `php artisan migrate --force` only — never `migrate:fresh` / `db:wipe`.
4. Run DQ chain audits + Foundation summary.
5. Cache rebuild + smoke (`/login`, protected routes, governance commands).

## Permanent governance rules (FG-1)

- Foundation summary must name exact causes of WATCH (rule ID + classification + message).
- Combined GO cannot be claimed while unexplained NSF/DMO **blocking** WATCH remains.
- DQ chain GO must be consumed by foundation governance without stale DQ warnings.
- Deferred governance items require owner/risk/target sprint in `config/foundation_governance.php`.
- Deploy gate must include Foundation summary + DQ chain audits.
- Evidence-only commits must not move GO tags unless explicitly approved.

## Configuration

- `config/foundation_governance.php` — FG-1 classifications, deferred backlog metadata, evidence paths, FG1 check IDs.

## Service

- `App\Services\Architecture\FoundationGovernanceSummaryService` — NSF + DMO + DQ chain + Combined decision with watch cause enumeration.
