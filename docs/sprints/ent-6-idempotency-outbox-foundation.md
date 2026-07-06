# ENT-6 — Idempotency & Outbox Foundation (Sprint Evidence)

Branch: `feature/ent-6-idempotency-outbox-foundation`  
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`  
GO tag after merge: `ent-6-idempotency-outbox-foundation-go`

## Sprint Goal

Lock the QUEUE-1 idempotency and outbox primitives as enterprise foundation
rules, preserve ENT-5 queue retry governance, and make the runtime pattern
auditable through a dedicated ENT-6 command and governance summary section.

## Runtime Implementation Summary

| Artifact | File |
| --- | --- |
| ENT-6 governance rules and readiness aggregation | `app/Services/Foundation/IdempotencyOutboxGovernanceService.php` |
| ENT-6 command | `app/Console/Commands/FoundationIdempotencyOutboxCheckCommand.php` |
| Summary section | `idempotency_outbox_governance` in `FoundationGovernanceSummaryService` |
| Governance config | `config/queue_governance.php` → `ent6_idempotency_outbox` |
| Roadmap lock | `config/foundation_roadmap.php` ENT-6 completed, next ENT-7 |
| Durable doc | `docs/architecture/idempotency-outbox-foundation-governance.md` |

No migration is added; QUEUE-1 already shipped `sys_idempotency_keys` and
`sys_outbox_events`. No route, permission, UI, RME, lab, billing, inventory, or
external integration workflow is changed.

## Commands

```bash
php artisan foundation:idempotency-outbox-check [--json] [--strict|--fail-on-warning]
php artisan foundation:idempotency-audit
php artisan foundation:outbox-audit
php artisan foundation:queue-governance-check
php artisan foundation:queue-retry-failed-job-check --strict
```

The ENT-6 command is read-only and privacy-safe. It reports counts, decisions,
and governance status only.

## GO / NO-GO

GO when ENT-6 command is GO, QUEUE-1 idempotency/outbox audits are GO, ENT-5
queue retry governance remains GO, roadmap next is ENT-7, and foundation
summary includes the ENT-6 section.

NO-GO if external dispatch is enabled, raw idempotency keys are allowed, denied
outbox categories are accepted, ENT-5 governance regresses, or foundation GO
regresses.
