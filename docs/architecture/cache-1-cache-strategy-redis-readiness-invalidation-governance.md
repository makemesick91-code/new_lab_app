# CACHE-1 — Cache Strategy, Redis Readiness & Invalidation Governance

**Sprint:** CACHE-1  
**Status:** Implemented (readiness/governance only)  
**Baseline:** NSF-10 GO — merge `8fdc92e`, tag `nsf-10-observability-backup-release-safety-hardening-go`  
**Next sprint:** QUEUE-1 — Queue, Idempotency & Outbox Foundation

---

## 1. Objective

Establish the **cache foundation governance layer** for DaengtisiaMS before any broad
runtime caching of domain data:

- Cache strategy source of truth (`config/cache_governance.php`)
- Redis readiness policy (not production enablement)
- Cache key naming + branch isolation + PII/secrets ban
- Invalidation governance for allowed categories
- Feature flag integration (NSF-9 flags)
- Release evidence / deploy gate / Foundation summary integration

**Out of scope for CACHE-1:**

- Enabling Redis as production default cache store
- Installing Redis on VPS
- Runtime caching of inventory stock, payments, RME finalization, consent, auth decisions
- Business behavior changes unless explicitly flag-guarded

---

## 2. Source of truth

| Artifact | Path |
| --- | --- |
| Cache governance config | `config/cache_governance.php` |
| Governance command | `php artisan foundation:cache-governance-check` |
| Service | `App\Services\Foundation\CacheGovernanceService` |
| Feature flags | `foundation.cache.redis_readiness`, `foundation.cache.invalidation_governance` |
| CI evidence | `storage/ci-evidence/cache-governance-check.json` |
| VPS evidence | `storage/release-evidence/latest/cache-governance-check.json` |

---

## 3. Cache strategy

- **Prefix:** `daengtisiams`
- **Template:** `{prefix}:{env}:{branch?}:{module}:{resource}:{version?}`
- **Branch-scoped data:** branch segment mandatory
- **Global data:** must appear in `global_key_allowlist`
- **PII/secrets:** never in keys or values (KTP/NIK/name/phone/email/RM/invoice raw banned)
- **Critical mutable data:** denied by default (see `denied_cache_categories`)

---

## 4. Redis readiness policy

- **Default status:** `readiness_only`
- **Production default enabled:** `false`
- **Probe:** optional via `--include-redis-probe`; uses ephemeral key `daengtisiams:{env}:foundation:cache_governance:probe`
- **Why Redis is not enabled broadly yet:** CACHE-1 delivers governance and probe readiness only; runtime enablement requires explicit owner approval, probe GO, rollback plan, and invalidation tests per category.

Allowed env keys (safe to reference): `CACHE_STORE`, `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_DB`, `REDIS_CACHE_DB`  
Never print: `REDIS_PASSWORD`, `REDIS_USERNAME`, `APP_KEY`, `DB_PASSWORD`

---

## 5. Allowed vs denied categories

**Allowed (readiness-only, with invalidation rules):**

- `foundation.static_config`, `foundation.feature_flags`, `foundation.roadmap_summary`, `foundation.release_evidence_summary`
- `master_data.global_reference`, `master_data.branch_reference`
- `reporting.precomputed_summary_readiness`

**Denied (critical mutable / PII):**

- Inventory stock/ledger, cashier payment/receivable, RME draft/finalization/consent
- Auth permission runtime, branch context, patient PII, private document scans

---

## 6. Invalidation governance

Every allowed category defines:

- `invalidation_events`
- `invalidation` block: trigger, scope, affected_key_pattern, fallback, owner, tests_required

Policy also documents:

- Event-based invalidation preferred
- Manual/deploy/config clear fallbacks
- Branch/module scoped invalidation
- Emergency full clear (`php artisan cache:clear`) with incident ticket + rollback plan

---

## 7. Feature flags (NSF-9)

| Flag | Default | Risk | Notes |
| --- | --- | --- | --- |
| `foundation.cache.redis_readiness` | false | high | Redis runtime enablement |
| `foundation.cache.invalidation_governance` | false | high | Enforces invalidation before critical cache usage |

Both `rollout_status: implemented` after CACHE-1; defaults remain **false**.

---

## 8. Release safety & deploy integration

- `config/release_safety.php` includes `foundation:cache-governance-check`
- `config/release_evidence.php` requires `cache-governance-check.json` for `ci` and `vps` profiles
- `scripts/deploy-vps.sh` runs governance check + captures JSON evidence
- `.github/workflows/foundation-evidence-gates.yml` captures CI artifact
- `architecture:foundation-governance-summary` includes **CACHE_GOVERNANCE** section

Combined GO: CACHE_GOVERNANCE **FAIL** blocks combined; **WATCH** (e.g. Redis probe unavailable while runtime disabled) is non-blocking.

---

## 9. GO / WATCH / NO-GO

| Decision | When |
| --- | --- |
| **GO** | Config complete; allowed/denied categories valid; Redis probe not required or passed |
| **WATCH** | Redis probe requested but unavailable while Redis runtime disabled |
| **NO-GO** | Config incomplete; denied category allowed; Redis runtime enabled but probe fails; PII/secrets policy violated |

Command: `php artisan foundation:cache-governance-check [--json] [--include-redis-probe]`

---

## 10. DaengtisiaMS permanent cache rules

1. No runtime caching of critical mutable financial, inventory, branch context, consent, auth decision, or medical record state without explicit cache governance approval and invalidation tests.
2. All branch data cache keys must include branch scope.
3. All global cache keys must be explicitly allowlisted in `config/cache_governance.php`.
4. No PII/secrets in cache keys or values.
5. Redis production enablement requires Redis probe GO and rollback plan.
6. Cache invalidation rule is mandatory before runtime cache usage.
7. Cache governance command must be part of CI, release evidence, deploy gate, and Foundation summary.
8. Future caching implementation must reference `config/cache_governance.php`.

---

## 11. Next sprint

**QUEUE-1 — Queue, Idempotency & Outbox Foundation** (locked after CACHE-1 completion).
