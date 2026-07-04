# National Foundation Expansion Roadmap (ROADMAP-1)

**Status:** Source-locked (ROADMAP-1)
**Machine-readable source of truth:** [`config/foundation_roadmap.php`](../../config/foundation_roadmap.php)
**Governance command:** `php artisan architecture:foundation-roadmap-check`
**Integrated into:** `php artisan architecture:foundation-governance-summary` (ROADMAP section)

> This document is the canonical narrative roadmap. Where this doc and
> `config/foundation_roadmap.php` differ, **the config is authoritative** and this
> doc must be corrected. Any change to order/scope requires an explicit **ROADMAP
> update sprint** plus an evidence doc — Cursor/Claude Code must not create foundation
> sprints outside this sequence.

---

## 1. Current Baseline

Foundation is fully green after **NSF-8**:

| Gate | Status |
| --- | --- |
| DQ-1 | GO |
| DQ-2 | GO |
| DQ-3 | GO |
| DQ-3.1 | GO |
| DMO | GO |
| NSF raw | GO |
| NSF effective | GO |
| Combined Foundation | GO |
| Node runtime (VPS) | ≥ 20 |
| Observability (NSF-R009) | validated |
| CI evidence gate (NSF-R011 / R012) | automated |

---

## 2. Roadmap Principles

1. Foundation before feature scale.
2. One foundation per scoped sprint.
3. Readiness / design first before production rollout for risky infra.
4. No production destructive changes.
5. All changes must preserve DQ / DMO / NSF / Combined GO.
6. Every sprint must update evidence docs.
7. Every sprint must include test / pint / graphify.
8. Every deploy must back up the DB first.
9. Every production deploy must run DQ + DMO + NSF + Foundation gates.

---

## 3. Approved Sprint Sequence

Execution order is fixed (ascending priority). RC-1 is always last.

| # | Sprint | Title |
| --- | --- | --- |
| 1 | **NSF-9** | Release Safety, Feature Flag & Automated Smoke |
| 2 | **NSF-10** | Observability, Backup & Release Safety Hardening |
| 3 | **CACHE-1** | Cache Strategy, Redis Readiness & Invalidation Governance |
| 4 | **QUEUE-1** | Queue, Idempotency & Outbox Foundation |
| 5 | **DBPERF-1** | PostgreSQL Index Optimization & Query Plan Audit |
| 6 | **DBPERF-2** | PgBouncer & PostgreSQL Runtime Tuning |
| 7 | **RPT-1** | Materialized View + rpt_* Summary Foundation |
| 8 | **STORAGE-1** | Object Storage Readiness |
| 9 | **STATELESS-1** | Stateless App Readiness |
| 10 | **LB-1** | Load Balancer Pilot |
| 11 | **REPLICA-1** | Read Replica Readiness |
| 12 | **PART-1** | Partitioning Design, Not Production Yet |
| 13 | **SEARCH-1** | Search Engine & Log Explorer Foundation |
| 14 | **NDA-1** | National Distributed Architecture Plan |
| 15 | **RC-1** | Foundation Green Release Candidate Consolidation |

**Next recommended sprint:** `NSF-10` (NSF-9 completed — see
[`nsf-9-release-safety-feature-flag-automated-smoke.md`](nsf-9-release-safety-feature-flag-automated-smoke.md)).

---

## 4. Sprint Cards

Each card below mirrors `config/foundation_roadmap.php`. The config carries the full
`objective / why_this_order / allowed_scope / out_of_scope / production_safety_rule /
required_gates / go_criteria / watch_criteria / no_go_criteria / deliverables` per sprint.

### NSF-9 — Release Safety, Feature Flag & Automated Smoke — **COMPLETED**
- **Status:** Completed. See [`nsf-9-release-safety-feature-flag-automated-smoke.md`](nsf-9-release-safety-feature-flag-automated-smoke.md)
  and `docs/sprints/nsf-9-release-safety-feature-flag-automated-smoke-evidence.md`.
