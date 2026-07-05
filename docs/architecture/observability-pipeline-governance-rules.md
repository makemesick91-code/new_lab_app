# OBS-2 — Observability Pipeline Governance Rules (OBS-R013..R024)

Published by `App\Services\Foundation\ObservabilityPipelineGovernanceService`
into `php artisan architecture:foundation-governance-summary` as the
`observability_pipeline_governance` section. Informational only — not wired
into the blocking `combined` decision, matching OBS-1's posture. This is a
separate service from `ObservabilityGovernanceService` (OBS-1,
OBS-R001..R012) so the existing OBS-1 governance section and its tests are
never touched — the same pattern used for old `cache_governance` vs the
newer `cache_redis_governance`.

| Rule | Title |
|---|---|
| OBS-R013 | Centralized logging/error tracking stays OFF by default |
| OBS-R014 | External log/error export never carries PII or secrets |
| OBS-R015 | DSN/API key/endpoint values never appear outside env/config |
| OBS-R016 | Request id/correlation id (OBS-1) must accompany every exported event |
| OBS-R017 | Synthetic smoke stays non-PII, bounded, and safe |
| OBS-R018 | Production debug mode stays off before external error tracking |
| OBS-R019 | Central logging rollout requires retention, access-control, and redaction policy |
| OBS-R020 | Observability tooling never changes critical transaction flow |
| OBS-R021 | Readiness command stays non-destructive with no default external traffic |
| OBS-R022 | Governance summary shows pipeline readiness without weakening other chains |
| OBS-R023 | Vendor-adding sprints must include a data-processing/privacy note |
| OBS-R024 | Log/error sampling must be configurable to protect the VPS and vendor |

## Non-regression

This sprint does not modify or remove:

- `OBS-R001..OBS-R012` (OBS-1 request id / correlation id foundation)
- `STORAGE-R001..R005`
- `STATELESS-R001..R008`
- `LB-R001..R010`
- `REPLICA-R001..R012`
- `CACHE-R001..R012` (Redis readiness) and the older `cache_governance`
  section
- Release evidence / NSF governance gates

## How this is checked

- Command: `php artisan obs:pipeline-readiness-check` (see
  `docs/architecture/centralized-logging-error-tracking-readiness.md`).
- Governance summary: `php artisan architecture:foundation-governance-summary`
  → `observability_pipeline_governance`.
