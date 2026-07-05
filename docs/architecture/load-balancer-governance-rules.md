# LB-1 — Load Balancer Pilot Governance Rules

These rules are published by `App\Services\Foundation\LoadBalancerGovernanceService`
into `php artisan architecture:foundation-governance-summary` under the
`lb_governance` section. They are informational — like STORAGE-R001..R005 and
STATELESS-R001..R008, they do not gate the combined GO/WATCH/NO-GO decision
on their own.

## Rules

- **LB-R001** — The application must be able to run safely behind a reverse
  proxy/load balancer only with an explicit trusted proxy IP/CIDR
  configuration, never a blanket trust-all default.
- **LB-R002** — New features must not rely on the raw connecting IP for
  security/rate-limit decisions without accounting for trusted forwarded
  headers.
- **LB-R003** — The load balancer health endpoint must stay minimal,
  unauthenticated, and must never expose PII, secrets, or internal
  configuration.
- **LB-R004** — HTTPS termination at the proxy must be verified before
  enabling secure-cookie enforcement or a global HTTPS redirect.
- **LB-R005** — Real multi-node deployment requires a shared session/cache/
  queue strategy; single-VPS local-disk warnings must not be ignored when
  scaling out.
- **LB-R006** — Load balancer readiness/smoke commands must be
  non-destructive and safe to run directly on the VPS.
- **LB-R007** — Deploying behind a load balancer must preserve an explicit
  cache rebuild step and a clear rollback path.
- **LB-R008** — A request-id/correlation-id observability roadmap is required
  before routing significant multi-node production traffic.
- **LB-R009** — The foundation governance summary must surface load balancer
  readiness without regressing STORAGE/STATELESS/NSF governance chains.
- **LB-R010** — No new public diagnostic endpoint may expose internal
  configuration, secrets, PII, or stack traces.

## How it is wired

`FoundationGovernanceSummaryService::collect()` calls
`LoadBalancerGovernanceService::collect()` and exposes it as the
`lb_governance` key, alongside the existing `storage_governance`
(STORAGE-R001..R005) and `stateless_governance` (STATELESS-R001..R008)
sections — both are unchanged by this sprint.

## Verifying nothing else regressed

```bash
php artisan architecture:foundation-governance-summary
php artisan architecture:nsf-governance-check
php artisan runtime:stateless-readiness-check
php artisan storage:object-readiness-check
```

`storage_governance`, `stateless_governance`, and `lb_governance` should all
report `decision: GO` in the default single VPS pilot configuration (object
storage disabled, no unwritable path, no trusted proxies configured).