- **Objective:** Governed feature-flag layer + automated post-deploy smoke suite.
- **Why this order:** Flags + smoke must exist before any risky runtime feature.
- **Allowed scope:** flag registry/resolver (no SaaS), automated smoke command, rollout/rollback docs.
- **Out of scope:** enabling Redis/Queue/PgBouncer; destructive migration; external flag SaaS.
- **Production safety:** flags default OFF; smoke read-only, no financial/inventory mutation.
- **Gates:** test / pint / graphify / dq / dmo / nsf / combined.
- **GO:** flag layer present + default-off; smoke green; foundation GO preserved.
- **WATCH:** partial smoke coverage or incomplete rollback playbook, documented.
- **NO-GO:** flags default ON; smoke mutates prod; foundation GO regressed.

### NSF-10 — Observability, Backup & Release Safety Hardening
- **Objective:** Harden observability, backup verification, release-safety runbooks.
- **Why this order:** Detectability + recoverability before stateful infra.
- **Out of scope:** enabling cache/queue backends; PII/secrets in logs.
- **Production safety:** verified backups before any stateful rollout; no PII/secrets in observability.
- **GO:** backup+restore drill evidenced; observability expanded (no PII); foundation GO preserved.
- **WATCH:** restore drill deferred with owner sign-off.
- **NO-GO:** PII/secrets in logs; no verified backup path.

### CACHE-1 — Cache Strategy, Redis Readiness & Invalidation Governance
- **Objective:** Cache strategy + Redis readiness with **mandatory invalidation governance**.
- **Why this order:** Cache (with invalidation tests) precedes read-replica usefulness.
- **Out of scope:** caching critical mutable financial/inventory values without invalidation tests.
- **Production safety:** no Redis cache for critical mutable values without invalidation tests.
- **GO:** invalidation rules tested; cache taxonomy + TTL locked; foundation GO preserved.
- **WATCH:** non-critical cache candidates deferred.
- **NO-GO:** critical value cached without invalidation test.

### QUEUE-1 — Queue, Idempotency & Outbox Foundation
- **Objective:** Queue foundation with **mandatory idempotency keys** + **outbox** pattern.
- **Why this order:** Queue/outbox before any async integration.
- **Out of scope:** auto-send WhatsApp; auto-create LabOrder from RME payment; jobs without idempotency/retry policy.
- **Production safety:** no queue job without idempotency key or retry/failure policy.
- **GO:** idempotency convention + outbox tested; retry/failure policy defined.
- **NO-GO:** job without idempotency/retry policy.

### DBPERF-1 — PostgreSQL Index Optimization & Query Plan Audit
- **Objective:** Query-plan audit + additive index optimization.
- **Why this order:** Optimize workload before pooling/tuning.
- **Out of scope:** destructive schema rewrites; partitioning production migration.
- **Production safety:** additive indexes only; no migrate:fresh/db:wipe; large builds in low-traffic window.

### DBPERF-2 — PgBouncer & PostgreSQL Runtime Tuning
- **Objective:** PgBouncer pooling + PostgreSQL tuning with **connection-pool rollback plan**.
- **Why this order:** After index audit; readiness-first with rollback.
- **Production safety:** no PgBouncer production routing without rollback plan.

### RPT-1 — Materialized View + rpt_* Summary Foundation
- **Objective:** `rpt_*` summary tables first, then materialized views.
- **Why this order:** rpt_* summary before materialized view expansion.
- **Out of scope:** destructive replacement of transactional tables; KTP/NIK in summaries.

### STORAGE-1 — Object Storage Readiness
- **Objective:** S3-compatible object storage readiness for uploaded assets.
- **Why this order:** Object storage before stateless/load balancer.
- **Production safety:** no migration without local backup + rollback plan; sensitive docs stay private.

### STATELESS-1 — Stateless App Readiness
- **Objective:** Externalize session/cache/queue/storage so multiple instances run behind an LB.
- **Why this order:** Depends on object storage; must be GO before LB-1.

