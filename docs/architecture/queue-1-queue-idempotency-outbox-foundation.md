# QUEUE-1 — Queue, Idempotency & Outbox Foundation

**Status:** Completed (governance/readiness foundation).
**Branch:** `feature/queue-1-queue-idempotency-outbox-foundation`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**GO tag:** `queue-1-queue-idempotency-outbox-foundation-go`

## 1. Objective

Establish a queue, idempotency, and outbox **governance and readiness** foundation so
that any future async integration is safe, replayable, and auditable before it is
ever wired to a real domain workflow. QUEUE-1 does **not** enable async business
processing — it defines the rules, the safe persistence shape, and the governance
commands that every future queue job/outbox event must satisfy.

## 2. Baseline from CACHE-1

CACHE-1 (merge `5449b76`, GO tag
`cache-1-cache-strategy-redis-readiness-invalidation-governance-go`) is the
prerequisite baseline: feature flags, automated smoke, release evidence/safety, and
cache governance were all GO before this sprint started. QUEUE-1 follows the exact
same governance pattern (`config/*_governance.php` → `*GovernanceService` →
`foundation:*-check` command → release evidence/safety → Foundation Summary → CI →
deploy gate) established by CACHE-1's `CacheGovernanceService`.

## 3. Queue runtime policy — why no long-running worker in QUEUE-1

`config/queue_governance.php` restricts QUEUE-1 to **readiness-only** queue
connections: `sync` and `database`. Redis/SQS/external broker production runtime is
explicitly denied (`queue_runtime.denied_for_queue_1`). No `queue:work` /
`queue:listen` process is started by the deploy script or CI. The rationale:

- A worker consuming real jobs before idempotency/retry policy exists risks
  duplicate processing of critical mutable data (payment, RME, inventory).
- Readiness must be verified (config complete, tables exist, flags safe) before any
  process starts pulling from a queue.
- `foundation:queue-governance-check --include-worker-probe` performs a **one-time,
  non-blocking** connection/table readiness probe — it never starts or leaves
  running a worker process.

Future sprints that want to run a worker must add an explicit worker-readiness
sprint that flips `queue_runtime.long_running_worker_enabled` to `true` with its own
governance review.

## 4. Idempotency key policy

- Every reservation is scoped (`queue_job` / `outbox_event` / `api_request_future`)
  and keyed by `key_hash` = `SHA-256(raw key)`.
- **Raw keys are never stored.** `sys_idempotency_keys.key_hash` is the only
  representation of the caller-supplied key; `raw_key_storage_allowed` is hard-coded
  `false` in governance and asserted by `QueueGovernanceService`.
- Reservation states: `reserved → completed|failed|expired`. A duplicate `reserve()`
  call within an active (`expires_at` in the future) reservation returns the
  existing row instead of reprocessing — this is the conflict-safety guarantee.
- `IdempotencyService::expireOld()` sweeps stale `reserved` rows to `expired`; it
  never touches `completed`/`failed` rows.
- `metadata` is filtered through a PII/secret denylist (`ktp`, `nik`, `password`,
  `token`, `secret`, `email`, `phone`, `address`) before every write.

## 5. Outbox event policy

- `sys_outbox_events` requires `event_version` (schema version) and
  `payload_classification` on every row — enforced by `OutboxService::createSafeEvent()`,
  which rejects unknown/denied classifications and any payload key that looks like
  PII/a secret before the row is ever written.
- `allowed_event_categories` in QUEUE-1: `foundation.internal_test`,
  `release.evidence_summary`, `audit.future_safe_event` — placeholders for
  governance testing only. No domain event (RME/lab/inventory/cashier) is wired in
  this sprint.
- `denied_event_categories`: `patient.identity_pii`, `rme.medical_record_content`,
  `cashier.payment_sensitive_state`, `inventory.ledger_raw_payload`,
  `auth.permission_decision`, `branch_context.runtime_context`.
- Statuses: `pending → processing → dispatched|failed|cancelled`.
- `dispatch_enabled` and `external_dispatch_enabled` are hard-coded `false` in
  `config/queue_governance.php` for QUEUE-1 — `OutboxService::markDispatched()`
  additionally asserts the `foundation.queue.external_dispatch_enabled` feature flag
  before allowing a status transition to `dispatched`, so even a future flag flip
  without governance re-review cannot silently enable dispatch.

