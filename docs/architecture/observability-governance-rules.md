# OBS-1 — Request ID, Correlation ID & Observability Governance Rules

These rules are published by `App\Services\Foundation\ObservabilityGovernanceService`
into `php artisan architecture:foundation-governance-summary` under the
`observability_governance` section. They are informational — like
STORAGE-R001..R005, STATELESS-R001..R008, LB-R001..R010,
REPLICA-R001..R012, and CACHE-R001..R012, they do not gate the combined
GO/WATCH/NO-GO decision on their own.

## Rules

- **OBS-R001** — Every HTTP request must be attached to a safe, bounded
  request id and correlation id so logs can be correlated across a request
  lifecycle.
- **OBS-R002** — Inbound `X-Request-ID`/`X-Correlation-ID` headers are only
  trusted when strict length/character validation is active; otherwise a
  generated id replaces them.
- **OBS-R003** — Log context stays limited to minimal request metadata
  (request id, correlation id, method, path, route name) — never PII,
  secrets, tokens, cookies, session id, or full request payload.
- **OBS-R004** — KTP/NIK, medical notes, private scan paths, consent
  content, and payment-sensitive/audit-sensitive payloads must be masked or
  excluded from all logs.
- **OBS-R005** — The observability readiness command must be
  non-destructive and must never display sensitive log file contents.
- **OBS-R006** — Public health/diagnostic endpoints stay minimal and
  non-sensitive — never exposing env, config internals, or stack traces.
- **OBS-R007** — Multi-node deployment requires verified request id
  propagation across app instances before it happens.
- **OBS-R008** — Queue/job correlation propagation is required before
  significantly expanding asynchronous workflows.
- **OBS-R009** — Centralized logging/APM is a future scale roadmap item,
  not a justification to send PII/secrets to an external vendor without
  review.
- **OBS-R010** — Production `APP_DEBUG` must stay off; this is a warning
  (and a strict-mode failure) if detected otherwise.
- **OBS-R011** — The governance summary must surface observability
  readiness without regressing STORAGE/STATELESS/LB/REPLICA/CACHE/NSF
  governance chains.
- **OBS-R012** — Any sprint adding new logging must state its PII/secret
  masking strategy explicitly in PR evidence.

## How it is wired

`FoundationGovernanceSummaryService::collect()` calls
`ObservabilityGovernanceService::collect()` and exposes it as the
`observability_governance` key, alongside the existing `storage_governance`,
`stateless_governance`, `lb_governance`, `database_replica_governance`, and
`cache_redis_governance` sections — all unchanged by this sprint.

## Verifying nothing else regressed

```bash
php artisan architecture:foundation-governance-summary
php artisan architecture:nsf-governance-check
php artisan lb:readiness-check
php artisan db:replica-readiness-check
php artisan cache:redis-readiness-check
php artisan runtime:stateless-readiness-check
php artisan storage:object-readiness-check
```

All of `storage_governance`, `stateless_governance`, `lb_governance`,
`database_replica_governance`, `cache_redis_governance`, and
`observability_governance` should report `decision: GO` in the default
single VPS pilot configuration.