### LB-1 — Load Balancer Pilot
- **Objective:** Pilot LB in front of stateless instances.
- **Why this order:** Requires stateless readiness; precedes replica production routing.
- **Production safety:** no LB pilot until stateless readiness is GO.

### REPLICA-1 — Read Replica Readiness
- **Objective:** Replica readiness + read/write split **design** — **no production read routing** unless approved.
- **Why this order:** After cache (offload) and LB (routing capability); readiness-only.
- **Production safety:** no read traffic redirected to replica unless explicitly approved.

### PART-1 — Partitioning Design, Not Production Yet
- **Objective:** Partitioning strategy **design only** — no production partition migration.
- **Why this order:** After index audit + rpt_*; production explicitly deferred.
- **Production safety:** no direct production partitioning in PART-1.

### SEARCH-1 — Search Engine & Log Explorer Foundation
- **Objective:** Search + log explorer foundation with PII/secret redaction.
- **Why this order:** After observability hardening + queue (async indexing).
- **Production safety:** no search/log explorer exposing PII/secrets.

### NDA-1 — National Distributed Architecture Plan
- **Objective:** Consolidate readiness into a national distributed architecture blueprint.
- **Why this order:** After all readiness/design sprints, before RC.
- **Production safety:** plan/design only; no production distributed migration.

### RC-1 — Foundation Green Release Candidate Consolidation
- **Objective:** Consolidate all expansion sprints into a green RC with a single validated GO.
- **Why this order:** **Last** — after every planned foundation expansion sprint is GO.
- **GO:** all expansion sprints GO; DQ/DMO/NSF/Combined/roadmap all GO; RC evidence consolidated.
- **NO-GO:** any expansion sprint NO-GO; foundation GO regressed.

---

## 5. Dependency Map

- Feature flag (NSF-9) **before** risky runtime features (CACHE-1 / QUEUE-1 / DBPERF-2).
- Automated smoke (NSF-9) **before** Redis / Queue / PgBouncer.
- Cache (CACHE-1) **before** read replica (REPLICA-1) where useful.
- Queue / outbox (QUEUE-1) **before** async integrations.
- Index audit (DBPERF-1) **before** PgBouncer / tuning (DBPERF-2).
- `rpt_*` summary (RPT-1) **before** materialized view expansion.
- Object storage (STORAGE-1) **before** stateless (STATELESS-1) / load balancer.
- Stateless (STATELESS-1) **before** load balancer (LB-1).
- Load balancer (LB-1) **before** read replica production routing.
- Partitioning design (PART-1) **before** any partition production migration.
- National architecture plan (NDA-1) **before** RC.
- **RC-1 after all planned foundation expansion sprints.**

---

## 6. Guardrails

- No direct production partitioning in PART-1.
- No read traffic redirected to replica in REPLICA-1 unless explicitly approved.
- No Redis cache for critical mutable financial/inventory values without invalidation tests.
- No queue job without idempotency key or retry/failure policy.
- No object storage migration without local backup and rollback plan.
- No load balancer pilot until stateless readiness is GO.
- No PgBouncer production routing without connection pool rollback plan.
- No search/log explorer exposing PII/secrets.
- No feature outside the approved roadmap unless the owner creates a ROADMAP update sprint.

---

## 7. How to Use This Roadmap

Before opening any new foundation sprint:

```bash
php artisan architecture:foundation-roadmap-check          # GO / WATCH / FAIL
php artisan architecture:foundation-roadmap-check --json   # machine-readable
php artisan architecture:foundation-governance-summary     # ROADMAP section + full foundation status
```

- The command **FAILs** if the roadmap config is missing or an unsafe order/guardrail
  violation is detected, **WATCHes** if a planned item misses required detail, and
  returns **GO** when the roadmap is complete, safely ordered, and guardrails intact.
- Follow the sequence starting from `next_recommended_sprint`.
- To change the order or scope, run a dedicated **ROADMAP update sprint** that edits
  `config/foundation_roadmap.php`, updates this doc, and adds an evidence doc — never
  drift outside the locked sequence.