## 6. Retry/failure policy

- Outbox: `attempts` increments on every `markProcessing()`; `markFailed()` moves
  the event back to `pending` (for another attempt) until `attempts >= max_attempts`
  (default 5, configurable via `queue_governance.outbox`), after which it moves to
  `failed` with `last_error_code`/`last_error_summary` recorded.
- Idempotency: a `failed` reservation stops future automatic retries of the *same*
  key within its scope until a new key/attempt is issued by the caller.
- Every future queue job **must** define both an idempotency policy and a
  retry/failure policy before it ships — enforced as a permanent rule (see §11).

## 7. Feature flag integration

Four flags in `config/feature_flags.php` (all default `false`, all risk `high`/
`critical` per `FeatureFlagService::RISKY_LEVELS`):

| Flag | Purpose | Risk |
| --- | --- | --- |
| `foundation.queue.outbox_readiness` | Marks outbox pattern as implemented/ready | high |
| `foundation.queue.idempotency_required` | Enforces idempotency keys on future jobs | high |
| `foundation.queue.worker_readiness` | Marks worker as ready to run (does not start it) | high |
| `foundation.queue.external_dispatch_enabled` | Gate for real external outbox dispatch | critical |

## 8. Release evidence / release safety integration

- `config/release_evidence.php`: `ci` and `vps` profiles now require
  `queue-governance-check.json`, `idempotency-audit.json`, `outbox-audit.json`.
- `ReleaseEvidenceService::buildJobs()` captures all three via
  `Artisan::call(..., ['--json' => true])` — same safe-capture pattern as every
  other evidence artifact (forbidden-pattern/regex scan before write).
- `config/release_safety.php` `required_pre_deploy_gates` now includes
  `foundation:queue-governance-check`, `foundation:idempotency-audit`,
  `foundation:outbox-audit`.

## 9. Deploy gate integration

`scripts/deploy-vps.sh` runs the three QUEUE-1 commands (console + `--json` capture
to `storage/release-evidence/latest/`) alongside the existing cache-governance gate,
before `foundation:release-safety-check`. `.github/workflows/foundation-evidence-gates.yml`
(`release_safety_gate` job) runs the same three commands and writes JSON to
`storage/ci-evidence/`. No queue worker is started in either environment.

## 10. GO / WATCH / NO-GO criteria

- **GO:** `queue_governance` config complete; allowed connections readiness-only;
  long-running worker disabled; external dispatch flag off; idempotency raw-key ban
  enforced; outbox denied categories documented and never allowed; feature flags
  registered and safe; Foundation GO preserved.
- **WATCH (non-blocking):** `sys_idempotency_keys`/`sys_outbox_events` not yet
  migrated in an environment, or the worker probe was not run — both are expected
  and harmless while the worker stays disabled by design.
- **NO-GO:** long-running worker enabled; Redis/SQS/broker production runtime
  enabled; external dispatch enabled without governance GO; a raw idempotency key
  stored; PII/secrets in a queue/outbox payload; Foundation GO regressed.

## 11. DaengtisiaMS permanent rules (queue/idempotency/outbox)

1. No async job ships without an idempotency policy.
2. No async job ships without a retry/failure policy.
3. No raw idempotency key is ever stored — only its hash.
4. No PII/secrets in a queue payload or an outbox payload.
5. No external dispatch without both a feature flag and outbox governance GO.
6. No long-running queue worker is started by deploy unless a future worker-readiness
   sprint explicitly approves it.
7. Every outbox event requires a schema version and a payload classification.
8. `foundation:queue-governance-check` (+ idempotency/outbox audits) must remain part
   of CI, release evidence, the deploy gate, and the Foundation Governance Summary.
9. Any future queue/outbox implementation must reference
   `config/queue_governance.php` as the single source of truth.
10. Critical mutable inventory/payment/RME/auth/branch-context async processing
    requires explicit domain-specific approval and dedicated idempotency tests —
    it is never queued "by default" just because the queue foundation exists.

## 12. Next sprint

`DBPERF-1` — PostgreSQL Index Optimization & Query Plan Audit.
