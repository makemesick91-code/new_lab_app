# Ontology Relationship Map

**Sprint:** DMO-1  
**Maintained by:** `DmoOntologyRegistry` via `architecture:dmo-foundation`

## Summary

| Count | Value |
| --- | --- |
| Documented relationships | 26 |
| Dimension standards | 11 |

## Relationship Types

| Type | Meaning |
| --- | --- |
| scopes | Branch owns/limits entity access |
| has_many / has_one | Parent-child entity link |
| belongs_to | Optional parent reference |
| derives_from | Computed from source entity |
| produces | Workflow creates downstream entity |
| consumes | Dashboard/report reads metric/telemetry |
| converts_to | Workflow transformation |
| attaches_to | Optional FK attachment (batch on movement) |
| aggregates | SUM/COUNT over ledger rows |

## Core Relationships

### Branch Scoping

| Source | Target | Type | Cardinality | Domain |
| --- | --- | --- | --- | --- |
| Branch | Clinic Visit | scopes | one_to_many | foundation |
| Branch | Patient | scopes | one_to_many | foundation |
| Branch | Inventory Movement | scopes | one_to_many | inventory |
| Branch | RME Invoice | scopes | one_to_many | cashier |
| Branch | RME Payment | scopes | one_to_many | cashier |

### RME Clinical Chain

| Source | Target | Type | Cardinality | Notes |
| --- | --- | --- | --- | --- |
| Patient | Clinic Visit | has_many | one_to_many | Patient-centric RM workspace |
| Clinic Visit | Medical Record | has_one | one_to_one | UNIQUE per visit sheet |
| Clinic Visit | Odontogram | has_one | one_to_one | Structured tooth_map |
| Clinic Visit | Clinic Visit | belongs_to | many_to_one | follow_up_parent_visit_id |
| Clinic Visit | RME Invoice | produces | one_to_many | After cashier_pending |

### Cashier / Receivable

| Source | Target | Type | Notes |
| --- | --- | --- | --- |
| RME Invoice | RME Payment | has_many | Full/partial; batch_uuid |
| RME Invoice | Receivable | derives_from | Derived view — DMO-003 |
| Treatment | RME Invoice Item | references | Nullable treatment_id |
| Tariff | RME Invoice Item | references | Multi-branch boundary — DMO-006 |

### Inventory Ledger

| Source | Target | Type | Notes |
| --- | --- | --- | --- |
| Inventory Product | Inventory Movement | has_many | Ledger truth |
| Inventory Movement | Current Stock | derives_from | SUM(in)-SUM(out) |
| Inventory Batch | Inventory Movement | attaches_to | Lot on movements |
| Purchase Request | Purchase Order | produces | Procurement |
| Purchase Order | Goods Receipt | produces | |
| Goods Receipt | Inventory Movement | produces | quantity_in |
| Stock Transfer | Inventory Movement | produces | out + in pair |
| Stock Opname | Inventory Movement | produces | variance adjustment |

### Lab / RME Integration

| Source | Target | Type | Notes |
| --- | --- | --- | --- |
| RME Invoice Item | Lab Case Candidate | produces | requires_lab after payment |
| Lab Case Candidate | Lab Order | converts_to | Admin review |

### Reporting / Telemetry

| Source | Target | Type | Notes |
| --- | --- | --- | --- |
| Owner Dashboard KPI | Canonical Metrics | consumes | Duplicate aliases — DMO-M005 |
| Performance Runtime Evidence | pg_stat_statements | consumes | NSF-3 telemetry |

## Risks and Gaps

| Relationship | Risk |
| --- | --- |
| Receivable derives_from Invoice | No persisted receivable table — metrics must document derivation |
| Tariff → Invoice Item | Multi-branch pricing boundary unresolved |
| Current Stock derives_from Movement | Must never introduce mutable stock column |
| Patient Document | Sensitivity policy not formalized — DMO-001 |

## Export

```bash
php artisan architecture:dmo-foundation --json | jq '.ontology_relationships'
```
