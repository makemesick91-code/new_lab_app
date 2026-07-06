# ENT-6 — Idempotency & Outbox Foundation Governance

**Status:** LOCKED — durable enterprise governance  
**Sprint:** ENT-6 — Idempotency & Outbox Foundation  
**Command:** `php artisan foundation:idempotency-outbox-check`  
**Summary section:** `idempotency_outbox_governance`

ENT-6 hardens the shipped QUEUE-1 idempotency and outbox primitives so future
cross-module and external integrations are replay-safe, privacy-safe, and
observable before any external side effect is enabled.

ENT-6 does not enable external dispatch, start a worker, auto-send WhatsApp, or
auto-create LabOrder from RME payment. It locks the runtime pattern and the
governance check that later implementation sprints must pass.

## Rules

| Rule | Title | Requirement |
| --- | --- | --- |
| ENT6-IO001 | Hashed idempotency keys | Raw idempotency keys must never be stored; services compare only hashes. |
| ENT6-IO002 | Versioned classified outbox | Every outbox event requires schema version and payload classification. |
| ENT6-IO003 | Safe payload only | Outbox payloads must reject PII-shaped or secret-shaped keys; denied classifications remain blocked. |
| ENT6-IO004 | External dispatch disabled | External sends remain disabled until a later approved sprint. |
| ENT6-IO005 | ENT-5 remains mandatory | Future queued dispatchers must pass ENT-5 retry, timeout, backoff, failed-job, and ShouldQueue governance. |
| ENT6-IO006 | Cross-module side effects use outbox | Payment, invoice, inventory, lab-candidate, and notification integrations must use idempotency/outbox before external effects. |
| ENT6-IO007 | RME payment remains non-creating | RME payment must stay idempotent and must not auto-create LabOrder. |
| ENT6-IO008 | Privacy-safe checks | Governance commands report counts/classifications only, never raw keys or row payloads. |

## Runtime Surface

- Existing QUEUE-1 tables remain the persistence source:
  `sys_idempotency_keys` and `sys_outbox_events`.
- Existing QUEUE-1 services remain canonical:
  `App\Services\Foundation\IdempotencyService` and
  `App\Services\Foundation\OutboxService`.
- ENT-6 adds `App\Services\Foundation\IdempotencyOutboxGovernanceService`
  and `foundation:idempotency-outbox-check`.
- ENT-6 summary is additive. It must not replace `queue_governance`,
  `idempotency`, `outbox`, or `queue_retry_governance`.

## GO Criteria

ENT-6 is GO only when:

- `foundation:idempotency-outbox-check --strict` returns GO.
- `foundation:idempotency-audit` and `foundation:outbox-audit` return GO.
- `foundation:queue-governance-check` remains GO.
- `foundation:queue-retry-failed-job-check --strict` remains GO.
- `architecture:foundation-governance-summary` includes
  `idempotency_outbox_governance`.
- `foundation:roadmap-check --strict` reports ENT-7 as the next sprint.

## Future Sprint Rule

Any future sprint adding an external integration, notification dispatcher,
lab-candidate event, report export job, payment side effect, or inventory async
side effect must first pass ENT-5 and ENT-6 governance. External dispatch must
remain off unless that future sprint explicitly approves it with tests, rollback
evidence, and deploy smoke.
