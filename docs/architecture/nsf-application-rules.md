# NSF Application Rules (NSF-6)

## 1. Purpose

Encode National Scale Foundation (NSF-1 through NSF-5) as **application-level** guardrails — branch isolation, ledger inventory, performance/index governance, migration safety, observability, privacy-safe evidence, deploy gates, and DMO alignment.

## 2. Why application-level

NSF foundation must be machine-readable and validated before NDA (national distributed architecture) work begins. Rules live in `config/nsf.php`, validated by `architecture:nsf-governance-check`, covered by tests, and referenced by sprint evidence.

## 3. Relationship to DMO application rules

| Layer | Governs |
| --- | --- |
| **DMO rules** (DMO-R001–R015) | Canonical entities, metrics, Owner KPI mapping, sensitivity |
| **NSF rules** (NSF-R001–NSF-R021) | Branch isolation, ledger stock, performance, migrations, observability, deploy gates, NDA boundary |

Future NDA sprints must pass **both** `architecture:dmo-governance-check` and `architecture:nsf-governance-check`.

## 4. NSF rule registry NSF-R001–NSF-R021

| ID | Title | Severity |
| --- | --- | --- |
| NSF-R001 | Branch isolation required | error |
| NSF-R002 | Inventory stock is ledger-derived only | error |
| NSF-R003 | No mutable stock columns | error |
| NSF-R004 | Inventory movement is source of truth | error |
| NSF-R005 | Safe index governance | warning |
| NSF-R006 | Migration safety | error |
| NSF-R007 | Driver-aware SQL | error |
| NSF-R008 | Observability required | error |
| NSF-R009 | pg_stat guardrail | warning |
| NSF-R010 | Privacy-safe evidence | error |
| NSF-R011 | Full suite gate | warning |
| NSF-R012 | Build gate | warning |
| NSF-R013 | Deploy backup gate | error |
| NSF-R014 | Deploy smoke gate | error |
| NSF-R015 | No distributed technology before NDA approval | error |
| NSF-R016 | DMO alignment required | error |
| NSF-R017 | Owner KPI rule dependency | error |
| NSF-R018 | Read-only governance commands | error |
| NSF-R019 | Rollback readiness | warning |
| NSF-R020 | Evidence path standard | error |
| NSF-R021 | National scale readiness boundary | info |

Source: `config/nsf.php`  
Validator: `App\Services\Architecture\NsfApplicationRulesService`

## 5. Branch isolation rules

- Use `BranchContext::requireId()` for branch-owned operations.
- Never trust request `branch_id` without policy validation.
- Reports and dashboards must scope to authorized branches.

## 6. Inventory ledger rules

- Stock = `SUM(quantity_in) - SUM(quantity_out)` from `trx_inventory_movements`.
- No mutable `current_stock` / `qty_on_hand` on canonical inventory tables.
- Procurement, transfer, opname, and batch workflows write movements only.

## 7. Performance/index rules

- Index proposals require slow-query audit or documented evidence (NSF-R005).
- NSF-2 safe index pack patterns remain baseline.
- Runtime observability via `performance:runtime-query-observability`.

## 8. Migration/test compatibility rules

- Migrations must be PostgreSQL-safe and SQLite-test compatible where full suite uses SQLite.
- Driver-specific SQL must be guarded (NSF-R007).

## 9. Observability rules

- `performance:slow-query-audit` and `performance:runtime-query-observability` must remain registered.
- VPS PostgreSQL should have `pg_stat_statements` preloaded (NSF-R009).

## 10. Privacy/evidence rules

Governance commands must never emit: patient names, KTP/NIK, phone, address, diagnosis, odontogram/medical notes, or raw financial row data.

## 11. Deploy gates

See `docs/architecture/nsf-governance-deploy-gates.md`.

## 12. NDA boundary rules

NSF-R015 blocks Redis/Kafka/GraphQL/gRPC/NoSQL/LB/CDN until NDA sprint approval.  
NSF-R021 declares that NDA implementation must not violate branch isolation, ledger stock, DMO metrics, privacy, or deploy gates.

## 13. How future sprints must use these rules

1. Run `architecture:nsf-governance-check --include-dmo --include-observability`
2. Run `data-quality:dq1-audit --fail-on=error` for database/foundation sprints
3. Run `architecture:foundation-governance-summary` before GO tag
4. Capture evidence under `storage/app/architecture/`
5. Document rollback in sprint evidence
6. Pass full suite + build gates before merge
7. Multi-write operations must use `DB::transaction` (see `docs/architecture/dq-1-acid-constraint-data-quality-audit.md`)
8. Migrations must be additive and production-safe; prefer named constraints after DQ-1 audit confirms clean data

```bash
php artisan data-quality:dq1-audit
  [--json]
  [--output=storage/app/architecture/dq1-audit.json]
  [--fail-on=error|warning|any]

php artisan architecture:nsf-governance-check
  [--json]
  [--output=storage/app/architecture/nsf6-governance-check.json]
  [--strict]
  [--include-dmo]
  [--include-deploy-gates]
  [--include-observability]
  [--include-privacy]
```
