# DaengtisiaMS — Redis Cache Enterprise Policy (ENT-4)

**Status:** ACTIVE / LOCKED  
**Sprint:** ENT-4 — Redis Cache Enterprise Policy  
**Scope:** Governance, policy, readiness checks, and future implementation tests. ENT-4 does not switch production cache, session, or queue drivers.

## Purpose

Redis is the preferred shared cache/session backend for multi-process and future multi-node DaengtisiaMS deployments, but cache remains an accelerator only. PostgreSQL transaction tables, ledger tables, invoices, visits, payments, and medical records remain the source of truth.

This policy builds on `CACHE-1` and `CACHE-1-REDIS-READINESS` without replacing their runtime readiness checks.

## Canonical Cache Key

All new application cache keys must use:

```text
dms:{env}:{domain}:{scope}:{identifier}:{version}
```

Examples use placeholders only:

```text
dms:{env}:inventory:branch-{branch_id}:stock-summary:v1
dms:{env}:reporting:cross-branch:owner-dashboard:v1
dms:{env}:health:system:redis-readiness:v1
```

Never place secrets, full KTP/NIK, patient names, phone numbers, raw notes, tokens, or credentials in cache keys.

## Locked Rules

- **CACHE-R001:** Redis is the preferred shared cache/session backend for multi-process and future multi-node DaengtisiaMS deployments.
- **CACHE-R002:** Cache is an accelerator only. Database transaction tables, ledger tables, invoices, visits, payments, and medical records remain the source of truth.
- **CACHE-R003:** Cache keys must use canonical structure `dms:{env}:{domain}:{scope}:{identifier}:{version}`.
- **CACHE-R004:** Branch-owned cache keys must include branch scope or a clearly documented cross-branch analytics scope.
- **CACHE-R005:** Cross-branch cached analytics must be read-only, permission-gated, and PII-masked.
- **CACHE-R006:** PII, full KTP/NIK, raw clinical notes, scanned document contents, session secrets, tokens, and credentials must not be stored in application cache payloads.
- **CACHE-R007:** TTL must be explicit. No indefinite cache for business data unless it is immutable reference data and has a clear invalidation path.
- **CACHE-R008:** The TTL matrix must define defaults for master data, branch settings, owner dashboard, reporting summaries, inventory derived reads, patient lookup metadata, health/readiness checks, and feature flags/governance metadata.
- **CACHE-R009:** Invalidation strategy must be documented for each cacheable domain: write-through invalidation, tag-based invalidation if supported, versioned keys, scheduled refresh, manual rebuild, or short TTL fallback.
- **CACHE-R010:** Cache invalidation must not rely on UI actions only. Server-side writes must trigger or plan invalidation.
- **CACHE-R011:** Inventory stock remains ledger-derived from `trx_inventory_movements`. Cache may accelerate derived stock reads, but must not become mutable stock or source of truth.
- **CACHE-R012:** RME/payment/lab candidate transactional decisions must not depend on cached stale data.
- **CACHE-R013:** Report/dashboard cache must align with the ENT-2 Database Performance Contract and ENT-3 Reporting Materialized Summary Contract.
- **CACHE-R014:** Queue-heavy refresh and cache warming must align with ENT-5 Queue Governance and ENT-6 Idempotency/Outbox.
- **CACHE-R015:** Cache health/readiness must be observable and must feed ENT-7/ENT-8 Developer Assistance and Health Check work.
- **CACHE-R016:** Single-VPS local-disk cache/session warnings must be documented as pilot risk and migration target.
- **CACHE-R017:** Cache failure must degrade safely. Critical writes must not fail solely because non-critical cache invalidation failed.
- **CACHE-R018:** Any future cache implementation must include tests or smoke evidence for key format, TTL, invalidation, branch scope, and PII safety.

## Implementation Posture

ENT-4 intentionally adds no business feature caching, no migration, and no production driver change. Future cache work must cite this policy, `docs/architecture/cache-ttl-matrix.md`, and `docs/architecture/cache-invalidation-matrix.md`.
