# STATELESS-1 — Deploy Portability Governance Rules

These rules are published by `App\Services\Foundation\StatelessGovernanceService`
into `php artisan architecture:foundation-governance-summary` under the
`stateless_governance` section. They are informational — like STORAGE-R001..R005,
they do not gate the combined GO/WATCH/NO-GO decision on their own.

## Rules

- **STATELESS-R001** — New features must not depend on the local ephemeral
  container filesystem for durable user files; use the storage abstraction /
  object storage readiness path (STORAGE-1) instead.
- **STATELESS-R002** — Only `storage/` and `bootstrap/cache/` may be runtime
  writable paths for the application.
- **STATELESS-R003** — Session/cache/queue drivers must be auditable via
  `runtime:stateless-readiness-check` before every deploy.
- **STATELESS-R004** — Scheduler/queue worker jobs intended to run on
  multiple instances must have an idempotency/locking guardrail before
  scale-out.
- **STATELESS-R005** — Deploys must explicitly rebuild config/route/view
  caches and must not depend on stale local state from a previous deploy.
- **STATELESS-R006** — Runtime secrets/config must come from environment/
  config only, never hardcoded in code, docs, or logs.
- **STATELESS-R007** — Local log files are acceptable for a single VPS pilot
  only; a centralized logging roadmap is required before horizontal
  scale-out.
- **STATELESS-R008** — Readiness/smoke commands must be non-destructive and
  safe to run directly on the VPS.

## How it is wired

`FoundationGovernanceSummaryService::collect()` calls
`StatelessGovernanceService::collect()` and exposes it as the
`stateless_governance` key, alongside the existing `storage_governance`
(STORAGE-R001..R005) section — STORAGE governance is unchanged by this
sprint.

## Verifying nothing else regressed

```bash
php artisan architecture:foundation-governance-summary
php artisan architecture:nsf-governance-check
```

Both `storage_governance` and `stateless_governance` sections should report
`decision: GO` in the default (object storage disabled, no unwritable path)
configuration.
