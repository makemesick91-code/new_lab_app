# ENT-8 — Observability & Health Check Pack Governance

Status: **LOCKED (GO)** — durable governance for the enterprise health-check pack.

Builds on OBS-1 (request/correlation id), OBS-2 (logging/error-tracking readiness),
and the LB-1 `/health/lb` endpoint. Adds minimal, non-sensitive liveness/readiness
endpoints plus a read-only readiness command that aggregates DB/cache/queue/storage
health and alerting-threshold readiness — with **no external paging vendor** and
**no PII/secret in output**.

## Runtime surfaces

| Surface | Path / signature | Notes |
| --- | --- | --- |
| Liveness endpoint | `GET /health/live` (`health.live`) | Cheap; confirms app booted. No dependency touched. |
| Readiness endpoint | `GET /health/ready` (`health.ready`) | Aggregates component health; HTTP 200 (ok/degraded) or 503 (down). Body = overall status + per-component `ok\|degraded\|down` only. |
| Readiness service | `App\Support\Health\HealthCheckService` | Read-only probes: database (critical), cache, queue, storage, object storage. |
| Governance command | `php artisan foundation:health-check` (`--json`, `--strict`) | Read-only; verifies posture + ENT-5/ENT-6/ENT-7 GO. |
| Governance service | `App\Services\Foundation\HealthCheckGovernanceService` | Publishes `health_check_governance` into `architecture:foundation-governance-summary`. |
| Config | `config/health_check.php` | Reads `HEALTH_CHECK_*` from the environment example file; safe defaults. |

The endpoints extend the LB-1 minimal-health contract; `/health/lb` stays intact.

## Rules (ENT8-HC001..ENT8-HC010)

- **ENT8-HC001** — Health endpoints are minimal and non-sensitive (status + component state only; never APP_KEY, DB host/credentials, env values, route list, user/branch/patient data, secrets, or stack traces).
- **ENT8-HC002** — Health endpoints are strictly read-only and GET/HEAD only.
- **ENT8-HC003** — Readiness aggregates DB/cache/queue/storage/object-storage health with critical/non-critical classification.
- **ENT8-HC004** — Alerting readiness stays internal; no external paging/alerting vendor without privacy review, external channel flag disabled by default.
- **ENT8-HC005** — No PII/secret in health or alert output; probe exceptions are swallowed, never echoed.
- **ENT8-HC006** — Health pack is read-only, introduces no migration and no runtime driver change.
- **ENT8-HC007** — `foundation:health-check` is read-only and privacy-safe.
- **ENT8-HC008** — ENT-5/ENT-6/ENT-7 governance remain mandatory and surfaced; their strict checks must stay GO.
- **ENT8-HC009** — Health endpoints build on the LB-1 `/health/lb` posture, which stays intact.
- **ENT8-HC010** — New health surfaces require non-sensitivity coverage, endpoint tests, and a passing governance check before shipping.

## Alerting-threshold readiness

Readiness-only thresholds (no external channel wired) for `failed_jobs`,
`pending_jobs`, and `storage_free_percent`. A breached warn threshold => WATCH,
a fail threshold => FAIL in the governance command — never on the public
endpoints. A future MON-1 / paging sprint may consume these thresholds after a
privacy review.

## Deploy note (route-dependent gate ordering)

`foundation:health-check` and `foundation:developer-console-check` inspect the
live route table. `scripts/deploy-vps.sh` now runs `route:clear` + `config:clear`
**before** the governance-gate phase (well before the cache-rebuild block) so the
gates see freshly deployed routes — this closes the ENT-7 stale-route-cache
hiccup for any route-adding sprint.

## Evidence

- Release evidence artifact: `health-check-check.json` (CI + VPS profiles).
- Governance section: `health_check_governance` in the foundation summary.
- Roadmap: ENT-8 `completed`; next recommended sprint = **ENT-9**.
- Tests: `tests/Feature/Architecture/Ent8ObservabilityHealthCheckPackTest.php`,
  `tests/Feature/Foundation/HealthCheckGovernanceCommandTest.php`,
  `tests/Feature/Foundation/HealthCheckEndpointTest.php`.
