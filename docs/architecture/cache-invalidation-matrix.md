# DaengtisiaMS — Cache Invalidation Matrix (ENT-4)

**Status:** ACTIVE / LOCKED  
Server-side writes must trigger or plan invalidation. UI-only invalidation is not sufficient.

| Domain | Write event | Affected cache scopes | Invalidation method | Fallback if Redis unavailable | Future queue/outbox tie-in | Status |
| --- | --- | --- | --- | --- | --- | --- |
| Patient lookup | Patient created/updated | Branch patient lookup metadata, masked search metadata | Versioned key bump or targeted forget | Short TTL, database read-through | ENT-6 outbox event for patient update | planned |
| Clinic visit | Clinic visit created/status changed | Branch RME report, owner dashboard KPI | Write-through invalidation after transaction commit | Short TTL, summary rebuild | ENT-5 queued refresh for heavy reports | planned |
| Medical record | Medical record finalized/updated | RME reports, patient metadata, owner summaries | Targeted forget/versioned key; never cache raw notes | Database read-through | ENT-6 idempotent record-finalized event | planned |
| RME invoice/payment | Invoice/payment created/updated | Receivable aging, finance reports, dashboard KPI | Write-through invalidation after commit | Short TTL, manual rebuild | ENT-6 payment outbox event | planned |
| Receivable follow-up | Follow-up created | Receivable aging/follow-up summaries | Targeted forget or version bump | Short TTL | ENT-5 queued report refresh | planned |
| Lab candidates | Lab candidate generated/converted | Candidate counts, lab dashboard aggregates | Write-through invalidation after commit | Short TTL | ENT-6 idempotent conversion event | planned |
| Inventory movement | Movement created | Current stock derived reads, stock card, valuation | Targeted branch/product/location forget or version bump | Direct ledger query | ENT-5 queued valuation refresh | planned |
| Stock transfer | Transfer shipped/received | Source/destination location stock, transfer counts | Invalidate both affected locations after ledger write | Direct ledger query | ENT-6 transfer event | planned |
| Stock opname | Stock opname finalized | Branch stock, variance reports, valuation | Version bump after adjustment movements commit | Direct ledger query, manual rebuild | ENT-5 queued valuation refresh | planned |
| Product/batch/master data | Product, batch, unit, tariff, payment method, room changed | Master data and dependent report scopes | Tag-based invalidation if supported; otherwise versioned keys | Short TTL, config/deploy rebuild | ENT-6 master-data changed event | planned |
| Report summary | Report summary refreshed | Dashboard/report cached aggregates | Versioned summary keys; scheduled refresh; manual rebuild | Serve database/summary directly | ENT-5 scheduled/queued refresh | planned |

Critical writes must complete even if non-critical cache invalidation fails. The failure must be logged without secrets/PII and surfaced through future ENT-7/ENT-8 observability work.
