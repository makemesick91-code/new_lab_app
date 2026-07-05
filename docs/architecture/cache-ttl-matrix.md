# DaengtisiaMS — Cache TTL Matrix (ENT-4)

**Status:** ACTIVE / LOCKED  
All TTLs are defaults for future implementations. Runtime caching remains planned unless explicitly marked active by a later sprint.

| Domain | Example key pattern | Data sensitivity | Branch scope required | Default TTL | Max TTL | Invalidation trigger | Stale tolerance | Source of truth | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Owner dashboard KPI | `dms:{env}:dashboard:cross-branch:owner-kpi:v1` | Aggregated, PII-masked | Cross-branch scope documented | 5 minutes | 15 minutes | Report summary refresh, invoice/payment/visit/write events | Low | PostgreSQL + declared summaries | planned |
| RME patient lookup metadata | `dms:{env}:rme:branch-{branch_id}:patient-lookup:{hash}:v1` | Sensitive metadata only, no full KTP/NIK | Yes | 1 minute | 5 minutes | Patient created/updated, medical record update | Very low | Patient/RME tables | planned |
| RME reports | `dms:{env}:reporting:branch-{branch_id}:rme-report:{filters_hash}:v1` | Masked report data | Yes unless owner analytics | 10 minutes | 30 minutes | Visit, medical record, invoice, payment writes | Medium | PostgreSQL + summaries | planned |
| RME receivable aging | `dms:{env}:finance:branch-{branch_id}:receivable-aging:{date}:v1` | Financial aggregate | Yes unless owner analytics | 10 minutes | 30 minutes | Invoice/payment/follow-up writes | Medium | Invoice/payment tables | planned |
| Inventory current stock derived read | `dms:{env}:inventory:branch-{branch_id}:stock:{product_id}:v1` | Operational stock aggregate | Yes | 1 minute | 5 minutes | Inventory movement, transfer, opname finalization | Very low | `trx_inventory_movements` ledger | planned |
| Inventory valuation/reporting | `dms:{env}:inventory:branch-{branch_id}:valuation:{date}:v1` | Financial inventory aggregate | Yes unless owner analytics | 10 minutes | 30 minutes | Movement, batch, cost, opname finalization | Medium | Ledger + product/batch tables | planned |
| Lab order/candidate counts | `dms:{env}:lab:branch-{branch_id}:candidate-counts:v1` | Count aggregate | Yes | 5 minutes | 15 minutes | Candidate generated/converted, order status change | Low | Lab order/candidate tables | planned |
| Master data: branches | `dms:{env}:master:global:branches:v1` | Low | Global | 30 minutes | 24 hours | Branch created/updated/deactivated | Medium | `mst_branches` | planned |
| Master data: rooms/treatments/tariffs/payment methods | `dms:{env}:master:branch-{branch_id}:reference:{type}:v1` | Low to medium | Yes where branch-owned | 30 minutes | 12 hours | Master data write | Medium | Master tables | planned |
| Feature flags/governance metadata | `dms:{env}:governance:global:feature-flags:v1` | Low, no secrets | Global | 5 minutes | 30 minutes | Config deploy, governance update, manual rebuild | Low | Config/database flag source | planned |
| Health/readiness checks | `dms:{env}:health:system:redis-readiness:v1` | Non-sensitive status only | No | 30 seconds | 2 minutes | Readiness command run, deploy, config cache rebuild | Low | Runtime readiness command | planned |
| Forbidden sensitive payloads | Prohibited | PII/secrets/raw clinical/scans/session data | N/A | N/A | N/A | N/A | None | Authoritative tables/storage/session backend | prohibited |

Forbidden payloads include full KTP/NIK, raw clinical notes, scanned document contents, session secrets, tokens, credentials, raw private documents, and unmasked patient identity bundles.
