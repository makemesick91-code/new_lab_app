# CACHE-1 — Cache/Redis Governance Rules (CACHE-R001..R012)

Published by `App\Services\Foundation\CacheRedisGovernanceService` into
`architecture:foundation-governance-summary` under the `cache_redis_governance`
section (informational only — does not affect the blocking `combined`
decision, matching the STORAGE/STATELESS/LB/REPLICA pattern).

| Rule | Title |
|---|---|
| CACHE-R001 | Single-node-safe runtime default until Redis is configured |
| CACHE-R002 | No cache/session driver switch without readiness and rollback |
| CACHE-R003 | Non-destructive, prefixed, short-TTL Redis healthchecks only |
| CACHE-R004 | No Redis secrets in output, docs, logs, tests, or governance summary |
| CACHE-R005 | Shared cache/session required for real multi-node; local cache is single-VPS-only |
| CACHE-R006 | Distributed locks require a controlled prefix, TTL, and safe release |
| CACHE-R007 | Cache invalidation design required before read-heavy caching for RME/payment/inventory/reports |
| CACHE-R008 | Session storage changes require login/logout/CSRF/cashier/RME/branch-context regression |
| CACHE-R009 | Redis outage must fail safe and never double-submit critical writes |
| CACHE-R010 | Governance summary shows Redis/cache readiness without weakening other chains |
| CACHE-R011 | Redis is never the source of truth for stock, invoice, payment, RM, odontogram, or audit log |
| CACHE-R012 | Redis enablement is a separate sprint with canary and explicit rollback |

## Relationship to prior governance

This is a distinct rule set from the earlier `CACHE-1` (cache strategy,
Redis readiness & invalidation governance) sprint's `config/cache_governance.php`
/ `App\Services\Foundation\CacheGovernanceService` / `cache_governance`
summary section, which remains unchanged and continues to publish its own
key-naming/invalidation policy. `CACHE-R001..R012` and the
`cache_redis_governance` section are additive — they do not replace,
renumber, or remove the earlier `cache_governance` section, and both appear
side by side in `architecture:foundation-governance-summary`.

## Non-negotiables

- No `FLUSHDB`/`FLUSHALL`, no wildcard key deletion.
- No secret (Redis password, connection string with credentials) ever
  appears in command output, docs, logs, tests, or the governance summary —
  only a boolean "configured" flag.
- Single VPS pilot must remain `GO` even with Redis fully disabled.
- STORAGE-R001..R005, STATELESS-R001..R008, LB-R001..R010, and
  REPLICA-R001..R012 remain unchanged.
